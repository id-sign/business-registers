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
