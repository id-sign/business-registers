# Architecture

## Source layout

```
src/
  CompanyLookup  CompanyProfile  CompanyProfiles  Section  SectionStatus  RiskFlag   facade
  CompanyId  VatId  Address  Source                                                  common types
  Exception/   ExceptionInterface  InvalidInput  ServiceUnavailable  InvalidResponse
  Ares/        CompanyDirectory (interface)  AresClient  Company  Companies  Registrations  AresRegister
               RegistrationStatus  CompanySearch  CompanySearchResult  Internal/CompanyMapper
  Adis/        VatRegister (interface)  VatRegisterClient  VatSubject  VatSubjects  SubjectType  BankAccount
               UnreliablePayer  Internal/ResponseParser  Internal/BankAccountNumber
  Vies/        Vies (interface)  ViesClient  ViesResult  TraderDetails  MatchResult
  Isir/        InsolvencyRegister (interface)  InsolvencyClient  InsolvencyProceedings  InsolvencyProceeding
               Internal/ResponseParser
  Internal/    HttpTransport  JsonReader  XmlReader  Dates  ListElement  Identifiers @internal, not public API
tests/         mirrors src/; Fixtures/{Ares,Adis,Vies,Isir}; Double/ (test helpers); Live/ (live suite)
```

Every client implements a public interface (`CompanyDirectory`, `VatRegister`, `Vies`, `InsolvencyRegister`);
consumers depend on the interface and use it for test doubles.

## Classes and state

- All classes are `final`. DTOs are `readonly` with public properties only (scalars, enums, `DateTimeImmutable`,
  nested DTOs), so consumers can `json_encode` and compare them.
- Clients are stateless `final readonly` classes, safe in long-running workers. `HttpClientInterface`, endpoint,
  timeout and, for ARES and ADIS, `maxConcurrency` come through the constructor; request options are set per request.
- Request building and response parsing are separate: ARES `Internal/CompanyMapper` plus private body builders; ADIS
  and ISIR `Internal/ResponseParser` plus private envelope builders; VIES private methods of `ViesClient`.
- Ids read from a response are built with `CompanyId::fromRegister()` (format only), because ARES lists active
  subjects whose IČO fails the check digit; ids the caller passes as strings go through the strict `parse()`.
- A VAT id read from an ARES response (`dic`, `dicSkDph`) goes through the strict `VatId::parse($value, 'CZ')`, and a
  value it rejects is an `InvalidResponse` for the whole response. The check is the format alone, never a check
  digit: 8–10 digits after a `CZ` prefix or without a prefix, the generic pattern after another two-letter prefix. A
  value with another prefix is read as that country's VAT id; the facade does not send it to ADIS (section `Vat`
  `Rejected`) but does send it to VIES. A rejected value is no VAT id ADIS or VIES could be asked with. No such value
  from ARES is known; a tolerant `VatId::fromRegister()` waits for a real example that shows what to accept.

## Address

`Address` has one meaning for every source that fills it; `Internal/StreetLine` holds the street-line rule.

| Field                                            | Meaning                                                              |
|--------------------------------------------------|----------------------------------------------------------------------|
| `street`                                         | street name, or a place name without one, and the numbers if any: "Duhová 1444/2", "Libotenice 153" |
| `streetName`, `houseNumber`, `orientationNumber` | the parts of `street`, filled only from a shape that splits unambiguously |
| `houseNumberType`                                | 1 descriptive (popisné), 2 registration (evidenční), 3 substitute    |
| `postalCode`                                     | five digits without a space                                          |
| `district`, `cityDistrict`, `city`, `county`, `region`, `countryName` | as the source writes them       |
| `text`, `countryCode`, `addressPointId`, `municipalityCode` | ARES only                                 |

- ARES fills every field from its structured address and composes `street`.
- ARES composes `street` from the street name, else the part of the municipality, else the municipality.
- ADIS sends the street line `uliceCislo`; its trailing numbers are split off, and a line without a street name
  ("153") is completed with `castObce`, else `mesto` ("LIBOTENICE 153"). A name ending with a dot is a number label
  ("č.p. 153") and is not split. ADIS writes names in upper case, and `city` can be a city district ("PRAHA 4").
- ISIR sends `ulice` and `cisloPopisne` apart; `street` is composed from the two, `houseNumberType` is 1 for a `čp.`
  prefix, the space in `psc` is removed. `ulice` is the village name where there are no streets ("Libotenice", where
  ARES has `district`), and `city` can name a city district ("Praha 5").
- Splitting accepts a trailing `123`, `123/4` or `123/4a` in ASCII digits (ISIR also `čp.123`); any other shape
  (e.g. ISIR `332E`) stays in `street` only, the parts `null`. Names are not re-cased and "Praha 4" is not split: both
  would be guesses.
- VIES returns the address as free text per member state (`ViesResult::$address`), not as an `Address`.

## HTTP handling

- Every client sends through its own `Internal/HttpTransport` (one per source, built in the client constructor).
  `send()` issues the request with `timeout` and `max_duration` set to the client timeout and returns the unread
  response; `read()` returns the status and `getContent(false)` for any status; `exchange()` is both. Only
  `TransportExceptionInterface`, at request or read time, becomes `ServiceUnavailable` of that source with the
  transport exception as `previous`.
- `sendInWaves()` sends at most `maxConcurrency` requests, then reads and consumes them in sending order before the next
  wave. On the first failure of a send, a read or the consumer it cancels every unread response of the wave, sends no
  further wave and rethrows; an unread response with an error status would otherwise throw from its destructor.
- Whether a wave really runs in parallel depends on the `HttpClientInterface` implementation: `CurlHttpClient`, which
  `HttpClient::create()` usually returns when `ext-curl` is loaded, does; `NativeHttpClient`, its fallback without
  `ext-curl`, opens each request synchronously, so waves bring no speed-up there.
- The status mapping is explicit per client; the transport never interprets a status. Error bodies of non-200 responses
  are read leniently (`JsonReader::tryFromJson` for ARES and VIES, `Adis\Internal\ResponseParser::faultCode` and
  `Isir\Internal\ResponseParser::faultCode` over `XmlReader` for ADIS and ISIR; an `InvalidResponse` is swallowed):
  the HTTP status decides the exception, the body only supplies an `errorCode`.
- ADIS and ISIR are SOAP 1.1 over plain HTTP POST without `ext-soap`: the envelope is built by hand and parsed
  through `Internal/XmlReader`. Each source has its own parser and its own lenient `faultCode()`; a shared SOAP helper
  would need the source and the prefix map passed in and save a few lines.
- ISIR has no bulk query and no published limits: `InsolvencyClient::find()` sends one request with
  `filtrAktualniRizeni=F` (the service's "current only" filter hides only some ended states, so the library asks for
  everything and decides "ongoing" itself in `InsolvencyProceeding::isOngoing()`) and `maxPocetVysledku=101`. The
  service caps distinct proceedings at that number and returns every debtor row of each, so a list cut at 101
  proceedings always has more than 100 rows: more than 100 distinct proceedings (by `reference()`) is
  `InvalidResponse`, while a complete answer of up to 100 proceedings is accepted whatever its number of rows. The
  response order is undocumented. The request children are
  unqualified and their order is fixed by the XSD; another order is a SOAP Fault.

## Bulk calls and collections

- `findMany()` dedupes, chunks by 100 (ARES rejects 101+ ids, ADIS answers status code 1) and merges. An empty list
  makes no request. All ids are validated before the first request.
- The batches of one `findMany()` call go out in waves of `maxConcurrency` (constructor, 1–4, else
  `\InvalidArgumentException`; ARES default 2, ADIS default 4, the ADIS operator cap of 4 parallel requests per source
  IP). Results keep the sequential order; the first failure in sending order is thrown with the same exception as on
  the sequential path, and no later wave is sent. Concurrency exists only inside one `findMany()`, never across calls.
  The timeout applies per request and includes the time a request waits queued at the source; with a shared IP or a
  tight timeout, lower `maxConcurrency`. The library does not count requests per minute, hour or day.
- A list element that is neither the id object nor a string is an `InvalidInput` naming the index or key and the
  expected type (`Internal/ListElement`, shared by ARES and ADIS). String-keyed input arrays are accepted.
- Bulk results are the collections `Ares\Companies`, `Adis\VatSubjects` and `CompanyProfiles` (facade):
  values-only `IteratorAggregate`, `Countable`. `get()` / `has()` normalise a string lookup id strictly through
  `CompanyId::parse()` / `VatId::parse($id, 'CZ')` and take an id object as it is (`Internal/Identifiers`, shared with
  the clients), so a register id that fails the check digit is found; `missing()` lists requested ids that are absent;
  `all()` returns the list. A company without IČO or a duplicate key in the constructor is an `InvalidInput`; a
  duplicate subject in a response is an `InvalidResponse`.
- No `toArray()`, `keys()` or `ArrayAccess`: PHP casts digit-only array keys to `int`, so a keyed array would make a
  non-normalised lookup report an existing company as missing.

## Facade

- `CompanyLookup::byCompanyId($id, Section ...$sections)` calls ARES first; `null` from ARES returns `null`, an ARES
  exception propagates. A requested section without its client is a `\LogicException` before any request.
- Each section is asked once, in the order requested. A section's `ServiceUnavailable` or `InvalidResponse` becomes
  `SectionStatus::Unavailable`, its `InvalidInput` becomes `SectionStatus::Rejected` (retrying will not help); both
  store the exception for `error()`. Only ARES exceptions propagate.
- ADIS and VIES are asked under `Company::vatLookupId()` (`groupVatId ?? vatId`): ADIS answers for a VAT group member
  under the group VAT id; in rare cases it still answers the member's own VAT id (which ARES may carry as a former one)
  as a VAT payer too, and the facade asks the group only. A DIČ is never derived from an IČO. ADIS, not ARES, decides
  VAT payer status.
- Each section decides `NotApplicable` on the id it is asked under, and its client is then not asked: `Vat` and `Vies`
  without `Company::vatLookupId()`, `Insolvency` (ISIR, keyed by IČO) without `Company::$id`. A company without a DIČ
  therefore still gets an `Insolvency` answer. `Insolvency` is `Ok` also with an empty collection; the lookup never
  sets `NotFound` for it.
- `CompanyProfile`: `status()`, `error()`, `isComplete()` (no section `Unavailable` or `Rejected`, and `Insolvency`
  not `NotApplicable`, whose answer is unknown), `flags()`,
  `hasFlag()`. Flags are computed only from sections in status `Ok` plus the ARES base; an absent flag means "clean"
  only on a complete profile with the section requested.
- Shortcuts `isVatPayer()` and `hasPublishedAccount()` return `?bool`: `true`/`false` from an `Ok` section, `false` for
  `NotFound` and `NotApplicable`, `null` for `Unavailable` and `Rejected` (unknown, never "not a payer"),
  `\LogicException` for `NotRequested`. No further `VatSubject` API is delegated onto the profile. `isInInsolvency()`
  follows the same pattern over `Section::Insolvency` with one difference: `InsolvencyProceedings::hasOngoing()` for
  `Ok`, `false` for `NotFound`, `null` for `Unavailable`, `Rejected` and `NotApplicable` (a subject without an IČO
  can still be listed under its birth number, so an unasked register is no negative answer), `\LogicException` for
  `NotRequested`. ISIR is asked directly from `profile()`, one `find()` per company; it has no state to share.
- `SectionStatus` is string-backed; its values are part of the JSON form of a profile and must stay stable.
- `RiskFlag::InLiquidation` matches the phrase `v\s+likvidaci` (`/iu`) anywhere in the name, provided the character on
  each side is absent or one of whitespace, a straight or typographic quote (`"'„“”‘’‚‛‟«»‹›`), the ARES quote
  substitutes `´` and `` ` ``, `,`, `.`, `(`, `)`, `/` or a dash (`\p{Pd}`); "vlikvidaci" and "Kov likvidaci" stay
  unmatched. `RiskFlag::InsolvencyRecord` comes from ARES `Insolvency = Active`, which can be a closed proceeding;
  `RiskFlag::Insolvency` comes from an `Ok` section `Insolvency` with at least one proceeding
  `InsolvencyProceeding::isOngoing()` reports as ongoing (a filed petition included). The two are independent.
- `RiskFlag::Ceased` is `Company::hasCeased()`: `ceasedOn` not after today at midnight Europe/Prague;
  `ceasedOn` is ARES `datumZaniku` (zánik, not zrušení). `hasCeased()` is the only place the library reads the
  clock; the optional `$on` keeps tests deterministic. `UnreliableVatPayer` is `nespolehlivyPlatce` on a payer or
  group (`isVatPayer()`); `UnreliablePerson` is type `UnreliablePerson` or `nespolehlivyPlatce` on an identified
  person. `VatRegistrationEnded` is ARES-derived and yields only to an `Ok` section `Vat` whose subject
  `isVatPayer()`.
- A profile with stored exceptions is not guaranteed to be `serialize()`-able; consumers snapshot the DTOs.
- `CompanyLookup::byCompanyIds($ids, Section ...$sections)` returns `CompanyProfiles` in the order of the `Companies`
  ARES returns; ids ARES does not hold are absent (`missing()`). Before any request it checks the section clients and
  every id (`Internal/ListElement`, then `Internal/Identifiers`: strings strict, `CompanyId` as it is). ARES is one
  `findMany()`; section `Vat` is one `VatRegister::findMany()` over the distinct Czech `Company::vatLookupId()` values;
  `Vies` is one `check()` per distinct `Company::vatLookupId()`, sequentially, memoised for the call (result or
  exception shared by the companies with that lookup id); after an `InvalidInput` with `errorCode`
  `INVALID_REQUESTER_INFO` no further `check()` is sent and the remaining lookup ids get that same exception.
  `Insolvency` is one `InsolvencyRegister::find()` per company, sequentially in `Companies` order, not memoised (ARES
  de-duplicates the companies) and without a short-circuit: a failure affects only that company. The facade does not
  chunk; the clients do.
- The statuses follow `byCompanyId()` through one shared per-company step: no id for the section → `NotApplicable`;
  absent from the ADIS answer → `NotFound`. The ADIS call's `ServiceUnavailable` / `InvalidResponse` makes `Vat`
  `Unavailable`, its `InvalidInput` makes it `Rejected`, for every profile in the call; a non-Czech lookup id is not
  sent and makes that profile's `Vat` `Rejected`. A VIES failure affects only the companies with that lookup id, except a requester
  rejection, which stops VIES for the rest of the call. Only ARES exceptions propagate.
