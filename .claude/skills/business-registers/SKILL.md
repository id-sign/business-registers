---
name: business-registers
description: Typed PHP client for Czech business registers (ARES, VAT register ADIS, VIES). Use in a PHP project with id-sign/business-registers when looking up a Czech company by IČO, validating IČO or DIČ, checking VAT payer status, unreliable payer, published bank accounts, or EU VAT id validity (VIES), or when writing tests for code that uses the library.
---

# business-registers (id-sign/business-registers v0.1.0)

Stateless PHP 8.4+ library. It covers ARES (company identity), the VAT register ADIS (VAT payer, unreliable payer,
published bank accounts) and VIES (EU VAT id validity). Register extracts, the insolvency register (ISIR) and ARES
code lists are not available yet.

## Setup

Install `id-sign/business-registers` and an HTTP client (`symfony/http-client`) with Composer. It needs `ext-dom`;
any `symfony/http-client-contracts` implementation works.

```php
$http = Symfony\Component\HttpClient\HttpClient::create();
$ares = new IdSign\BusinessRegisters\Ares\AresClient($http);            // implements CompanyDirectory
$vat = new IdSign\BusinessRegisters\Adis\VatRegisterClient($http);      // implements VatRegister
$vies = new IdSign\BusinessRegisters\Vies\ViesClient($http);            // implements Vies
$lookup = new IdSign\BusinessRegisters\CompanyLookup($ares, $vat, $vies, viesRequester: null);
```

Clients take optional `string $endpoint` and `float $timeout = 10.0`. In Symfony register the clients and bind
each interface to its client in `services.yaml` (`CompanyDirectory`, `VatRegister`, `Vies`, `CompanyLookup: ~`).
Inject the interfaces, not the clients.

## Facade or single client

- Facade `CompanyLookup`: a profile for one IČO (ARES plus optional ADIS and VIES sections, risk flags). Use it for
  "everything about this company" with tolerance to a partial outage.
- Single client: bulk checks (`findMany`), search, the list of unreliable payers, a bank account check without ARES,
  VIES for a foreign VAT id.

## API (namespace `IdSign\BusinessRegisters`)

```php
// CompanyLookup
byCompanyId(CompanyId|string $id, Section ...$sections): ?CompanyProfile   // Section::Vat, Section::Vies

// CompanyProfile (readonly): company, vat, vies, statuses, errors
$profile->company;                      // Ares\Company
$profile->vat;                          // ?Adis\VatSubject
$profile->vies;                         // ?Vies\ViesResult
$profile->status(Section $s): SectionStatus
$profile->error(Section $s): ?ExceptionInterface
$profile->isComplete(): bool            // no requested section is Unavailable
$profile->flags(): list<RiskFlag>       // enum order
$profile->hasFlag(RiskFlag $f): bool
$profile->isVatPayer(): ?bool
$profile->hasPublishedAccount(string $account): ?bool

// Ares\CompanyDirectory
find(CompanyId|string $id): ?Company
findMany(array $ids): Ares\Companies    // list<CompanyId|string>
search(Ares\CompanySearch $q): Ares\CompanySearchResult   // ->total, ->companies (list<Company>)
new CompanySearch(name:, address:, municipalityCode:, legalFormCodes:, naceCodes:, taxOfficeCodes:, limit: 20, offset: 0, orderBy: [])

// Ares\Company (readonly): aresId, id (?CompanyId), name, legalFormCode, vatId (?VatId), groupVatId (?VatId),
// taxOfficeCode, seat (?Address), deliveryAddressLines, establishedOn, dissolvedOn, updatedOn (?DateTimeImmutable),
// naceCodes, naceCodes2008, fileNumber, primarySource, registrations
$company->vatLookupId(): ?VatId          // groupVatId ?? vatId: the ONLY id to send to ADIS and VIES
$company->isNaturalPerson(): bool        // legal forms 101-108
$company->registrations->status(Ares\AresRegister $r): Ares\RegistrationStatus   // also active(), isActive($r)

// Ares\Companies and Adis\VatSubjects (readonly, IteratorAggregate over values, Countable)
get($id)    has($id): bool    all(): list<Company|VatSubject>    count()
missing(array $requested): list<CompanyId> | list<VatId>      // requested ids that are absent

// Adis\VatRegister
find(VatId|string $vatId): ?VatSubject      // string without country = CZ; non-CZ -> InvalidInput
findMany(array $vatIds): Adis\VatSubjects
unreliablePayers(): list<Adis\UnreliablePayer>   // vatId, since, taxOfficeCode; ~4 300 entries, 500 kB

// Adis\VatSubject (readonly): vatId, type (SubjectType), unreliable, unreliableSince, taxOfficeCode, name, address,
// bankAccounts (all, incl. ended), checkedAt
$s->isVatPayer(): bool                   // VatPayer or VatGroup
$s->activeBankAccounts(): list<BankAccount>      // BankAccount: prefix, number, bankCode, publishedFrom, publishedUntil
$s->hasPublishedAccount(string $account): bool   // active accounts only; domestic forms and Czech IBAN; garbage = false

// Vies\Vies
check(VatId|string $vatId, VatId|string|null $requester = null): Vies\ViesResult
// ViesResult: vatId, valid, name?, address?, consultationNumber?, checkedAt (UTC)

// Value types
CompanyId::parse(string): CompanyId        // ' 452 746 49 ', '64581' -> '00064581'; checksum; InvalidInput
CompanyId::tryParse(string): ?CompanyId    // ->value (8 digits), equals(), (string)
VatId::parse(string, ?string $defaultCountry = null): VatId   // ->countryCode, ->number, isCzech(), equals(), (string) 'CZ45274649'; GR -> EL
Address: text, street, streetName, houseNumber, houseNumberType, orientationNumber, district, cityDistrict, city,
         postalCode, county, region, countryCode, countryName, addressPointId, municipalityCode; postalCodeFormatted()
```

## Interpreting the data

**Four meanings of a `null` section (`$profile->vat`, `$profile->vies`)** — call `status()`:

| `SectionStatus` (string value) | Meaning |
|---|---|
| `NotRequested` (`not_requested`) | section not passed to `byCompanyId()` |
| `NotFound` (`not_found`) | source does not hold the subject |
| `NotApplicable` (`not_applicable`) | subject has no VAT id |
| `Unavailable` (`unavailable`) | source could not answer: UNKNOWN; `error()` has the exception |
| `Ok` (`ok`) | the section property is filled |

**`isComplete()` before flags.** Flags come from ARES and from `Ok` sections only. No flag means "clean" only if
`isComplete()` is true and the section behind the flag was requested.

| `RiskFlag` | Condition | Section |
|---|---|---|
| `Dissolved` | ARES has a dissolution date | |
| `InLiquidation` | name ends with "v likvidaci" (quotes, trailing dot, extra whitespace tolerated); not before the legal form | |
| `InsolvencyRecord` | ARES lists an insolvency record, possibly closed; not proof of current insolvency | |
| `UnreliableVatPayer` | ADIS: unreliable payer | Vat |
| `UnreliablePerson` | ADIS: unreliable person | Vat |
| `VatRegistrationEnded` | ARES VAT registration Dissolved/Historical and not in an active VAT group | |
| `NoPublishedBankAccount` | payer or VAT group without active published account | Vat |
| `ViesInvalid` | VIES says invalid | Vies |

**Shortcuts `isVatPayer()` / `hasPublishedAccount()`** read `Section::Vat`:

| `status(Vat)` | Result |
|---|---|
| `Ok` | `true` / `false` from ADIS |
| `NotFound`, `NotApplicable` | `false` (definitive) |
| `Unavailable` | `null` = unknown, retry later. NEVER treat as "not a payer" or "account not published" |
| `NotRequested` | `\LogicException` (pass `Section::Vat`) |

**ARES is a pointer, not an answer.**
- A filled `vatId` does not make a VAT payer; ADIS decides (`SubjectType::VatPayer` / `VatGroup`). ARES `Vat = Active`
  also covers identified persons.
- A VAT group member has `vatId === null` and a `groupVatId`; ask ADIS/VIES with `vatLookupId()`.
- Never derive a DIČ from an IČO. Natural persons have ten-digit DIČ.
- `Insolvency = Active` can be a closed proceeding; `Bankruptcy` (CEÚ) is useless for insolvency.
- 404 / `null` = "not in ARES", also for deleted subjects. A subject without IČO has `id === null` and an
  `aresId` like `ARES_########`.
- `RegistrationStatus::Unknown` = a value added by ARES after this version; an absent key = `Nonexistent`.
- ADIS and ARES give no VAT registration start/end date or history.

**VIES**: an invalid id is `valid === false`, not an exception. `name`/`address` are null where the member state
does not disclose them (Germany). `consultationNumber` only when a requester is passed. An empty-string requester is
`InvalidInput` (no request); map empty config to `null` (Symfony `%env(default::VIES_REQUESTER)%`).

## Identifiers

- IČO and DIČ are **strings, never int**: store IČO as `CHAR(8)`/`VARCHAR(8)`, edit with Symfony `TextType`, keep string
  DTO properties. The library accepts `CompanyId|string` / `VatId|string` only; an int or other type in an id list is
  `InvalidInput`. Cast a legacy INT column at the boundary (better: migrate it).
- Bulk results are collections, not arrays keyed by id: PHP turns digit-only keys into ints, and a non-normalised
  key would report an existing company as missing. Look up with `get()`/`has()` in any id form; find deleted or
  unknown ids with `$companies->missing($requestedIds)`. An invalid id there is `InvalidInput`, not "missing".
- `all()` and iteration follow ARES response order, not request order.

## Exceptions (all implement `Exception\ExceptionInterface`)

| Exception | Meaning | Do |
|---|---|---|
| `InvalidInput` (`?string $errorCode`) | bad input or rejected by source | fix input / tell user; retrying is useless |
| `ServiceUnavailable` (`Source $source`, `?string $errorCode`) | could not answer: transport, timeout, outage, overload | retry later. NEVER "not a payer" / "invalid" |
| `InvalidResponse` (`Source $source`) | unreadable answer | investigate and report; message has key path and type only |

- Branch on `$e->errorCode` / `$e->source`, never parse messages. When `errorCode` is a token (`[A-Za-z0-9_.:-]{1,64}`)
  the message ends with ` (error code X)`; `errorCode` itself is raw (untrusted text).
- Messages never contain response text or record data, only your input id and the HTTP status.
- Facade: ARES errors and `InvalidInput` propagate; `ServiceUnavailable`/`InvalidResponse` of a section become
  `Unavailable` + `error()`. Requesting a section without its client is `\LogicException` before any request.
- ADIS: status 1 / unknown -> `InvalidResponse`; status 2 (nightly 0:00-0:10), 3, SOAP Fault -> `ServiceUnavailable`.
- VIES: HTTP 200 bodies with `errorWrappers` are errors (`INVALID_INPUT`, `INVALID_REQUESTER_INFO` -> `InvalidInput`;
  all other and unknown codes -> `ServiceUnavailable`), never `valid:false`.
- ARES search above 1 000 matches: `InvalidInput`, `errorCode` `VYSTUP_PRILIS_MNOHO_VYSLEDKU`; ask for a narrower query.

## Limits

100 ids per batch (chunked automatically); search max 1 000 results (`limit` 1-1 000, at least one non-blank
criterion); ADIS down nightly 0:00-0:10; VIES and member states throttle (`ServiceUnavailable`, retry later);
timeout 10 s default (use more for `unreliablePayers()`).

## What the library does not do

No caching, retrying, rate limiting, scheduling, persistence or concurrent queries — the project does that
(cache profiles, retry `ServiceUnavailable` with back-off, queue bulk work). No IBAN check-digit validation. No
extracts of the public/trade register, no insolvency register, no code lists yet.

For change tracking snapshot the DTOs (`$profile->company`, `->vat`, `->vies`), not the whole profile:
`serialize($profile)` can fail on stored exceptions. `json_encode($profile)` works.

## Typical code

```php
$profile = $lookup->byCompanyId($ico, Section::Vat, Section::Vies);   // null: not in ARES
if (null === $profile) { /* unknown or deleted company */ }
$payer = $profile->isVatPayer();                       // true / false / null (unknown, retry)
if (!$profile->isComplete()) { /* some section unavailable: do not trust absent flags */ }

$companies = $ares->findMany($icosFromDb);             // list<string>
foreach ($companies->missing($icosFromDb) as $id) { /* not in ARES any more */ }
```

## Testing

Depend on `CompanyDirectory`, `VatRegister`, `Vies` and double them. Build collections with
`new Companies([$company, ...])` / `new VatSubjects([...])` (every company needs an IČO, ids unique, else
`InvalidInput`); build `Company`, `VatSubject`, `ViesResult` and `CompanyProfile` with named constructor arguments
(`CompanyProfile`: `statuses`/`errors` keyed by `Section::name`). To test the clients, pass a
`Symfony\Component\HttpClient\MockHttpClient`. The VIES test service
(`https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-test-service`, numbers `100`-`601`, e.g. `DE100`)
works through the `$endpoint` argument. Do not call the live registers from unit tests.
