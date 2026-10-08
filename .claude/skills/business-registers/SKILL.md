---
name: business-registers
description: Typed PHP client for Czech business registers (ARES, VAT register ADIS, VIES, insolvency register ISIR). Use in a PHP project with id-sign/business-registers when looking up a Czech company by IČO, validating IČO or DIČ, checking VAT payer status, unreliable payer, published bank accounts, EU VAT id validity (VIES) or insolvency proceedings (ISIR), or when writing tests for code that uses the library.
---

# business-registers (id-sign/business-registers v0.1.0)

Stateless PHP 8.4+ library. It covers ARES (company identity), the VAT register ADIS (VAT payer, unreliable payer,
published bank accounts), VIES (EU VAT id validity) and the insolvency register ISIR (proceedings by IČO). Not
covered: register extracts, ARES code lists and the ARES change feed.

## Setup

Install `id-sign/business-registers` and an HTTP client (`symfony/http-client`) with Composer. It needs `ext-dom`;
any `symfony/http-client-contracts` implementation works.

```php
$http = Symfony\Component\HttpClient\HttpClient::create();
$ares = new IdSign\BusinessRegisters\Ares\AresClient($http);            // implements CompanyDirectory
$vat = new IdSign\BusinessRegisters\Adis\VatRegisterClient($http);      // implements VatRegister
$vies = new IdSign\BusinessRegisters\Vies\ViesClient($http);            // implements Vies
$isir = new IdSign\BusinessRegisters\Isir\InsolvencyClient($http);      // implements InsolvencyRegister; port 8443
$lookup = new IdSign\BusinessRegisters\CompanyLookup($ares, $vat, $vies, $isir, viesRequester: null);  // ?VatId, see VIES
```

Clients take optional `string $endpoint` and `float $timeout = 10.0`; `AresClient` and `VatRegisterClient` also
`int $maxConcurrency` (batches of one `findMany()` sent at once, 1-4, else `\InvalidArgumentException`; default ARES
2, ADIS 4; lower it when workers share an IP; parallel only with `CurlHttpClient`, not `NativeHttpClient`).
`timeout` is per request and includes the time it waits queued at the source: with a shared IP or a tight timeout,
lower `maxConcurrency`. In Symfony register the clients and bind each interface to its client in `services.yaml`
(`CompanyDirectory`, `VatRegister`, `Vies`, `InsolvencyRegister`, `CompanyLookup: ~`). Inject the interfaces, not the clients.

## Facade or single client

- Facade `CompanyLookup`: a profile for one IČO (ARES plus optional ADIS, VIES and ISIR sections, risk flags), or
  `byCompanyIds()` for a list (1 ARES + 1 ADIS request per 100 IČO; VIES one `check()` per distinct lookup DIČ,
  sequential, a requester rejection stops VIES for the rest of the call: 100 companies with `Vies` take minutes; ISIR
  one `find()` per company, sequential, 0.10–0.25 s each; a connection failure stops ISIR for the rest). Use it for
  "everything about these companies" with tolerance to an outage.
- Single client: bulk checks (`findMany`), search, the list of unreliable payers, a bank account check without ARES, VIES
  for a foreign VAT id.

## API (namespace `IdSign\BusinessRegisters`)

```php
// CompanyLookup
byCompanyId(CompanyId|string $id, Section ...$sections): ?CompanyProfile   // Section::Vat, Vies (by DIČ), Insolvency (by IČO)
byCompanyIds(array $ids, Section ...$sections): CompanyProfiles   // list<CompanyId|string>; same statuses; ids checked
// before any request; a failed ADIS call makes Vat Unavailable for every profile asked in it; a non-CZ lookup DIČ is
// not sent to ADIS -> Vat Rejected (VIES is still asked); no lookup DIČ -> Vat/Vies NotApplicable; no IČO -> Insolvency
// NotApplicable; Insolvency is Ok with an empty collection, never NotFound

// CompanyProfile (readonly): company, vat, vies, insolvencies, statuses, errors
$profile->company;                      // Ares\Company
$profile->vat;                          // ?Adis\VatSubject
$profile->vies;                         // ?Vies\ViesResult
$profile->insolvencies;                 // ?Isir\InsolvencyProceedings
$profile->status(Section $s): SectionStatus
$profile->error(Section $s): ?ExceptionInterface
$profile->isComplete(): bool            // no requested section Unavailable/Rejected, Insolvency not NotApplicable
$profile->flags(): list<RiskFlag>       // enum order
$profile->hasFlag(RiskFlag $f): bool
$profile->isVatPayer(): ?bool
$profile->hasPublishedAccount(string $account): ?bool
$profile->isInInsolvency(): ?bool       // Section::Insolvency: an ongoing proceeding (filed petition included)

// Ares\CompanyDirectory
find(CompanyId|string $id): ?Company
findMany(array $ids): Ares\Companies    // list<CompanyId|string>
search(Ares\CompanySearch $q): Ares\CompanySearchResult   // ->total, ->companies (list<Company>)
new CompanySearch(name:, address:, municipalityCode: ?int, legalFormCodes:, naceCodes:, taxOfficeCodes:,
                  limit: 20, offset: 0, orderBy: [])
// >= 1 criterion (a blank name/address does not count; any municipalityCode or non-empty code list does),
// limit 1–1 000, offset >= 0 — otherwise InvalidInput before any request

// Ares\Company (readonly): aresId, id (?CompanyId), name, legalFormCode, vatId (?VatId), groupVatId (?VatId),
// taxOfficeCode (ARES financniUrad: workplace or 013 Specialised Tax Office), seat (?Address), deliveryAddressLines,
// establishedOn, ceasedOn, updatedOn (?DateTimeImmutable), naceCodes, naceCodes2008, fileNumber, primarySource, registrations
$company->vatLookupId(): ?VatId          // groupVatId ?? vatId: the ONLY id to send to ADIS and VIES
$company->isNaturalPerson(): bool        // legal forms 100, 101-108, 424, 425
$company->hasCeased(?DateTimeImmutable $on = null): bool   // ceasedOn <= $on's calendar day; default today, Europe/Prague
$company->registrations->status(Ares\AresRegister $r): Ares\RegistrationStatus   // also active(), isActive($r)

// Ares\Companies, Adis\VatSubjects and CompanyProfiles (readonly, IteratorAggregate over values, Countable)
get($id): ?Company|?VatSubject|?CompanyProfile    has($id): bool    all(): list<…>    count()
// ids in any form; VatSubjects reads a string as CZ and throws InvalidInput for a non-CZ id
missing(array $requested): list<CompanyId> | list<VatId>      // requested ids that are absent

// Adis\VatRegister
find(VatId|string $vatId): ?VatSubject      // string without country = CZ; non-CZ -> InvalidInput
findMany(array $vatIds): Adis\VatSubjects
unreliablePayers(): list<Adis\UnreliablePayer>   // vatId, since, taxOfficeCode (ADIS cisloFu: office 451–464 or 013); ~4 300 entries, 500 kB
                                                 // only some unreliable persons, not reliably marked identified persons; no type;
                                                 // to screen for UnreliablePerson use find()/findMany() or the facade

// Adis\VatSubject (readonly): vatId, type (SubjectType), unreliable, unreliableSince,
// taxOfficeCode (ADIS cisloFu: regional office 451–464 or 013), name, address,
// bankAccounts (all, incl. ended), checkedAt
$s->isVatPayer(): bool                   // VatPayer or VatGroup
$s->activeBankAccounts(): list<BankAccount>      // BankAccount: prefix, number, bankCode, publishedFrom,
                                                 // publishedUntil, isActive(), isStandard(), (string) "27-5868650297/0100"
$s->hasPublishedAccount(string $account): bool   // active accounts only; a Czech account in any domestic form or as a
                                                 // Czech IBAN; a non-standard one (e.g. foreign IBAN) literally,
                                                 // case-insensitive; garbage = false

// Vies\Vies
check(VatId|string $vatId, VatId|string|null $requester = null, ?Vies\TraderDetails $trader = null): Vies\ViesResult
// a string needs the country prefix (no CZ default): check('45274649') is InvalidInput
// TraderDetails(name?, street?, postalCode?, city?, companyType?) — null and blank fields not sent, others unchanged
// ViesResult: vatId, valid, name?, address?, nameMatch?, streetMatch?, postalCodeMatch?, cityMatch?,
//             companyTypeMatch? (?MatchResult: Valid | Invalid | NotProcessed), consultationNumber?, checkedAt (UTC)

// Isir\InsolvencyRegister — one request per call, always every proceeding (ended ones too)
find(CompanyId|string $id): Isir\InsolvencyProceedings   // empty = not on the list NOW (§ 425 removes after 5 years)
// InsolvencyProceedings (readonly, IteratorAggregate, Countable): proceedings (list), synchronisedAt (?DateTimeImmutable,
// Prague local time, freshness hint only; absent on an empty result), ongoing(): list<…>, hasOngoing(): bool
// InsolvencyProceeding (readonly, one debtor row; one reference() may span co-debtors or one debtor twice): companyId?,
// birthNumber?, senate, caseType, caseNumber, year, court?, bornOn?, titleBefore?, titleAfter?, firstName?, name?,
// addressKind? (SÍDLO FY, SÍDLO ORG., TRVALÁ), address?, stateCode? (raw: NEVYRIZENA, ÚPADEK, KONKURS, REORGANIZ,
// ODDLUŽENÍ, PRAVOMOCNA, ODSKRTNUTA, VYRIZENA, …), detailUrl?, endedOn?, insolvencyDeclaredOn? (null although declared
// is possible: use stateCode), otherDebtorInProceeding (raw dalsiDluznikVRizeni, varies by query: not "has co-debtors")
$p->reference(): string                   // "95 INS 12575/2022"
$p->isOngoing(): bool                     // endedOn null AND stateCode not ODSKRTNUTA/PRAVOMOCNA/VYRIZENA/MYLNÝ ZÁP.;
                                          // missing/unknown state = ongoing; KONKURS/ÚPADEK can be ended

// Value types
CompanyId::parse(string): CompanyId        // ' 452 746 49 ', '64581' -> '00064581'; checksum; InvalidInput; tryParse(); ->value (8 digits), equals()
CompanyId::fromRegister(string): CompanyId // format only, no checksum: an IČO ARES uses although it fails it; also for ids stored from ARES
$id->hasValidCheckDigit(): bool            // false for register ids such as '00123562', '29340042'
VatId::parse(string, ?string $defaultCountry = null): VatId      // also tryParse(): ?VatId
// ->countryCode, ->number, isCzech(), equals(), (string) 'CZ45274649'; GR -> EL
Address: text, street, streetName, houseNumber, houseNumberType, orientationNumber, district, cityDistrict, city,
         postalCode, county, region, countryCode, countryName, addressPointId, municipalityCode; postalCodeFormatted()
// one meaning for ARES, ADIS, ISIR: street "Duhová 1444/2", its parts only when they split unambiguously
```

## Interpreting the data

**Five meanings of a `null` section (`$profile->vat`, `->vies`, `->insolvencies`)** — call `status()`:

| `SectionStatus` (string value) | Meaning |
|---|---|
| `NotRequested` (`not_requested`) | section not passed to `byCompanyId()` / `byCompanyIds()` |
| `NotFound` (`not_found`) | source does not hold the subject |
| `NotApplicable` (`not_applicable`) | subject has no id the section is asked under (DIČ for Vat/Vies, IČO for Insolvency) |
| `Unavailable` (`unavailable`) | source could not answer: UNKNOWN; `error()` has the exception |
| `Rejected` (`rejected`) | source rejected the request (`InvalidInput`, e.g. VIES `INVALID_REQUESTER_INFO`): UNKNOWN; retrying is useless, fix input/config; `error()` has the exception |
| `Ok` (`ok`) | the section property is filled |

**`isComplete()` before flags.** Flags come from ARES and from `Ok` sections only. No flag means "clean" only if
`isComplete()` is true and the section behind the flag was requested.

| `RiskFlag` | Condition | Section |
|---|---|---|
| `Ceased` | ARES `datumZaniku` (end of existence or registration, not the dissolution decision) is today or past (Europe/Prague); a future date = no flag | |
| `InLiquidation` | name contains the standalone phrase "v likvidaci" anywhere, also before the legal form or in parentheses; legal persons only, never for natural persons or foreign branches | |
| `InsolvencyRecord` | ARES lists an insolvency record, possibly closed; not proof of current insolvency | |
| `Insolvency` | ISIR lists an ongoing proceeding (`isOngoing()`, a filed petition included); independent of `InsolvencyRecord` | Insolvency |
| `UnreliableVatPayer` | ADIS `nespolehlivyPlatce` on a VAT payer or VAT group (`isVatPayer()`) | Vat |
| `UnreliablePerson` | ADIS: unreliable person, or an identified person marked unreliable | Vat |
| `VatRegistrationEnded` | ARES VAT registration Ended/Historical and no active VAT group; suppressed only by an `Ok` Vat section whose subject is a payer | (Vat can suppress) |
| `NoPublishedBankAccount` | payer or VAT group without active published account | Vat |
| `ViesInvalid` | VIES says invalid | Vies |

**Shortcuts `isVatPayer()` / `hasPublishedAccount()`** read `Section::Vat`:

| `status(Vat)` | Result |
|---|---|
| `Ok` | `true` / `false` from ADIS |
| `NotFound`, `NotApplicable` | `false` (definitive) |
| `Unavailable` | `null` = unknown, retry later. NEVER treat as "not a payer" or "account not published" |
| `Rejected` | `null` = unknown; retrying is useless, fix input/config |
| `NotRequested` | `\LogicException` (pass `Section::Vat`) |

`isInInsolvency()` likewise, except `NotApplicable` (no IČO) -> `null`: ISIR can list a person by birth number only.

**ARES is a pointer, not an answer.**
- A filled `vatId` is no proof of a payer; ADIS decides (`isVatPayer()`). ARES `Vat = Active` includes identified persons.
- A VAT group member has a `groupVatId`; its `vatId` is `null` or its former own DIČ (ARES `Vat = Ended` or
  `Nonexistent`). Ask ADIS/VIES with `vatLookupId()` (`groupVatId ?? vatId`), never with `vatId`; ADIS answers for the
  group, rarely still for the member's own DIČ too, and the facade asks the group only.
- Never derive a DIČ from an IČO. Natural persons have a nine- or ten-digit DIČ: the birth number (nine digits for
  births before 1954) or a nine-digit identifier assigned by the tax administrator (starts with 6; daňový řád § 130
  odst. 4), also for foreign persons and VAT groups (`CZ699…`). A derived DIČ of a group member is usually not in ADIS.
- `Company::$taxOfficeCode` (ARES `financniUrad`: workplace or `013`) and `VatSubject`/`UnreliablePayer::$taxOfficeCode`
  (ADIS `cisloFu`: regional office 451–464 or `013`) are codes of the same list `FinancniUrad`; equal only for
  Specialised Tax Office subjects, never compare them across sources.
- `Insolvency = Active` can be a closed proceeding (ISIR decides: `isInInsolvency()`); `Bankruptcy` (CEÚ) covers only
  pre-2008 proceedings, useless.
- 404 / `null` = "not in ARES", usually also for deleted subjects. A subject without IČO has `id === null` and an
  `aresId` like `ARES_########`.
- A future `ceasedOn` is a recorded end of an authorisation (natural persons), not a subject that has ceased; a company in
  liquidation is active with `InLiquidation`; a subject that has really ceased to exist is usually 404 / `null`.
  ARES `Vat = Ended` can lag behind ADIS; `VatRegistrationEnded` yields to an `Ok` payer.
- `RegistrationStatus::Unknown` = a value added by ARES after this version; an absent key = `Nonexistent`.
- ADIS and ARES give no VAT registration start/end date or history.

**VIES**: an invalid id is `valid === false`, not an exception. `name`/`address` are null where the member state does
not disclose them (Germany). `consultationNumber` only when a requester is passed. An empty requester string is
`InvalidInput` (no request): map empty config to `null` (Symfony `%env(default::VIES_REQUESTER)%`). `CompanyLookup`
takes `?VatId $viesRequester`: build it with `VatId::parse()` (a factory in Symfony), not from a raw config string.
With `TraderDetails` VIES compares each given field and answers per field in `*Match` (`null` = not returned). Many
member states, CZ and IE among them, always answer `NotProcessed`; ES hides name/address, so matching is the official
way to verify a Spanish trader.

## Identifiers

- IČO and DIČ are **strings, never int**: store IČO as `CHAR(8)`/`VARCHAR(8)`, edit with Symfony `TextType`, keep string
  DTO properties. A single id parameter is `CompanyId|string` / `VatId|string` (an int is a `TypeError` under
  `strict_types`, coerced to a string without it); an int or other type in an id list is `InvalidInput`. Cast a legacy
  INT column at the boundary (better: migrate it).
- Bulk results are collections, not arrays keyed by id: PHP turns digit-only keys into ints, and a non-normalised
  key would report an existing company as missing. Look up with `get()`/`has()` in any id form; find deleted or
  unknown ids with `$companies->missing($requestedIds)`. An invalid id there is `InvalidInput`, not "missing".
- `all()` and iteration follow ARES response order, not request order: each batch of 100 ascending by IČO, batches in
  request order, no global order.
- A string id is validated strictly (check digit included) everywhere; a `CompanyId` object is taken as it is.
  `$company->id` from ARES may fail the check digit (active subjects do: `00123562`), and one such string fails a whole
  `findMany()` / `byCompanyIds()` call before any request. Re-hydrate ids stored from ARES with `fromRegister()` (or
  keep the `CompanyId` object); `parse()` and plain strings are for user input only, never for a register id's string.
- ISIR `birthNumber` is a string as received, never normalised or validated; personal data.

## Exceptions (all implement `Exception\ExceptionInterface`)

| Exception | Meaning | Do |
|---|---|---|
| `InvalidInput` (`?string $errorCode`) | bad input or rejected by source | fix input / tell user; retrying is useless |
| `ServiceUnavailable` (`Source $source`, `?string $errorCode`) | could not answer: transport, timeout, outage, overload | retry later. NEVER "not a payer" / "invalid" |
| `InvalidResponse` (`Source $source`) | unreadable answer | investigate and report; message has key path and type only |

- Branch on `$e->errorCode` / `$e->source`, never parse messages. When `errorCode` is a token (`[A-Za-z0-9_.:-]{1,64}`)
  the message ends with ` (error code X)`; `errorCode` itself is raw (untrusted text).
- Messages never contain response text or record data: only your input id, the HTTP status and, for a transport
  failure, the HTTP client's error text.
- `Source`: `Ares`, `Adis`, `Vies`, `Isir`.
- Facade: only ARES errors propagate; `ServiceUnavailable`/`InvalidResponse` of a section become `Unavailable`,
  `InvalidInput` of a section becomes `Rejected`, both + `error()`. Requesting a section without its client is
  `\LogicException` before any request.
- ARES: 404 with `NENALEZENO` / `VYSTUP_SUBJEKT_NENALEZEN` -> `null` (`findMany()` leaves the id out); 400 ->
  `InvalidInput`, `errorCode` = `subKod`; any other non-200 (also 404 without those codes, 429, 5xx) ->
  `ServiceUnavailable`.
- ADIS: status 1 / unknown -> `InvalidResponse`; status 2 (nightly 0:00-0:10), 3, SOAP Fault -> `ServiceUnavailable`;
  a SOAP Fault keeps `faultcode` as `errorCode` with any HTTP status.
- VIES: HTTP 200 bodies with `errorWrappers` are errors (`INVALID_INPUT`, `INVALID_REQUESTER_INFO` -> `InvalidInput`;
  all other and unknown codes -> `ServiceUnavailable`), never `valid:false`. HTTP 400 -> `InvalidInput`, other non-200
  -> `ServiceUnavailable`, `errorCode` from the body when readable.
- ISIR: `WS2` -> empty collection; `WS4`, `SQL1`, `SERVER1`, SOAP Fault, HTTP != 200 -> `ServiceUnavailable` (code or
  `faultcode` as `errorCode`); `WS1`, `WS3`, unknown code, truncated answer, more than 100 proceedings (incomplete
  list, never silently cut) -> `InvalidResponse`.
- ARES search above 1 000 matches: `InvalidInput`, `errorCode` `VYSTUP_PRILIS_MNOHO_VYSLEDKU`; ask for a narrower query.

## Limits

100 ids per batch (chunked automatically, sent in waves of `maxConcurrency` batches; result order as if sequential; the
first failure in sending order is thrown, the rest of its wave is cancelled, no further wave is sent); search max 1 000
results (`limit` 1-1 000, a non-blank criterion required); ADIS down nightly 0:00-0:10; VIES and member states throttle
(`ServiceUnavailable`, retry later); ISIR has no bulk query; timeout 10 s default (more for `unreliablePayers()`).

Operator terms (not enforced by the library; a breach can get the IP blocked; all workers behind one IP count together):
ARES max 500 requests/min and no "larger number" of simultaneous requests (no figure published); ADIS max 4 parallel
requests, 2 000/hour, 10 000/24 h (one request = up to 100 DIČ), maintenance Sunday 3:00-4:00; VIES global and
per-member-state concurrency caps shared by all users (thresholds not published); ISIR none published, but the ministry
runs the successor eISIR in verification operation and has announced a change of the web services without a date (the
client sits behind `InsolvencyRegister`). The library counts no requests; never fan out unbounded.

## What the library does not do

No caching, retrying, rate limiting, scheduling or persistence; concurrency only inside one `findMany()`, bounded by
`maxConcurrency`, never across calls — the project does the rest (cache profiles, retry `ServiceUnavailable` with
back-off, queue bulk work). No IBAN check-digit validation.

For change tracking snapshot the DTOs (`$profile->company`, `->vat`, `->vies`, `->insolvencies`), not the whole profile:
`serialize($profile)` can fail on stored exceptions. `json_encode($profile)` works.

## Typical code

```php
$profile = $lookup->byCompanyId($ico, Section::Vat, Section::Insolvency);   // null: not in ARES (unknown or deleted)
$payer = $profile->isVatPayer();                       // true / false / null (unknown, retry)
$insolvent = $profile->isInInsolvency();               // true / false / null, the same way
if (!$profile->isComplete()) { /* a section Unavailable/Rejected or Insolvency unasked: do not trust absent flags */ }

$requested = array_map(CompanyId::fromRegister(...), $icosFromDb);   // stored from $company->id; parse() is for user input only
$companies = $ares->findMany($requested);
foreach ($companies->missing($requested) as $id) { /* not in ARES any more */ }

$profiles = $lookup->byCompanyIds($requested, Section::Vat);   // CompanyProfiles, never an array keyed by IČO
```

## Testing

Depend on `CompanyDirectory`, `VatRegister`, `Vies`, `InsolvencyRegister` and double them. Build collections with
`new Companies([$company, ...])` / `new VatSubjects([...])` / `new CompanyProfiles([...])` (every company needs an IČO,
ids unique, else `InvalidInput`) and `new InsolvencyProceedings([...], synchronisedAt: null)`; build the DTOs with
named constructor arguments. `Company` has no defaults: pass all 17, `registrations: new
Registrations([AresRegister::Vat->value => RegistrationStatus::Active])`; every `Address` argument is optional;
`CompanyProfile`: pass `insolvencies:` (no default), `statuses`/`errors` keyed by `Section::name`. Production URLs:
`ENDPOINT` of each client. To test the clients, pass a `Symfony\Component\HttpClient\MockHttpClient`. The VIES test
service (`https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service`, numbers `100`-`601`, e.g.
`DE100`) works through the `$endpoint` argument. Do not call the live registers from unit tests.
