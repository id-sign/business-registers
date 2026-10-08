# Business registers

[![CI](https://github.com/id-sign/business-registers/actions/workflows/ci.yaml/badge.svg)](https://github.com/id-sign/business-registers/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/id-sign/business-registers.svg)](https://packagist.org/packages/id-sign/business-registers)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Typed, stateless PHP client for Czech business registers. One call gives you a company profile that combines four
sources, so you never deal with JSON, SOAP or per-source quirks yourself:

- **ARES** — company identity: name, seat, legal form, VAT ids, status in 16 source registers; bulk lookup and search.
- **VAT register (ADIS)** — VAT payer status, unreliable payers, published bank accounts.
- **VIES** — validity of an EU VAT id, with a consultation number.
- **Insolvency register (ISIR)** — insolvency proceedings of a subject by IČO, ongoing or ended.

Not covered: register extracts (public register, trade register), ARES code lists and the ARES change feed.

## What you can get

**Company identity (ARES)** — by IČO, for up to 100 IČO per request, or by search:

- name, IČO (or the ARES id of a subject without one), legal form code
- VAT id (DIČ) and, for a member of a VAT group, the group's VAT id
- registered seat: full text, street with house numbers, city, district, postal code, county, region, country,
  RÚIAN address point and municipality codes; delivery address lines
- date of establishment, end of existence or registration (`ceasedOn`, ARES `datumZaniku`) and last update
- CZ-NACE activity codes (2025 and 2008 classification)
- public register file number (e.g. `B 1581/MSPH`), tax office code, primary source register
- status in 16 source registers (public register, trade register, VAT, VAT group, insolvency, …): active, historical,
  ended, suspended, …
- search by name, address text, municipality, legal form, CZ-NACE or tax office (up to 1 000 results)

**VAT register (ADIS)** — by DIČ, for up to 100 DIČ per request:

- subject type: VAT payer, VAT group, identified person, unreliable person
- unreliability (ADIS `nespolehlivyPlatce`, also set for unreliable persons and for identified persons the register
  marks unreliable) and the date it was published
- published bank accounts, with publication dates, including accounts no longer published; a check whether a given
  account (domestic form or Czech IBAN) is published
- name, address and tax office of the subject
- the complete list of unreliable payers

**EU VAT id (VIES)** — for any EU member state:

- whether the VAT id is valid
- name and address, where the member state discloses them
- a consultation number as evidence that the check was made, when you pass your own VAT id (one of the elements of
  evidence for the exemption of intra-community supplies, not a proof on its own)
- whether a declared name, street, postal code, city and company type match the register, where the member state
  compares them

**Insolvency register (ISIR)** — by IČO, one request per company:

- every proceeding the register currently lists for the subject, ongoing and ended: case reference, court, state,
  date of the decision on insolvency, end date, link to the case file
- whether a proceeding is ongoing, a filed petition included
- the debtor as the register publishes it: name, address, birth number and birth date of natural persons

**Company profile** — one call that combines the sources above, reports per source whether it answered, and derives risk
flags: ceased, in liquidation, insolvency record, ongoing insolvency proceeding, unreliable VAT payer, unreliable person,
VAT registration ended, VAT payer without a published bank account, VAT id invalid in VIES. For a list of IČO, one call
builds all profiles with one ARES and one ADIS request per 100 companies.

**Validation without a request** — IČO (including the check digit) and DIČ (format; strict digits-only check for CZ)
are normalised and validated locally before any register is asked. An IČO that ARES itself returns is taken as the
register holds it, even when it fails the check digit (see § What the data means).

## Requirements

- PHP 8.4+
- `ext-dom`
- any implementation of `symfony/http-client-contracts` (for example `symfony/http-client`)

## Installation

```bash
composer require id-sign/business-registers symfony/http-client
```

### Symfony

The library is a plain PHP library, not a bundle. Register the services in `config/services.yaml`; the HTTP client is
autowired:

```yaml
IdSign\BusinessRegisters\Ares\AresClient: ~
IdSign\BusinessRegisters\Ares\CompanyDirectory: '@IdSign\BusinessRegisters\Ares\AresClient'
IdSign\BusinessRegisters\Adis\VatRegisterClient: ~
IdSign\BusinessRegisters\Adis\VatRegister: '@IdSign\BusinessRegisters\Adis\VatRegisterClient'
IdSign\BusinessRegisters\Vies\ViesClient: ~
IdSign\BusinessRegisters\Vies\Vies: '@IdSign\BusinessRegisters\Vies\ViesClient'
IdSign\BusinessRegisters\Isir\InsolvencyClient: ~
IdSign\BusinessRegisters\Isir\InsolvencyRegister: '@IdSign\BusinessRegisters\Isir\InsolvencyClient'
IdSign\BusinessRegisters\CompanyLookup: ~
```

### Without a framework

```php
use IdSign\BusinessRegisters\Adis\VatRegisterClient;
use IdSign\BusinessRegisters\Ares\AresClient;
use IdSign\BusinessRegisters\CompanyLookup;
use IdSign\BusinessRegisters\Isir\InsolvencyClient;
use IdSign\BusinessRegisters\Vies\ViesClient;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();

$ares = new AresClient($http);
$vatRegister = new VatRegisterClient($http);
$vies = new ViesClient($http);
$isir = new InsolvencyClient($http);
$lookup = new CompanyLookup($ares, $vatRegister, $vies, $isir);
```

Each client takes `$endpoint` (default: the production service) and `$timeout` in seconds (default `10.0`) as optional
constructor arguments. The clients keep no state, so one instance can serve the whole process (FrankenPHP workers
included).

`AresClient` and `VatRegisterClient` also take `$maxConcurrency`: how many batches of one `findMany()` call are sent at
the same time, 1 to 4 (anything else is an `\InvalidArgumentException`). Defaults: ARES `2`, ADIS `4` — the ADIS
operator allows at most 4 parallel requests per source IP address. Lower it when several workers share one IP address,
e.g. `new VatRegisterClient($http, maxConcurrency: 1)`, or in Symfony `services.yaml`
`IdSign\BusinessRegisters\Adis\VatRegisterClient: { arguments: { $maxConcurrency: 1 } }`. `$timeout` applies to each
request and includes the time the request waits queued at the source, so with a shared IP address or a tight timeout
lower `$maxConcurrency` too. The batches really run in parallel only with an asynchronous client such as
`CurlHttpClient`; `NativeHttpClient` (the fallback of `HttpClient::create()` without `ext-curl`) sends them one after
another. The library does not count requests per minute, hour or day.

## Usage

### Company profile (facade)

`CompanyLookup::byCompanyId()` asks ARES and the sections you request. Without sections it makes exactly one request.

```php
use IdSign\BusinessRegisters\RiskFlag;
use IdSign\BusinessRegisters\Section;
use IdSign\BusinessRegisters\SectionStatus;

$profile = $lookup->byCompanyId('452 746 49', Section::Vat, Section::Vies, Section::Insolvency);

if (null === $profile) {
    // ARES does not hold the subject (usually also true for deleted subjects)
    return;
}

echo $profile->company->name;                    // "ČEZ, a. s."
$profile->isComplete();                          // false if a requested section is Unavailable or Rejected, or Insolvency is NotApplicable
$profile->status(Section::Vat);                  // SectionStatus::Ok
$profile->vat?->type;                            // SubjectType::VatPayer
$profile->vies?->valid;                          // true
$profile->insolvencies?->hasOngoing();           // false

$profile->flags();                               // list<RiskFlag>, e.g. [RiskFlag::UnreliableVatPayer]
$profile->hasFlag(RiskFlag::Ceased);             // false

if (SectionStatus::Unavailable === $profile->status(Section::Vies)) {
    $profile->error(Section::Vies);              // the ServiceUnavailable or InvalidResponse that caused it
}
```

The sections are `Section::Vat` (VAT register, `$profile->vat`), `Section::Vies` (`$profile->vies`) and
`Section::Insolvency` (insolvency register ISIR, `$profile->insolvencies`). Vat and Vies are looked up under the
company's DIČ (`Company::vatLookupId()`), Insolvency under its IČO, so a company without a DIČ still gets an
Insolvency answer. Insolvency is `Ok` also when ISIR lists nothing (an empty collection); it is never `NotFound`.

The IČO may be given as a string in any spacing or a `CompanyId`. A section whose source is down does not fail the
lookup: its status becomes `Unavailable` and the other sections are still filled. A section whose source rejects the
request (an `InvalidInput` from ADIS, VIES or ISIR, e.g. VIES `INVALID_REQUESTER_INFO` for a wrong requester) becomes
`Rejected` the same way, with the exception and its `errorCode` in `error()`; the answer is **unknown** and asking again
will not help until the input or configuration is fixed. Only ARES errors are thrown, because without ARES there is no
profile.

#### Five meanings of `null`

`$profile->vat`, `$profile->vies` and `$profile->insolvencies` are `null` in five different situations; `status()`
tells them apart:

| `status($section)` | Meaning                                                                                                      |
|--------------------|--------------------------------------------------------------------------------------------------------------|
| `NotRequested`     | the section was not passed to `byCompanyId()` / `byCompanyIds()`                                             |
| `NotFound`         | the source answered that it does not hold the subject                                                        |
| `NotApplicable`    | the subject has no id the section is asked under (no DIČ for Vat and Vies, no IČO for Insolvency)            |
| `Unavailable`      | the source could not answer — the answer is **unknown**; `error()` holds the exception                       |
| `Rejected`         | the source rejected the request — **unknown**; fix the input or configuration; `error()` holds the exception |

`SectionStatus` is string-backed (`not_requested`, `ok`, `not_found`, `not_applicable`, `unavailable`, `rejected`) and
`json_encode($profile)` works. The status values are a stable contract. A stored exception in `errors` encodes its
public properties only — `source` (`ServiceUnavailable`, `InvalidResponse`) and `errorCode` (`ServiceUnavailable`,
`InvalidInput`), e.g. `{"source":"adis","errorCode":null}`; the message and the trace are not part of the JSON.

#### Flags

`flags()` returns the raised `RiskFlag` cases in enum order. Flags are computed from ARES and only from sections whose
status is `Ok`. An absent flag means "clean" **only when `isComplete()` is true and the section the flag comes from was
requested**.

| Flag                     | Raised when                                                                                                                                                                                                                          | Needs           |
|--------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------|
| `Ceased`                 | ARES `datumZaniku` — the day the subject ceased to exist (zánik, NOZ § 185: deletion from the register) or its registration ended — is today or in the past (midnight Europe/Prague). Not the dissolution (zrušení): a company in liquidation is still active, see `InLiquidation`. A future date raises nothing (it stays in `$company->ceasedOn`) | —               |
| `InLiquidation`          | the name contains the standalone phrase "v likvidaci" anywhere: at the end, before the legal form (`… v likvidaci, s.r.o.`), between dashes, slashes or parentheses; case-insensitive. Mandatory suffix for a legal person in liquidation (NOZ § 187 odst. 2); never raised for natural persons, foreign persons and branches, or a dissolution without liquidation | —               |
| `InsolvencyRecord`       | ARES lists the subject in the insolvency register — a record, possibly a closed one                                                                                                                                                  | —               |
| `Insolvency`             | ISIR lists at least one ongoing proceeding (`InsolvencyProceeding::isOngoing()`), a filed petition included; a closed proceeding raises only `InsolvencyRecord`                                                                     | `Section::Insolvency` |
| `UnreliableVatPayer`     | the VAT register marks a VAT payer or VAT group as unreliable (nespolehlivyPlatce); implies `isVatPayer() === true`                                                                                                                  | `Section::Vat`  |
| `UnreliablePerson`       | the subject is an unreliable person under §106aa of the VAT Act: the register keeps it as an unreliable person, or marks a non-payer identified person as unreliable                                                                 | `Section::Vat`  |
| `VatRegistrationEnded`   | ARES VAT registration is `Ended` or `Historical`, the subject is not in an active VAT group, and the VAT register (when `Section::Vat` is `Ok`) does not say the subject is a VAT payer                                          | —               |
| `NoPublishedBankAccount` | a VAT payer or VAT group without any active published bank account                                                                                                                                                                   | `Section::Vat`  |
| `ViesInvalid`            | VIES answered that the VAT id is not valid                                                                                                                                                                                           | `Section::Vies` |

#### Shortcuts: `isVatPayer()`, `hasPublishedAccount()` and `isInInsolvency()`

```php
$profile->isVatPayer();                          // ?bool
$profile->hasPublishedAccount('27-5868650297/0100'); // ?bool
```

Both are tri-state and read `Section::Vat`:

| `status(Section::Vat)`      | Result                                                                                           |
|-----------------------------|--------------------------------------------------------------------------------------------------|
| `Ok`                        | from the VAT register: `true` / `false`                                                          |
| `NotFound`, `NotApplicable` | `false` — definitive                                                                             |
| `Unavailable`               | `null` — **unknown**, ask again later; never read it as "not a payer" or "account not published" |
| `Rejected`                  | `null` — **unknown**, fix the input or configuration; asking again will not help                 |
| `NotRequested`              | throws `\LogicException` — you forgot to pass `Section::Vat`                                     |

`isInInsolvency()` answers "is the subject in insolvency now?" from `Section::Insolvency`, tri-state as well, except
that `NotApplicable` is unknown:

```php
$profile->isInInsolvency();                      // ?bool
```

| `status(Section::Insolvency)` | Result                                                                                     |
|-------------------------------|--------------------------------------------------------------------------------------------|
| `Ok`                          | `true` when ISIR lists an ongoing proceeding (a filed petition included), else `false`     |
| `NotApplicable`               | `null` — **unknown**; the subject has no IČO, and ISIR can list a person under the birth number alone; `isComplete()` is `false` |
| `Unavailable`                 | `null` — **unknown**, ask again later; never read it as "not in insolvency"                |
| `Rejected`                    | `null` — **unknown**, fix the input or configuration                                      |
| `NotRequested`                | throws `\LogicException` — you forgot to pass `Section::Insolvency`                        |

Requesting a section whose client was not passed to the `CompanyLookup` constructor also throws `\LogicException`,
before any request is made.

#### VAT group members

A member of a VAT group has a group DIČ (`groupVatId`); its own `vatId` in ARES is either `null` (Komerční banka,
`45317054`: group DIČ `CZ699001182`) or its former own DIČ (`21985685`, `05666112`; ARES `Vat = Ended` or
`Nonexistent`). ADIS answers for the group under the group DIČ; in rare cases it still answers the member's own DIČ as a
VAT payer as well. The facade asks the group only: it looks a member up in ADIS and VIES under the group DIČ
(`Company::vatLookupId()`, `groupVatId ?? vatId`), so `$profile->vat` and `$profile->vies` describe the group. Do the
same when you call the clients yourself: send `$company->vatLookupId()`, never `$company->vatId` and never a DIČ derived
from the IČO.

#### VIES requester

Pass your own VAT id as the fifth constructor argument `viesRequester` to get a consultation number in
`$profile->vies`:

```php
use IdSign\BusinessRegisters\VatId;

$lookup = new CompanyLookup($ares, $vatRegister, $vies, $isir, viesRequester: VatId::parse('CZ12345678'));
```

#### Many companies at once

`CompanyLookup::byCompanyIds()` builds the profiles of a list of IČO with as few requests as possible: ARES is asked
with one `findMany()`, section `Vat` with one ADIS `findMany()` over the companies' `vatLookupId()` values (a VAT group
is asked once for all its members). 100 IČO with `Section::Vat` are 1 ARES and 1 ADIS request (both clients send
batches of 100). VIES has no bulk call: `Section::Vies` makes one `check()` per distinct lookup DIČ (a VAT group is
checked once for all its members), one after another, so 100 companies with `Vies` take minutes. ISIR has no bulk
call either: `Section::Insolvency` makes one `find()` per company, one after another in ARES response order —
typically 0.10–0.25 s each (100 companies ≈ 10–25 s), at worst the ISIR client's `$timeout` per company when ISIR is
down.

```php
use IdSign\BusinessRegisters\CompanyId;

// ids your database stored from $company->id: re-hydrate with fromRegister(); parse() is for user input only
$requested = array_map(CompanyId::fromRegister(...), $icosFromDatabase);   // list<CompanyId>
$profiles = $lookup->byCompanyIds($requested, Section::Vat);

foreach ($profiles as $profile) {                // values only, in ARES response order: each batch of 100 ascending
    $profile->status(Section::Vat);              // by IČO, batches in request order — no global order
}
$profiles->get('45274649')?->isVatPayer();       // ids in any form, as in Companies
foreach ($profiles->missing($requested) as $id) {   // list<CompanyId> — not held by ARES
}
```

The statuses mean the same as for one company. If the ADIS call fails (outage, invalid response), `Vat` is
`Unavailable` for every profile asked in that call; a company whose lookup DIČ is not Czech is not sent to ADIS and
gets `Rejected`. A VIES outage or rejection affects only the companies with that lookup DIČ, which share the
stored exception. Once VIES rejects the requester (`INVALID_REQUESTER_INFO`), no further VIES request is sent in that
call: every company whose lookup DIČ was not yet checked gets `Rejected` with that same exception instance, whose
message names the DIČ of the request that was rejected; a company whose lookup DIČ was already checked keeps that
answer. The next call asks VIES again. An ISIR outage, invalid response or rejection affects only that company; the
following companies are still asked. Every IČO and the section clients are checked before
the first request; ARES errors are thrown.

#### Change tracking

To detect changes between runs, store snapshots of the DTOs (`$profile->company`, `$profile->vat`, `$profile->vies`,
`$profile->insolvencies`), not of the whole profile. `serialize($profile)` can fail when a stored exception carries a stack trace with
non-serialisable arguments (`zend.exception_ignore_args=0`, the `php.ini-development` default).

### Storing identifiers

IČO is a **string**, never an `int`: it has leading zeros (`00064581`), and PHP turns digit-only array keys into
integers. Store it in a `CHAR(8)` / `VARCHAR(8)` column, edit it with a Symfony `TextType`, keep it in string DTO
properties. A single id parameter is typed `CompanyId|string` (`find()`, `get()`, `has()`, `byCompanyId()`): an
`int` is a `TypeError` under `strict_types` and is silently coerced to a string without it (`$companies->has(64581)`
is then `true`). The elements of an id list (`findMany()`, `byCompanyIds()`, `missing()`) are checked by the library:
an element that is neither a `CompanyId` nor a string is an `InvalidInput`. A legacy `INT` column must be cast to a
string at your boundary (better: migrate the column). `CompanyId::parse()` restores the leading zeros. DIČ is a
string for the same reason. The ISIR birth number (`InsolvencyProceeding::$birthNumber`) is a string exactly as the
register sent it, never normalised or validated; it is personal data, store it only if you have a reason to.

```php
use IdSign\BusinessRegisters\CompanyId;

CompanyId::parse(' 452 746 49 ');    // 45274649
CompanyId::parse('64581');           // 00064581
CompanyId::tryParse('12345678');     // null (check digit)
(string) CompanyId::parse('64581');  // "00064581"

CompanyId::fromRegister('123562');   // 00123562 — format only, for an IČO ARES uses although it fails the check digit
CompanyId::fromRegister('123562')->hasValidCheckDigit();   // false
```

A string id is always validated strictly, including the check digit, in `find()`, `findMany()`, the collections and
`CompanyLookup::byCompanyId()` / `byCompanyIds()`; a `CompanyId` object is taken as it is. The two constructors
belong to two origins: `CompanyId::parse()` and plain strings are for user input; an IČO that came from ARES — stored
from `$company->id` or from any other response — is re-hydrated with `CompanyId::fromRegister()` when you read it
back. ARES holds active subjects whose IČO fails the check digit (`00123562`, `29340042`), and you cannot tell in
advance which stored string is one of them: a single such string makes the whole `findMany()` / `byCompanyIds()`
call throw `InvalidInput` before any request, and `missing()` / `get()` of the result throw for it too.

### Addresses

ARES seats, ADIS addresses and ISIR debtor addresses share `Address` with one meaning per field: `street` is the street
name (or the part of the municipality where there is none) with the numbers, "Duhová 1444/2"; `streetName`,
`houseNumber` and `orientationNumber` are its parts; `postalCode` is five digits without a space. ADIS and ISIR fill
the parts only when their value splits unambiguously, otherwise the source value stays in `street`. Names stay as the
source writes them: ADIS uses upper case, ADIS and ISIR can put a city district into `city` ("PRAHA 4", "Praha 5"),
where ARES has `city` "Praha" and `cityDistrict`, and ISIR `ulice` (`streetName`) is the village name where there are
no streets ("Libotenice"), where ARES has `district`. VIES returns free text (`ViesResult::$address`).

### ARES

```php
use IdSign\BusinessRegisters\Ares\CompanySearch;

$company = $ares->find('45274649');      // ?Company; null when ARES does not hold the subject
$company->name;
$company->seat?->street;                 // "Duhová 1444/2"
$company->seat?->postalCodeFormatted();  // "140 00"
$company->vatId;                         // ?VatId — a filled value does not mean "VAT payer"
$company->vatLookupId();                 // ?VatId — the one to send to ADIS and VIES
$company->taxOfficeCode;                 // ?string — ARES financniUrad: workplace (e.g. "293") or "013" Specialised Tax Office; code list FinancniUrad
$company->isNaturalPerson();             // legal form 100, 101–108, 424, 425
$company->hasCeased();                   // ARES datumZaniku (end of existence or registration) not after today (Europe/Prague); pass a date to ask for another calendar day
$company->registrations->active();       // list<AresRegister>
```

`findMany()` removes duplicates, sends the ids in batches of 100 (up to `$maxConcurrency` batches at a time) and
returns a `Companies` collection. ARES answers each batch in ascending IČO; with more than 100 ids the batches are
appended in request order, so the collection as a whole is not sorted. Ids that ARES does not hold are absent. If a
batch fails, the first failure in sending order is thrown, the other batches sent at the same time are cancelled and
no further batch is sent; ADIS `findMany()` works the same way.

```php
// ids your database stored from $company->id: re-hydrate with fromRegister(); parse() is for user input only
$requested = array_map(CompanyId::fromRegister(...), $icosFromDatabase);   // list<CompanyId>
$companies = $ares->findMany($requested);

$companies->has('64581');                // ids in any form: "00064581", "452 746 49", a CompanyId
$companies->get('00064581')?->name;
count($companies);

foreach ($companies as $company) {       // values only, in ARES response order: each batch of 100 ascending by IČO,
}                                        // batches in request order — no global order

foreach ($companies->missing($requested) as $id) {   // list<CompanyId> — not found in ARES (e.g. deleted)
}
```

The result is a collection and not an array keyed by IČO because PHP would turn the keys `45274649` into integers and a
lookup with a non-normalised key would silently report an existing company as missing. No public method of this library
returns an array keyed by IČO or DIČ. An invalid id passed to `has()`, `get()` or `missing()` is an `InvalidInput`, not
"missing".

`search()` returns one page and the total number of matches:

```php
$result = $ares->search(new CompanySearch(name: 'Komerční banka', limit: 20, offset: 0));

$result->total;                          // all matches, not only this page
foreach ($result->companies as $company) {   // list<Company>
    $company->id;                        // ?CompanyId — null for a subject without IČO
    $company->aresId;                    // company id or "ARES_########" for such a subject
}
```

Criteria: `name`, `address`, `municipalityCode`, `legalFormCodes`, `naceCodes`, `taxOfficeCodes` (ARES `financniUrad`: a
workplace code or `013`; regional codes 451–464 match nothing, see "What the data means"), plus `limit` (1–1 000,
default 20), `offset` (0 or greater) and `orderBy`. At least one criterion must be filled; a blank string is not a
criterion. An invalid query throws `InvalidInput` before any request.

### VAT register (ADIS)

Only Czech DIČ are accepted; a string without a country code is read as `CZ`, a non-Czech one is an `InvalidInput`.

```php
$subject = $vatRegister->find('CZ45274649');     // ?VatSubject; null when the register does not hold it
$subject->type;                                  // SubjectType::VatPayer
$subject->isVatPayer();                          // true for a VAT payer and a VAT group
$subject->unreliable;                            // bool
$subject->taxOfficeCode;                         // ?string — ADIS cisloFu: regional office 451–464 (e.g. "461") or "013"; code list FinancniUrad
$subject->activeBankAccounts();                  // list<BankAccount>
(string) $subject->activeBankAccounts()[0];      // e.g. "71504011/0100"
$subject->hasPublishedAccount('27-5868650297/0100');
$subject->hasPublishedAccount('CZ6501000000275868650297');
```

`hasPublishedAccount()` compares against **active** accounts only. Czech accounts match in any domestic form (with or
without prefix and leading zeros, spaces and any dash tolerated) or as a Czech IBAN (check digits are not verified);
the bank code must have four digits. A non-standard account matches literally (case-insensitive). Anything not
recognisable is `false`, never an exception.

`findMany()` returns a `VatSubjects` collection that works like `Companies` (`get`, `has`, `missing`, `all`, `count`,
values-only iteration; ids in any form, read as Czech):

```php
$subjects = $vatRegister->findMany(['CZ45274649', '699001182']);

$subjects->get('45274649')?->type;
foreach ($subjects->missing(['CZ45274649', 'CZ11111111']) as $vatId) {   // list<VatId>
    echo $vatId;                                                          // "CZ11111111"
}
```

`unreliablePayers()` returns the whole list of unreliable VAT payers as `list<UnreliablePayer>` (`vatId`, `since`,
`taxOfficeCode` — the ADIS office code, see "What the data means"). The list has about 4 300 entries and 500 kB;
consider a longer `$timeout` than the default 10 s. The list holds the unreliable payers and only some unreliable
persons, and not reliably the identified persons the register marks unreliable; it does not carry the subject type. To
screen for `UnreliablePerson`, use `find()` / `findMany()` (`VatSubject::$type`, `$unreliable`) or the facade, not the
list. `VatSubject::$unreliable` is likewise `true` for an unreliable person and for an identified person the register
marks unreliable (`14227843`); such a subject raises `UnreliablePerson`, never `UnreliableVatPayer`.

### VIES

`check()` needs the country prefix (there is no default country); `GR` is normalised to `EL`.

```php
$result = $vies->check('CZ45274649', requester: 'CZ12345678');

$result->valid;                  // bool — an invalid VAT id is a result, not an exception
$result->name;                   // ?string — null when the member state does not disclose it (Germany)
$result->address;                // ?string
$result->consultationNumber;     // ?string — issued only when a requester was given
$result->checkedAt;              // DateTimeImmutable, UTC
```

An empty-string requester is an `InvalidInput` and sends no request, so a misconfiguration fails loudly instead of
silently dropping the consultation number. Map an empty configuration value to `null` yourself; in Symfony:

```yaml
parameters:
    vies_requester: '%env(default::VIES_REQUESTER)%'   # null when the variable is unset or empty
```

#### Trader matching

Pass `TraderDetails` and VIES compares each given field with the register of the member state; null and blank
(whitespace-only) fields are not sent, the others are sent unchanged:

```php
use IdSign\BusinessRegisters\Vies\MatchResult;
use IdSign\BusinessRegisters\Vies\TraderDetails;

$result = $vies->check('ESA15075062', trader: new TraderDetails(
    name: 'Industria de Diseño Textil, S.A.',
    city: 'Arteixo',
));

$result->nameMatch;              // ?MatchResult — Valid, Invalid or NotProcessed
$result->streetMatch;            // ?MatchResult — likewise postalCodeMatch, cityMatch, companyTypeMatch
```

A match property is `null` when VIES did not return the field. Without `TraderDetails` VIES may still answer
`NotProcessed`, or omit the field (`null`). Many member states, CZ and IE among them, always answer `NotProcessed`.
Spain does not disclose name and address (`$result->name === null`), so matching is the official way to verify a
Spanish trader.

### Insolvency register (ISIR)

`find()` takes an IČO (a string is checked strictly, including the check digit, before any request) and returns every
proceeding the register currently lists for it, ended ones included:

```php
use IdSign\BusinessRegisters\Isir\InsolvencyClient;

$isir = new InsolvencyClient($http);
$proceedings = $isir->find('25083325');          // InsolvencyProceedings; empty when the subject is not listed

$proceedings->hasOngoing();                      // true
$proceedings->ongoing();                         // list<InsolvencyProceeding>
$proceedings->synchronisedAt;                    // ?DateTimeImmutable — how fresh the register data are
foreach ($proceedings as $proceeding) {          // also $proceedings->proceedings, count($proceedings)
    $proceeding->reference();                    // "95 INS 12575/2022"
    $proceeding->isOngoing();                    // true
    $proceeding->stateCode;                      // ?string, e.g. "KONKURS"
    $proceeding->insolvencyDeclaredOn;           // ?DateTimeImmutable — decision on insolvency took legal force
    $proceeding->endedOn;                        // ?DateTimeImmutable — end of the proceeding took legal force
}
```

- `isOngoing()` is `true` unless the proceeding has an end date (`endedOn`) or one of the ended states `ODSKRTNUTA`,
  `PRAVOMOCNA`, `VYRIZENA`, `MYLNÝ ZÁP.`; a missing or unknown state counts as ongoing (a false alarm is safer than a
  missed insolvency). A filed petition (`NEVYRIZENA`) is ongoing.
- `stateCode` is the register's raw value, never an enum. Observed: `NEVYRIZENA`, `ÚPADEK`, `KONKURS`, `REORGANIZ`,
  `ODDLUŽENÍ`, `PRAVOMOCNA`, `ODSKRTNUTA`, `VYRIZENA`. `KONKURS` or `ÚPADEK` does not mean the proceeding is still
  running (České aerolinie `45795908`: `ÚPADEK` with an end date).
- An empty collection means "not on the list of debtors now", not "never insolvent": the court removes a debtor
  5 years after the end of the proceeding took legal force, sooner in some cases (Act No. 182/2006 Sb. § 425).
- A row is one debtor of a proceeding. Rows sharing a `reference()` can be co-debtors (with the co-debtor's personal
  data) or the same debtor listed twice (two addresses), so `count()` can count a proceeding twice.
  `otherDebtorInProceeding` is the register's raw `dalsiDluznikVRizeni`; its meaning is undocumented and observed to
  vary by query, so do not read it as "has co-debtors". A natural person is found by IČO only if the court recorded it.
- `insolvencyDeclaredOn` can be `null` although insolvency was declared (older proceedings); `stateCode` decides.
  `addressKind` is the raw `druhAdresy`, observed `SÍDLO FY`, `SÍDLO ORG.`, `TRVALÁ`.
- The ministry runs the successor eISIR in verification operation and has announced a change of the web services
  without a date. The client sits behind `InsolvencyRegister`.
- Every row carries what the register publishes (§ 420), including the birth number (`birthNumber`, as received),
  birth date, name and address of natural persons. Your application is the controller of that personal data.
- `synchronisedAt` is the register's own freshness hint, read as Prague local time (verified in summer time only); the
  register omits it for an empty result, and an unreadable value is `null`. It is never part of a verdict.
- The service listens on port 8443 (`https://isir.justice.cz:8443/...`); allow it in your egress firewall. One `find()`
  is one request, 0.1–0.25 s observed.
- `find()` returns at most 100 proceedings (a proceeding can have several rows). A subject with more than 100 listed
  proceedings throws `InvalidResponse` rather than returning an incomplete list that might leave out an ongoing one.

## Error handling

Every exception implements `IdSign\BusinessRegisters\Exception\ExceptionInterface`.

| Exception                                            | Meaning                                                                 | What to do                                                               |
|------------------------------------------------------|-------------------------------------------------------------------------|--------------------------------------------------------------------------|
| `InvalidInput` (extends `\InvalidArgumentException`) | the input is invalid or the source rejected it                          | fix the input or ask the user; repeating will not help                   |
| `ServiceUnavailable`                                 | the source could not answer: transport error, timeout, outage, overload | try again later; it is **never** a business answer such as "not a payer" |
| `InvalidResponse`                                    | the source answered something the library cannot read                   | an error to investigate; report it                                       |

`InvalidInput` has `?string $errorCode`; `ServiceUnavailable` has `Source $source` and `?string $errorCode`;
`InvalidResponse` has `Source $source`. `Source` is `Ares`, `Adis`, `Vies` or `Isir`. `CompanyLookup::byCompanyId()`
throws only ARES errors; an exception from a section is recorded in the profile (`Unavailable` or `Rejected`, see
[Five meanings of `null`](#five-meanings-of-null)).

```php
use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Exception\InvalidResponse;
use IdSign\BusinessRegisters\Exception\ServiceUnavailable;

try {
    $profile = $lookup->byCompanyId($input, Section::Vat);
} catch (InvalidInput $e) {
    // show a validation error; $e->errorCode may hold the source's code
} catch (ServiceUnavailable $e) {
    // retry later; $e->source says which register
} catch (InvalidResponse $e) {
    // log and report; $e->source, the message names the key path
}
```

Rules for messages:

- When `errorCode` is set, the exception appends ` (error code X)` to the message (for example
  `… (error code VSTUP_NEVALIDNI_FORMAT_ICO)`, `ADIS is in scheduled maintenance (error code 2)`). Log normalisers such
  as Monolog's do not log custom exception properties, so the code has to be in the message. The suffix is appended
  only when the code looks like a token (`[A-Za-z0-9_.:-]`, 1–64 characters); `$e->errorCode` always holds the raw value
  and should be treated as untrusted text. Branch on `$e->errorCode` and `$e->source`, never parse the message.
- Messages never contain response text (ARES `popis`, ADIS `statusText`, ISIR `textChyby` / `popisChyby`, SOAP
  `faultstring`, VIES `message`) or record data. They may contain the id you passed in, the HTTP status and, for a
  transport failure, the error text of the HTTP client.
- `InvalidResponse` messages contain only the source label, the key path and the expected type, for example
  `ARES: expected string at sidlo.nazevObce`. JSON paths are jq-style with 0-based indices (`zaznamy[0].ico`); XML paths
  are XPath with 1-based indices
  (`s:Body/r:StatusNespolehlivySubjektRozsirenyResponse/r:statusSubjektu[3]/@typSubjektu`).

Per source:

| Source | Situation                                                                                                                           | Exception                                                                                                                                        |
|--------|-------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|
| ARES   | 404 "not found"                                                                                                                     | `find()` returns `null`; `findMany()` leaves the id out                                                                                          |
| ARES   | HTTP 400                                                                                                                            | `InvalidInput`, `errorCode` = ARES `subKod`; more than 1 000 search results is `VYSTUP_PRILIS_MNOHO_VYSLEDKU` — ask the user to refine the query |
| ARES   | other status, transport error, timeout                                                                                              | `ServiceUnavailable`                                                                                                                             |
| ADIS   | status code 1, unknown code                                                                                                         | `InvalidResponse`                                                                                                                                |
| ADIS   | status code 2 (nightly maintenance), 3, SOAP Fault, HTTP ≠ 200, transport error, timeout                                            | `ServiceUnavailable`, `errorCode` = status code 2 or 3, or the SOAP `faultcode` (also with HTTP ≠ 200); otherwise `null`                         |
| ISIR   | empty result (`WS2`)                                                                                                                | `find()` returns an empty collection                                                                                                             |
| ISIR   | `WS4` (data not current), `SQL1`, `SERVER1`, SOAP Fault, HTTP ≠ 200, transport error, timeout                                       | `ServiceUnavailable`, `errorCode` = the ISIR code or the SOAP `faultcode` (also with HTTP ≠ 200); otherwise `null`                               |
| ISIR   | `WS1`, `WS3`, unknown code, truncated answer (`pocetVysledku` above the rows returned), more than 100 proceedings                   | `InvalidResponse`                                                                                                                                |
| VIES   | `INVALID_INPUT`, `INVALID_REQUESTER_INFO`, HTTP 400                                                                                 | `InvalidInput`, `errorCode` = VIES code                                                                                                          |
| VIES   | every other code (`MS_UNAVAILABLE`, `TIMEOUT`, `*_MAX_CONCURRENT_REQ*`, `VAT_BLOCKED`, `IP_BLOCKED`, unknown codes), other statuses | `ServiceUnavailable`, `errorCode` = VIES code when the body is readable                                                                          |
| any    | element of the wrong type in an id list, a duplicate in a collection constructor                                                    | `InvalidInput`                                                                                                                                   |

VIES reports its errors in an HTTP 200 body; the library never turns them into `valid === false`.

## What the data means

The ARES status in the 16 source registers (`$company->registrations`) is a pointer, not an answer:

| Do not assume                                         | Reality                                                                                                                                                                                                                                                                                                                                                                               |
|-------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| VAT id filled in ARES = VAT payer                     | the VAT id stays after the registration ended (`26863154` has a DIČ and `Vat = Ended`); `Vat = Active` also covers identified persons. Only ADIS decides whether a subject is a VAT payer; `Vat = Ended` can lag behind ADIS (`10803351` is a payer in ADIS), so `VatRegistrationEnded` yields to an `Ok` VAT section                                                         |
| every company has a DIČ of its own                    | a VAT group member has a group DIČ and its own `vatId` is `null` or a former own DIČ (Komerční banka `45317054`: group DIČ `CZ699001182`, no own; `21985685`: former own DIČ, `Vat = Ended`). Use `vatLookupId()`, never `vatId`                                                                                                                                                  |
| a DIČ can be derived from the IČO                     | natural persons have a nine- or ten-digit DIČ: the birth number (nine digits for births before 1954) or a nine-digit identifier assigned by the tax administrator (starts with 6; daňový řád § 130 odst. 4), which also foreign persons and VAT groups (`CZ699…`) get, and a derived DIČ of a group member is usually not found in ADIS. Always take the DIČ from ARES                                                                                                        |
| `Insolvency = Active` means in insolvency now         | it stays `Active` after the proceedings ended (České aerolinie `45795908`). Only the insolvency register tells whether it is current (`Section::Insolvency`, `isInInsolvency()`, flag `Insolvency`) — hence the ARES flag is named `InsolvencyRecord`                                                                                                                                          |
| `Bankruptcy` (CEÚ) reflects insolvency                | it does not: CEÚ (centrální evidence úpadců) holds only bankruptcy (konkurs) and composition (vyrovnání) proceedings under the former Act No. 328/1991 Sb., i.e. opened before 1 January 2008; everything since is in the insolvency register (Act No. 182/2006 Sb. § 432). Sberbank CZ `25083325` in bankruptcy: `Nonexistent`. Do not use it                                                                                                                                                                                                                                                                                                      |
| every IČO in ARES satisfies the check digit           | no: `00123562`, `29340042` are active. `$company->id` may have `hasValidCheckDigit() === false`; strings you pass stay strict, so re-hydrate ids stored from ARES with `CompanyId::fromRegister()` (§ Storing identifiers); the check digit is a convention of the register administrator, not a legal requirement                                                                                                                                                            |
| `taxOfficeCode` of ARES and ADIS name the same office | not always. Both are codes of the list `FinancniUrad`. ARES `financniUrad` is the competent workplace (`293` = Územní pracoviště Brno-venkov) or `013` Specialised Tax Office; ADIS `cisloFu` is a regional office 451–464 (`461` = Finanční úřad pro Jihomoravský kraj) or `013`. They are equal only for Specialised Tax Office subjects; never compare or join them across sources |
| only legal forms 101–108 are natural persons          | also 100 (domestic self-employed natural person, in source `rzp` also a non-entrepreneur natural person), 424 (foreign natural person) and 425 (its branch, named after a person); `isNaturalPerson()` covers all eleven forms                                                                                                                                                        |
| `datumZaniku` marks the dissolution                   | it is the end of existence (zánik, NOZ § 185) or of the registration, not the dissolution (zrušení, NOZ § 168); a dissolved company in liquidation is active in ARES (`InLiquidation`). ARES carries a future `datumZaniku` for some active subjects (`72396067`, a natural person with an authorisation recorded until 2035-12-10); `Ceased` is raised only once the date has come (`Company::hasCeased()`)                                                                                                                                                                                              |
| a deleted subject is returned                         | ARES usually answers 404, so `find()` returns `null`; it can serve a subject that has ceased for some days after `datumZaniku`, then `Ceased` can appear next to `PersonsRegister = Active`                                                                                                                                                                                              |
| statuses are complete                                 | all 16 registers are always present; a key ARES omits is `Nonexistent`; a value ARES adds later is `Unknown`                                                                                                                                                                                                                                                                          |

The insolvency register answers for the moment of the query and only for what the court recorded:

| Do not assume                                      | Reality                                                                                                                                                                         |
|----------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| the service's `filtrAktualniRizeni` means ongoing  | it hides only `ODSKRTNUTA` and `PRAVOMOCNA`, other ended proceedings stay; the library asks for every listed proceeding and decides with `InsolvencyProceeding::isOngoing()`     |
| `KONKURS` or `ÚPADEK` = the proceeding is running  | the state can stay after the end (České aerolinie `45795908`: `ÚPADEK` with an end date); `isOngoing()` checks the end date and the ended states                                 |
| an empty result = never insolvent                  | it means "not on the list of debtors now": the court removes a debtor 5 years after the end of the proceeding took legal force, sooner in some cases (Act No. 182/2006 Sb. § 425) |
| a self-employed person is always found by IČO      | only if the court recorded the IČO with the debtor                                                                                                                              |

What the registers do not return: the date a VAT registration started or ended and its history (neither ADIS nor ARES
has it), and the reason a subject is an unreliable payer. The library does not cover statutory bodies and members
(public register extracts), financial statements, beneficial owners, enforcement proceedings, subsidies or sanction
lists.

## Limits

- Bulk: 100 ids per request; `findMany()` chunks automatically (ARES rejects 101+ ids, ADIS answers status code 1)
  and sends at most `$maxConcurrency` batches at a time (1–4; ARES default 2, ADIS default 4). The bound holds only
  inside one `findMany()` call, never across calls; requests of several workers on one IP address add up.
- ARES search: at most 1 000 results; a broader query is an `InvalidInput`.
- ADIS is unavailable every night from 0:00 to 0:10 (`ServiceUnavailable`, `errorCode` `2`).
- VIES and the member states throttle concurrent requests (`MS_MAX_CONCURRENT_REQ`, `GLOBAL_MAX_CONCURRENT_REQ`);
  treat these as `ServiceUnavailable` and retry later. Some member states do not disclose name and address.
- ISIR has no bulk query: one `find()` is one request, 0.1–0.25 s observed.
- Default timeout 10 s per request (idle and total), including the time a request waits queued at the source.

The operators publish terms of use. The library is stateless and does not enforce them; a breach can get your IP
address restricted or blocked, so throttle in your application (all workers behind one IP address count together):

| Source | Declared terms                                                                                                                                                                                                                                | Published at                                                                                                                                                      |
|--------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| ARES   | at most 500 requests per minute; no "larger number of simultaneous requests" from automated clients (no figure is published); no repeated identical or mostly invalid requests, no probing with random data                                   | [ares.gov.cz › Info pro vývojáře](https://ares.gov.cz/stranky/vyvojar-info), [mf.gov.cz › ARES](https://mf.gov.cz/cs/ministerstvo/informacni-systemy/ares)        |
| ADIS   | at most 4 requests in parallel, 2 000 requests per hour and 10 000 requests per 24 hours (one request = one call with up to 100 DIČ); no repeated identical requests. Scheduled maintenance every Sunday 3:00–4:00                            | [MOJE daně › Dokumentace › webová služba](https://adisspr.mfcr.cz/pmd/dokumentace/webove-sluzby-spolehlivost-platcu)                                              |
| VIES   | a global and a per-member-state cap on concurrent requests, counted across all users; the thresholds are not published. A request above the cap is rejected (`*_MAX_CONCURRENT_REQ*`); abusive use gets the IP address blocked (`IP_BLOCKED`) | [VIES › FAQ (Q15)](https://ec.europa.eu/taxation_customs/vies/#/faq), [Technical information](https://ec.europa.eu/taxation_customs/vies/#/technical-information) |
| ISIR   | no limits or terms published; the service is undocumented apart from its XSD, listens on port 8443 and is due to change (eISIR)                                                                                                               | —                                                                                                                                                                 |

- The library does no caching, retrying, rate limiting, scheduling or persistence. Add what you need around it (cache
  profiles, retry `ServiceUnavailable` with back-off, queue lookups).

## Testing your code

All four registers sit behind interfaces — `CompanyDirectory`, `VatRegister`, `Vies`, `InsolvencyRegister` — so your
code can depend on them and your tests can substitute doubles. Collections are built from lists, and every company needs
an IČO and ids must be unique, or the constructor throws `InvalidInput`.

```php
use IdSign\BusinessRegisters\Ares\AresRegister;
use IdSign\BusinessRegisters\Ares\Companies;
use IdSign\BusinessRegisters\Ares\Company;
use IdSign\BusinessRegisters\Ares\CompanyDirectory;
use IdSign\BusinessRegisters\Ares\Registrations;
use IdSign\BusinessRegisters\Ares\RegistrationStatus;
use IdSign\BusinessRegisters\CompanyId;

$company = new Company(
    aresId: '45274649',
    id: CompanyId::parse('45274649'),
    name: 'Test s.r.o.',
    legalFormCode: '112',
    vatId: null,
    groupVatId: null,
    taxOfficeCode: null,
    seat: null,
    deliveryAddressLines: [],
    establishedOn: null,
    ceasedOn: null,
    updatedOn: null,
    naceCodes: [],
    naceCodes2008: [],
    fileNumber: null,
    primarySource: null,
    registrations: new Registrations([AresRegister::Vat->value => RegistrationStatus::Active]),
);

$directory = $this->createStub(CompanyDirectory::class);   // PHPUnit
$directory->method('findMany')->willReturn(new Companies([$company]));
```

`VatSubjects` and `CompanyProfiles` are built the same way from a list of `VatSubject` or `CompanyProfile`;
`InsolvencyProceedings` from a list of `InsolvencyProceeding` and the freshness hint
(`new InsolvencyProceedings([], synchronisedAt: null)` for a subject that is not listed).
`CompanyProfile` has a public constructor too; use named arguments, and key `statuses` and `errors` by `Section::name`:

```php
use IdSign\BusinessRegisters\CompanyProfile;
use IdSign\BusinessRegisters\Section;
use IdSign\BusinessRegisters\SectionStatus;

$profile = new CompanyProfile(
    company: $company,
    vat: null,
    vies: null,
    insolvencies: null,
    statuses: [Section::Vat->name => SectionStatus::Unavailable],
    errors: [],
);
```

To test the clients themselves, feed them a `MockHttpClient`:

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$client = new AresClient(new MockHttpClient(new MockResponse($json, ['http_code' => 200])));
```

The official VIES test service can be used through the `$endpoint` argument
(`https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service`); it accepts the numbers `100`–`601`
with any country prefix (`DE100`) and answers with the corresponding error.

## AI assistant skill

`.claude/skills/business-registers/SKILL.md` is a self-contained guide for an AI assistant working in a project that
uses this library. It is not part of the Composer package. In Claude Code, install it as a plugin from this repository:

```
/plugin marketplace add id-sign/business-registers
/plugin install business-registers@id-sign
```

Without the plugin, copy the `business-registers` directory into your project's `.claude/skills/`; another assistant
can read `SKILL.md` directly.

## Development

```bash
composer install
composer test        # unit suite, no network
composer test:live   # live suite against the production registers (ARES, ADIS, VIES, ISIR)
composer phpstan     # PHPStan, level max, strict rules, over src and tests
composer cs          # PHP CS Fixer, dry run (composer cs:fix applies)
composer check       # cs, phpstan and test; run before every commit
```

Contribution rules are in [CONTRIBUTING.md](CONTRIBUTING.md); report a vulnerability as described in
[SECURITY.md](SECURITY.md).

`make test`, `make test-live`, `make phpstan`, `make cs-fix` and `make test-matrix` run the same in Docker
(`PHP_VERSION=8.4` by default; `test-matrix` covers 8.4 and 8.5). `make` leaves a root-owned `composer.phar`
(gitignored) in the project root. The live suite may skip a VIES test when production VIES is throttling; run it again.

## Disclaimer

This is an independent project. It is not affiliated with or endorsed by the Ministry of Finance of the Czech Republic,
the Czech Tax Administration, the European Commission or the Ministry of Justice of the Czech Republic, which operate
ARES, ADIS, VIES and ISIR. The library passes on what these services answer; risk flags are derived from that data
by the rules above (see [What the data means](#what-the-data-means)) and are not a legal assessment of a subject.

## License

MIT. See [LICENSE](LICENSE).
