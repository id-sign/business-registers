# Contributing

Bug reports, register quirks and pull requests are welcome. For a new feature or a change of the public API, open an
issue first, so that the design can be agreed before you write code.

## Reporting a bug

- Name the method you called, the IČO or DIČ you passed and the exception class with its message. Messages contain
  your input id but never register response data.
- Do not paste raw register responses about natural persons (names, addresses, birth-number DIČ). Describe the shape of
  the data instead, or replace the personal values.
- For a security issue, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## Pull requests

- `composer check` (code style, PHPStan, unit suite) must pass. Run `composer cs:fix` to apply the code style.
- PHPStan runs at level `max` with strict rules over `src` and `tests`. No `@phpstan-ignore` and no baseline: if a
  finding cannot be satisfied, raise it in the pull request.
- Every change of behaviour comes with a unit test. The unit suite is offline: recorded or hand-made responses in
  `tests/Fixtures/`, never a real request. Fixtures about natural persons use a fictitious name and address.
- Code, comments, PHPDoc, exception messages and documentation are in English. Czech appears only as data (field names
  of the registers, enum values, fixtures).
- Exception messages never contain text from a register response; a source error code is passed as `errorCode`, never
  formatted into the message by hand. See [docs/errors.md](docs/errors.md).
- IČO and DIČ are never `int`, and no public method returns an array keyed by IČO or DIČ.
- Update in the same pull request: `README.md`, `.claude/skills/business-registers/SKILL.md`, the affected file in
  `docs/`, and `UPGRADE.md` for every change a consumer has to adapt to.
- Out of scope: `ext-soap`, caching, retries, rate limiting and a Symfony bundle. The library stays stateless; these
  belong in the application around it.

## Where to read first

| File                                               | Contents                                                       |
|----------------------------------------------------|----------------------------------------------------------------|
| [docs/architecture.md](docs/architecture.md)       | source layout, design decisions, HTTP handling, bulk calls     |
| [docs/errors.md](docs/errors.md)                   | exceptions, message rules, error codes, key paths              |
| [docs/readers.md](docs/readers.md)                 | the internal JSON and XML readers                              |
| [docs/testing.md](docs/testing.md)                 | test rules, fixtures, live suite, Docker, CI                   |
| [docs/release.md](docs/release.md)                 | versioning and `UPGRADE.md`                                    |
| [docs/spec-deviations.md](docs/spec-deviations.md) | decisions that differ from the original design specification   |

The live suite (`composer test:live`) sends real requests to ARES, ADIS, VIES and ISIR. Run it only when your change
touches a client, and mind the operators' limits listed in the README.
