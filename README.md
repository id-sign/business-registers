# Business registers

Typed, stateless PHP client for Czech business registers. One call gives you a company profile that combines three
sources, so you never deal with JSON, SOAP or per-source quirks yourself:

- **ARES** — company identity: name, seat, legal form, VAT ids, status in 16 source registers; bulk lookup and search.
- **VAT register (ADIS)** — VAT payer status, unreliable payers, published bank accounts.
- **VIES** — validity of an EU VAT id, with a consultation number.

Register extracts (public register, trade register), the insolvency register (ISIR) and ARES code lists and change
feeds are planned for later releases and are not part of this one (`v0.1.0`).

## What you can get

**Company identity (ARES)** — by IČO, for up to 100 IČO per request, or by search:

- name, IČO (or the ARES id of a subject without one), legal form code
- VAT id (DIČ) and, for a member of a VAT group, the group's VAT id
- registered seat: full text, street with house numbers, city, district, postal code, county, region, country,
  RÚIAN address point and municipality codes; delivery address lines
- date of establishment, dissolution and last update
- CZ-NACE activity codes (2025 and 2008 classification)
- public register file number (e.g. `B 1581/MSPH`), tax office code, primary source register
- status in 16 source registers (public register, trade register, VAT, VAT group, insolvency, …): active, historical,
  dissolved, suspended, …
- search by name, address text, municipality, legal form, CZ-NACE or tax office (up to 1 000 results)

**VAT register (ADIS)** — by DIČ, for up to 100 DIČ per request:

- subject type: VAT payer, VAT group, identified person, unreliable person
- unreliability (ADIS `nespolehlivyPlatce`, also set for unreliable persons) and the date it was published
- published bank accounts, with publication dates, including accounts no longer published; a check whether a given
  account (domestic form or Czech IBAN) is published
- name, address and tax office of the subject
- the complete list of unreliable payers

**EU VAT id (VIES)** — for any EU member state:

- whether the VAT id is valid
- name and address, where the member state discloses them
- a consultation number as proof of the check, when you pass your own VAT id
- whether a declared name, street, postal code, city and company type match the register, where the member state
  compares them

**Company profile** — one call that combines the sources above, reports per source whether it answered, and derives
risk flags: dissolved, in liquidation, insolvency record, unreliable VAT payer, unreliable person, VAT registration
ended, VAT payer without a published bank account, VAT id invalid in VIES. For a list of IČO, one call builds all
profiles with one ARES and one ADIS request per 100 companies.

**Validation without a request** — IČO (including the check digit) and DIČ (format; strict digits-only check for CZ)
are normalised and validated locally before any register is asked. An IČO that ARES itself returns is taken as the
register holds it, even when it fails the check digit (see § What the data means).

## Requirements

- PHP 8.4+
- `ext-dom`
- any implementation of `symfony/http-client-contracts` (for example `symfony/http-client`)

## Installation

The package is not published yet; once it is on Packagist (planned name `id-sign/business-registers`):

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
IdSign\BusinessRegisters\CompanyLookup: ~
```

### Without a framework

```php
use IdSign\BusinessRegisters\Adis\VatRegisterClient;
use IdSign\BusinessRegisters\Ares\AresClient;
use IdSign\BusinessRegisters\CompanyLookup;
use IdSign\BusinessRegisters\Vies\ViesClient;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();

$ares = new AresClient($http);
$vatRegister = new VatRegisterClient($http);
$vies = new ViesClient($http);
$lookup = new CompanyLookup($ares, $vatRegister, $vies);
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

$profile = $lookup->byCompanyId('452 746 49', Section::Vat, Section::Vies);

if (null === $profile) {
    // ARES does not hold the subject (also true for deleted subjects)
    return;
}

echo $profile->company->name;                    // "ČEZ, a. s."
$profile->isComplete();                          // false if any requested section is Unavailable or Rejected
$profile->status(Section::Vat);                  // SectionStatus::Ok
$profile->vat?->type;                            // SubjectType::VatPayer
$profile->vies?->valid;                          // true

$profile->flags();                               // list<RiskFlag>, e.g. [RiskFlag::UnreliableVatPayer]
$profile->hasFlag(RiskFlag::Dissolved);          // false

if (SectionStatus::Unavailable === $profile->status(Section::Vies)) {
    $profile->error(Section::Vies);              // the ServiceUnavailable or InvalidResponse that caused it
}
```

The IČO may be given as a string in any spacing or a `CompanyId`. A section whose source is down does not fail the
lookup: its status becomes `Unavailable` and the other sections are still filled. A section whose source rejects the
request (an `InvalidInput` from ADIS or VIES, e.g. VIES `INVALID_REQUESTER_INFO` for a wrong requester) becomes
`Rejected` the same way, with the exception and its `errorCode` in `error()`; the answer is **unknown** and asking again
will not help until the input or configuration is fixed. Only ARES errors are thrown, because without ARES there is no
profile.

#### Five meanings of `null`

`$profile->vat` and `$profile->vies` are `null` in five different situations; `status()` tells them apart:

| `status($section)` | Meaning                                                                                                      |
|--------------------|--------------------------------------------------------------------------------------------------------------|
| `NotRequested`     | the section was not passed to `byCompanyId()` / `byCompanyIds()`                                             |
| `NotFound`         | the source answered that it does not hold the subject                                                        |
| `NotApplicable`    | the subject has no VAT id, so there is nothing to ask for                                                    |
| `Unavailable`      | the source could not answer — the answer is **unknown**; `error()` holds the exception                       |
| `Rejected`         | the source rejected the request — **unknown**; fix the input or configuration; `error()` holds the exception |

`SectionStatus` is string-backed (`not_requested`, `ok`, `not_found`, `not_applicable`, `unavailable`, `rejected`) and
`json_encode($profile)` works. The status values are a stable contract; stored exceptions in `errors` encode as empty
objects.

#### Flags

`flags()` returns the raised `RiskFlag` cases in enum order. Flags are computed from ARES and only from sections whose
status is `Ok`. An absent flag means "clean" **only when `isComplete()` is true and the section the flag comes from was
requested**.

| Flag                     | Raised when                                                                                                                                                                                      | Needs           |
|--------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------|
| `Dissolved`              | ARES records a dissolution date                                                                                                                                                                  | —               |
| `InLiquidation`          | the name contains the standalone phrase "v likvidaci" anywhere: at the end, before the legal form (`… v likvidaci, s.r.o.`), between dashes, slashes or parentheses; case-insensitive            | —               |
| `InsolvencyRecord`       | ARES lists the subject in the insolvency register — a record, possibly a closed one                                                                                                              | —               |
| `UnreliableVatPayer`     | a VAT payer or VAT group the VAT register marks as unreliable; an unreliable person gets `UnreliablePerson` only                                                                                 | `Section::Vat`  |
| `UnreliablePerson`       | the VAT register keeps the subject as an unreliable person                                                                                                                                       | `Section::Vat`  |
| `VatRegistrationEnded`   | ARES VAT registration is `Dissolved` or `Historical` and the subject is not in an active VAT group                                                                                               | —               |
| `NoPublishedBankAccount` | a VAT payer or VAT group without any active published bank account                                                                                                                               | `Section::Vat`  |
| `ViesInvalid`            | VIES answered that the VAT id is not valid                                                                                                                                                       | `Section::Vies` |

#### Shortcuts: `isVatPayer()` and `hasPublishedAccount()`

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

Requesting a section whose client was not passed to the `CompanyLookup` constructor also throws `\LogicException`,
before any request is made.

#### VAT group members

A member of a VAT group has no DIČ of its own in ARES (Komerční banka, `45317054`: group DIČ `CZ699001182`). The facade
looks such a subject up in ADIS and VIES under the group DIČ (`Company::vatLookupId()`), so `$profile->vat` and
`$profile->vies` describe the group. Do the same when you call the clients yourself: send `$company->vatLookupId()`,
never a DIČ derived from the IČO.

#### VIES requester

Pass your own VAT id as the fourth constructor argument to get a consultation number in `$profile->vies`:

```php
use IdSign\BusinessRegisters\VatId;

$lookup = new CompanyLookup($ares, $vatRegister, $vies, viesRequester: VatId::parse('CZ12345678'));
```

#### Many companies at once

`CompanyLookup::byCompanyIds()` builds the profiles of a list of IČO with as few requests as possible: ARES is asked
with one `findMany()`, section `Vat` with one ADIS `findMany()` over the companies' `vatLookupId()` values (a VAT group
is asked once for all its members). 100 IČO with `Section::Vat` are 1 ARES and 1 ADIS request (both clients send
batches of 100). VIES has no bulk call: `Section::Vies` makes one `check()` per company with a DIČ, one after another,
so 100 companies with `Vies` take minutes.

```php
$requested = $idsFromDatabase;                   // list<CompanyId|string>
$profiles = $lookup->byCompanyIds($requested, Section::Vat);

foreach ($profiles as $profile) {                // values only, in ARES response order
    $profile->status(Section::Vat);
}
$profiles->get('45274649')?->isVatPayer();       // ids in any form, as in Companies
foreach ($profiles->missing($requested) as $id) {   // list<CompanyId> — not held by ARES
}
```

The statuses mean the same as for one company. If the ADIS call fails, `Vat` is `Unavailable` (outage, invalid
response) or `Rejected` for every profile in that call; a company whose lookup DIČ is not Czech is not sent to ADIS
and gets `Rejected`. A VIES failure affects only that company. Every IČO and the section clients are checked before
the first request; ARES errors are thrown.

#### Change tracking

To detect changes between runs, store snapshots of the DTOs (`$profile->company`, `$profile->vat`, `$profile->vies`),
not of the whole profile. `serialize($profile)` can fail when a stored exception carries a stack trace with
non-serialisable arguments (`zend.exception_ignore_args=0`, the `php.ini-development` default).

### Storing identifiers

IČO is a **string**, never an `int`: it has leading zeros (`00064581`), and PHP turns digit-only array keys into
integers. Store it in a `CHAR(8)` / `VARCHAR(8)` column, edit it with a Symfony `TextType`, keep it in string DTO
properties. The library accepts `CompanyId|string` everywhere and rejects other types with `InvalidInput`; a legacy
`INT` column must be cast to a string at your boundary (better: migrate the column). `CompanyId::parse()` restores the
leading zeros. DIČ is a string for the same reason.

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
`CompanyLookup::byCompanyId()` / `byCompanyIds()`; a `CompanyId` object is taken as it is. To look up a subject whose
IČO fails the check digit, pass `CompanyId::fromRegister()` or the `$company->id` from an earlier response.

### ARES

```php
use IdSign\BusinessRegisters\Ares\CompanySearch;

$company = $ares->find('45274649');      // ?Company; null when ARES does not hold the subject
$company->name;
$company->seat?->street;                 // "Duhová 1444/2"
$company->seat?->postalCodeFormatted();  // "140 00"
$company->vatId;                         // ?VatId — a filled value does not mean "VAT payer"
$company->vatLookupId();                 // ?VatId — the one to send to ADIS and VIES
$company->isNaturalPerson();             // legal form 100, 101–108, 424, 425
$company->registrations->active();       // list<AresRegister>
```

`findMany()` removes duplicates, sends the ids in batches of 100 (up to `$maxConcurrency` batches at a time) and
returns a `Companies` collection. Ids that ARES does not hold are absent. If a batch fails, the first failure in
sending order is thrown and no further batch is sent; ADIS `findMany()` works the same way.

```php
$requested = $idsFromDatabase;           // list<string>
$companies = $ares->findMany($requested);

$companies->has('64581');                // ids in any form: "00064581", "452 746 49", a CompanyId
$companies->get('00064581')?->name;
count($companies);

foreach ($companies as $company) {       // values only, in ARES response order (ascending IČO)
}

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

Criteria: `name`, `address`, `municipalityCode`, `legalFormCodes`, `naceCodes`, `taxOfficeCodes`, plus `limit`
(1–1 000, default 20), `offset` (0 or greater) and `orderBy`. At least one criterion must be filled; a blank string is
not a criterion. An invalid query throws `InvalidInput` before any request.

### VAT register (ADIS)

Only Czech DIČ are accepted; a string without a country code is read as `CZ`, a non-Czech one is an `InvalidInput`.

```php
$subject = $vatRegister->find('CZ45274649');     // ?VatSubject; null when the register does not hold it
$subject->type;                                  // SubjectType::VatPayer
$subject->isVatPayer();                          // true for a VAT payer and a VAT group
$subject->unreliable;                            // bool
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
`taxOfficeCode`). The list has about 4 300 entries and 500 kB; consider a longer `$timeout` than the default 10 s.
The list also contains unreliable persons, who are not VAT payers; it does not carry the subject type — only
`findMany()` (`VatSubject::$type`) tells them apart. `VatSubject::$unreliable` is likewise `true` for an unreliable
person.

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

## Error handling

Every exception implements `IdSign\BusinessRegisters\Exception\ExceptionInterface`.

| Exception                                            | Meaning                                                                 | What to do                                                               |
|------------------------------------------------------|-------------------------------------------------------------------------|--------------------------------------------------------------------------|
| `InvalidInput` (extends `\InvalidArgumentException`) | the input is invalid or the source rejected it                          | fix the input or ask the user; repeating will not help                   |
| `ServiceUnavailable`                                 | the source could not answer: transport error, timeout, outage, overload | try again later; it is **never** a business answer such as "not a payer" |
| `InvalidResponse`                                    | the source answered something the library cannot read                   | an error to investigate; report it                                       |

`InvalidInput` has `?string $errorCode`; `ServiceUnavailable` has `Source $source` and `?string $errorCode`;
`InvalidResponse` has `Source $source`. `Source` is `Ares`, `Adis`, `Vies` (and `Isir`, reserved).
`CompanyLookup::byCompanyId()` throws only ARES errors; an exception from a section is recorded in the profile
(`Unavailable` or `Rejected`, see [Five meanings of `null`](#five-meanings-of-null)).

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
- Messages never contain response text (ARES `popis`, ADIS `statusText`, SOAP `faultstring`, VIES `message`) or record
  data. They may contain the id you passed in and the HTTP status.
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
| VIES   | `INVALID_INPUT`, `INVALID_REQUESTER_INFO`, HTTP 400                                                                                 | `InvalidInput`, `errorCode` = VIES code                                                                                                          |
| VIES   | every other code (`MS_UNAVAILABLE`, `TIMEOUT`, `*_MAX_CONCURRENT_REQ*`, `VAT_BLOCKED`, `IP_BLOCKED`, unknown codes), other statuses | `ServiceUnavailable`, `errorCode` = VIES code when the body is readable                                                                          |
| any    | element of the wrong type in an id list, a duplicate in a collection constructor                                                    | `InvalidInput`                                                                                                                                   |

VIES reports its errors in an HTTP 200 body; the library never turns them into `valid === false`.

## What the data means

The ARES status in the 16 source registers (`$company->registrations`) is a pointer, not an answer:

| Do not assume                                 | Reality                                                                                                                                                                                       |
|-----------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| VAT id filled in ARES = VAT payer             | the VAT id stays after the registration ended (`26863154` has a DIČ and `Vat = Dissolved`); `Vat = Active` also covers identified persons. Only ADIS decides whether a subject is a VAT payer |
| every company has a DIČ of its own            | a VAT group member has none (Komerční banka `45317054`: group DIČ `CZ699001182`); use `vatLookupId()`                                                                                         |
| a DIČ can be derived from the IČO             | natural persons have ten-digit DIČ; a derived DIČ of a group member is not found in ADIS. Always take the DIČ from ARES                                                                       |
| `Insolvency = Active` means in insolvency now | it stays `Active` after the proceedings ended (České aerolinie `45795908`). Only the insolvency register (planned) tells whether it is current — hence the flag is named `InsolvencyRecord`   |
| `Bankruptcy` (CEÚ) reflects insolvency        | it does not (Sberbank CZ `25083325` in bankruptcy: `Nonexistent`). Do not use it                                                                                                              |
| every IČO in ARES satisfies the check digit   | no: `00123562`, `29340042` are active. `$company->id` may have `hasValidCheckDigit() === false`; strings you pass stay strict, look such a subject up with `CompanyId::fromRegister()`        |
| only legal forms 101–108 are natural persons  | also 100 (natural person in the commercial register), 424 (foreign natural person) and 425 (its branch, named after a person); `isNaturalPerson()` covers all eleven forms                    |
| a deleted subject is returned                 | ARES answers 404, so `find()` returns `null`                                                                                                                                                  |
| statuses are complete                         | all 16 registers are always present; a key ARES omits is `Nonexistent`; a value ARES adds later is `Unknown`                                                                                  |

What the registers do not return: the date a VAT registration started or ended and its history (neither ADIS nor ARES
has it), and the reason a subject is an unreliable payer. The library does not cover financial statements, beneficial
owners, enforcement proceedings, subsidies or sanction lists. Statutory bodies and members (public register) are
planned for a later release.

## Limits

- Bulk: 100 ids per request; `findMany()` chunks automatically (ARES rejects 101+ ids, ADIS answers status code 1)
  and sends at most `$maxConcurrency` batches at a time (1–4; ARES default 2, ADIS default 4). The bound holds only
  inside one `findMany()` call, never across calls; requests of several workers on one IP address add up.
- ARES search: at most 1 000 results; a broader query is an `InvalidInput`.
- ADIS is unavailable every night from 0:00 to 0:10 (`ServiceUnavailable`, `errorCode` `2`).
- VIES and the member states throttle concurrent requests (`MS_MAX_CONCURRENT_REQ`, `GLOBAL_MAX_CONCURRENT_REQ`);
  treat these as `ServiceUnavailable` and retry later. Some member states do not disclose name and address.
- Default timeout 10 s per request (idle and total), including the time a request waits queued at the source.

The operators publish terms of use. The library is stateless and does not enforce them; a breach can get your IP
address restricted or blocked, so throttle in your application (all workers behind one IP address count together):

| Source | Declared terms                                                                                                                                                                                                                                | Published at                                                                                                                                                      |
|--------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| ARES   | at most 500 requests per minute; no "larger number of simultaneous requests" from automated clients (no figure is published); no repeated identical or mostly invalid requests, no probing with random data                                   | [ares.gov.cz › Info pro vývojáře](https://ares.gov.cz/stranky/vyvojar-info), [mf.gov.cz › ARES](https://mf.gov.cz/cs/ministerstvo/informacni-systemy/ares)        |
| ADIS   | at most 4 requests in parallel, 2 000 requests per hour and 10 000 requests per 24 hours (one request = one call with up to 100 DIČ); no repeated identical requests. Scheduled maintenance every Sunday 3:00–4:00                            | [MOJE daně › Dokumentace › webová služba](https://adisspr.mfcr.cz/pmd/dokumentace/webove-sluzby-spolehlivost-platcu)                                              |
| VIES   | a global and a per-member-state cap on concurrent requests, counted across all users; the thresholds are not published. A request above the cap is rejected (`*_MAX_CONCURRENT_REQ*`); abusive use gets the IP address blocked (`IP_BLOCKED`) | [VIES › FAQ (Q15)](https://ec.europa.eu/taxation_customs/vies/#/faq), [Technical information](https://ec.europa.eu/taxation_customs/vies/#/technical-information) |

- The library does no caching, retrying, rate limiting, scheduling or persistence. Add what you need around it (cache
  profiles, retry `ServiceUnavailable` with back-off, queue lookups).

## Testing your code

All three registers sit behind interfaces — `CompanyDirectory`, `VatRegister`, `Vies` — so your code can depend on them
and your tests can substitute doubles. Collections are built from lists, and every company needs an IČO and ids must be
unique, or the constructor throws `InvalidInput`.

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
    dissolvedOn: null,
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

`VatSubjects` and `CompanyProfiles` are built the same way from a list of `VatSubject` or `CompanyProfile`.
`CompanyProfile` has a public constructor too; use named arguments, and key `statuses` and `errors` by `Section::name`:

```php
use IdSign\BusinessRegisters\CompanyProfile;
use IdSign\BusinessRegisters\Section;
use IdSign\BusinessRegisters\SectionStatus;

$profile = new CompanyProfile(
    company: $company,
    vat: null,
    vies: null,
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
uses this library. It lives in the repository only (it is not part of the Composer package); copy the
`business-registers` directory into your project's `.claude/skills/`.

## Development

```bash
composer install
composer test        # unit suite, no network
composer test:live   # live suite against the production registers (ARES, ADIS, VIES)
composer phpstan     # PHPStan, level max, strict rules, over src and tests
composer cs          # PHP CS Fixer, dry run (composer cs:fix applies)
composer check       # cs, phpstan and test; run before every commit
```

`make test`, `make test-live`, `make phpstan`, `make cs-fix` and `make test-matrix` run the same in Docker
(`PHP_VERSION=8.4` by default; `test-matrix` covers 8.4 and 8.5). `make` leaves a root-owned `composer.phar`
(gitignored) in the project root. The live suite may skip a VIES test when production VIES is throttling; run it again.

## License

MIT. See [LICENSE](LICENSE).
