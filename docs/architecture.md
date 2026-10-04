# Architecture

## Source layout

```
src/
  CompanyLookup  CompanyProfile  Section  SectionStatus  RiskFlag      facade
  CompanyId  VatId  Address  Source                                   common types
  Exception/   ExceptionInterface  InvalidInput  ServiceUnavailable  InvalidResponse
  Ares/        CompanyDirectory (interface)  AresClient  Company  Companies  Registrations  AresRegister
               RegistrationStatus  CompanySearch  CompanySearchResult  Internal/CompanyMapper
  Adis/        VatRegister (interface)  VatRegisterClient  VatSubject  VatSubjects  SubjectType  BankAccount
               UnreliablePayer  Internal/ResponseParser  Internal/BankAccountNumber
  Vies/        Vies (interface)  ViesClient  ViesResult
  Internal/    JsonReader  XmlReader  Dates  ListElement              @internal, not public API
tests/         mirrors src/; Fixtures/{Ares,Adis,Vies}; Double/ (test helpers); Live/ (live suite)
```

Every client implements a public interface (`CompanyDirectory`, `VatRegister`, `Vies`); consumers depend on the
interface and use it for test doubles.

## Classes and state

- All classes are `final`. DTOs are `readonly` with public properties only (scalars, enums, `DateTimeImmutable`,
  nested DTOs), so consumers can `json_encode` and compare them.
- Clients are stateless `final readonly` classes, safe in long-running workers. `HttpClientInterface`, endpoint and
  timeout come through the constructor; request options are set per request.
- Request building and response parsing are separate: ARES `Internal/CompanyMapper` plus private body builders; ADIS
  `Internal/ResponseParser` plus private envelope builders; VIES private methods of `ViesClient`.

## HTTP handling

- Every client calls `request(..., ['timeout' => t, 'max_duration' => t])`, then inside one `try` reads
  `getStatusCode()` and `getContent(false)`. Only `TransportExceptionInterface` becomes `ServiceUnavailable`.
- The status mapping is explicit per client. Error bodies of non-200 responses are read leniently
  (`JsonReader::tryFromJson`, an `InvalidResponse` is swallowed): the HTTP status decides the exception, the body only
  supplies an `errorCode`.
- ADIS is SOAP 1.1 over plain HTTP POST without `ext-soap`: the envelope is built by hand and parsed through
  `Internal/XmlReader`.

## Bulk calls and collections

- `findMany()` dedupes, chunks by 100 (ARES rejects 101+ ids, ADIS answers status code 1) and merges. An empty list
  makes no request. All ids are validated before the first request.
- A list element that is neither the id object nor a string is an `InvalidInput` naming the index or key and the
  expected type (`Internal/ListElement`, shared by ARES and ADIS). String-keyed input arrays are accepted.
- Bulk results are the collections `Ares\Companies` and `Adis\VatSubjects`: values-only `IteratorAggregate`,
  `Countable`. `get()` / `has()` normalise the lookup id through `CompanyId::parse()` / `VatId::parse($id, 'CZ')`;
  `missing()` lists requested ids that are absent; `all()` returns the list. A company without IČO or a duplicate key in
  the constructor is an `InvalidInput`; a duplicate subject in a response is an `InvalidResponse`.
- No `toArray()`, `keys()` or `ArrayAccess`: PHP casts digit-only array keys to `int`, so a keyed array would make a
  non-normalised lookup report an existing company as missing.

## Facade

- `CompanyLookup::byCompanyId($id, Section ...$sections)` calls ARES first; `null` from ARES returns `null`, an ARES
  exception propagates. A requested section without its client is a `\LogicException` before any request.
- Each section is asked once, in the order requested. A section's `ServiceUnavailable` or `InvalidResponse` becomes
  `SectionStatus::Unavailable` with the exception stored; `InvalidInput` propagates.
- ADIS and VIES are asked under `Company::vatLookupId()` (`groupVatId ?? vatId`): a VAT group member has no DIČ of its
  own. A DIČ is never derived from an IČO. ADIS, not ARES, decides VAT payer status.
- `CompanyProfile`: `status()`, `error()`, `isComplete()`, `flags()`, `hasFlag()`. Flags are computed only from
  sections in status `Ok` plus the ARES base; an absent flag means "clean" only on a complete profile with the section
  requested.
- Shortcuts `isVatPayer()` and `hasPublishedAccount()` return `?bool`: `true`/`false` from an `Ok` section, `false` for
  `NotFound` and `NotApplicable`, `null` for `Unavailable` (unknown, never "not a payer"), `\LogicException` for
  `NotRequested`. No further `VatSubject` API is delegated onto the profile.
- `SectionStatus` is string-backed; its values are part of the JSON form of a profile and must stay stable.
- `RiskFlag::InLiquidation` matches the name with `/v\s+likvidaci["'\s.]*$/iu`; the phrase before the legal form is
  not matched. `RiskFlag::InsolvencyRecord` comes from ARES `Insolvency = Active`, which can be a closed proceeding.
- A profile with stored exceptions is not guaranteed to be `serialize()`-able; consumers snapshot the DTOs.
