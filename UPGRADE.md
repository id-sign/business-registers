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
