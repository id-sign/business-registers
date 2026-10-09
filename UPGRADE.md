# Upgrade guide

Changes you have to make in your code when moving to a newer version. Newest version first. Additive changes are
not listed.

## v0.2.0

ARES `datumZaniku` is the end of a subject's existence or of its registration (zánik), not its dissolution
(zrušení). The names that said "dissolved" are renamed; behaviour is unchanged.

| Before                          | After                       |
|---------------------------------|-----------------------------|
| `Company::$dissolvedOn`         | `Company::$ceasedOn`        |
| `Company::isDissolved()`        | `Company::hasCeased()`      |
| `RiskFlag::Dissolved`           | `RiskFlag::Ceased`          |
| `RegistrationStatus::Dissolved` | `RegistrationStatus::Ended` |

- The JSON form of a `Company` has the key `ceasedOn` instead of `dissolvedOn`. Re-map stored snapshots, or rebuild
  them from ARES.
- `RegistrationStatus::Ended` keeps the value `ZANIKLY`, so stored status values stay valid.
- Code that builds a `Company` with named arguments passes `ceasedOn:`.

The facade gains the insolvency register section, which reorders two constructors:

- `CompanyLookup::__construct()` takes `?InsolvencyRegister $insolvencyRegister` as the 4th parameter; `$viesRequester`
  moves from the 4th to the 5th position. A positional requester (`new CompanyLookup($ares, $vat, $vies, $requester)`)
  no longer type-checks: pass it as `viesRequester: $requester`, or pass `null` or an `InsolvencyClient` as the 4th
  argument. Named callers need no change.
- `CompanyProfile::__construct()` takes `?InsolvencyProceedings $insolvencies` as the new 4th parameter, without a
  default, before `$statuses`. Code that builds profiles (e.g. in tests) passes `insolvencies: null`.
- The JSON form of a `CompanyProfile` has the new key `insolvencies` (`null` or the proceedings), and `flags()` can
  contain `RiskFlag::Insolvency`, placed right after `RiskFlag::InsolvencyRecord`.
- `Section` has the new case `Section::Insolvency`. A `match` over all `Section` cases needs an arm for it.
- `CompanyLookup::byCompanyIds()` with `Section::Vies` stops asking VIES after two connection failures in a row
  (timeout, refused or blocked connection). The companies not yet checked get `Vies` `Unavailable` with their own
  `ServiceUnavailable` (`connectionFailed` true) instead of each waiting for its own timeout. Retry them as any other
  `Unavailable` section.
- `CompanyProfile::flags()` and `hasFlag()` throw `\LogicException` for a profile whose `Vat` or `Insolvency` section
  is `Ok` but holds no data, as `isVatPayer()` and `isInInsolvency()` do; they returned the flag as absent before.
  Code that builds profiles in tests passes the subject or the proceedings with an `Ok` status.

`InvalidResponse` carries the source's error code, as `InvalidInput` and `ServiceUnavailable` do:

- `InvalidResponse::__construct()` takes `?string $errorCode` as the 3rd parameter; `$previous` moves from the 3rd to
  the 4th position. A positional previous exception (`new InvalidResponse($message, $source, $e)`) throws a
  `TypeError` only under `declare(strict_types=1)` (static analysis reports it); without strict types PHP stores the
  exception's string as `errorCode` and drops `previous`. Search for `new InvalidResponse(` with three positional
  arguments and pass it as `previous: $e`.
- ADIS `statusCode` 1 or an unknown code and ISIR `kodChyby` `WS1`, `WS3` or an unknown code set `errorCode` and end
  the message with ` (error code X)`.
- The JSON form of a stored `InvalidResponse` in `CompanyProfile` `errors` has the new key `errorCode`.

ADIS addresses follow the address format shared with ARES (README § Addresses):

- `Address::$streetName`, `$houseNumber` and `$orientationNumber` are filled from the ADIS street line when it ends in
  house numbers ("Kobližná 70/4"); they were always `null` before.
- A registration number (`č.ev.3`) sets `Address::$houseNumberType` to 2 and is left out of `$street`: "Pramenná č.ev.3"
  becomes "Pramenná 3", as in ARES.
- `Address::$street` of an address without a street name now starts with the part of the municipality, as in ARES:
  "LIBOTENICE 153" instead of "153". Stored ADIS addresses compared with fresh ones differ there.
