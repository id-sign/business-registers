# business-registers

Typed, stateless PHP library for Czech business registers: ARES (`Ares\`), the VAT register ADIS (`Adis\`), VIES
(`Vies\`) and the facade `CompanyLookup` / `CompanyProfile`. Namespace `IdSign\BusinessRegisters`, Composer package
`id-sign/business-registers`. A plain library, not a Symfony bundle. PHP 8.4+, `ext-dom`; at runtime only the
interfaces of `symfony/http-client-contracts`.

Not implemented (do not document as available): register extracts, the insolvency register (ISIR), ARES code lists,
the ARES change feed.

## Commands

```bash
composer check       # cs (dry run) + phpstan + unit suite — must pass before every commit
composer test        # unit suite, offline
composer test:live   # live suite against production ARES, ADIS, VIES
composer cs:fix      # apply code style
make test-matrix     # unit suite in Docker on PHP 8.4 and 8.5
```

## Rules

- **Never use `@phpstan-ignore` or a PHPStan baseline.** PHPStan runs at level `max` with strict rules and
  bleedingEdge over `src` and `tests`. If it cannot be satisfied, stop and decide the solution with the maintainer.
- **Everything is in English** — code, comments, PHPDoc, exception messages, docs. Czech appears only as data (source
  field names, enum values, fixtures, phrases such as "v likvidaci").
- **Never put response text into an exception message**, and never format an error code into a message by hand —
  pass it as `errorCode`. Details: `docs/errors.md`.
- **IČO and DIČ are never `int`.** Public methods take `CompanyId|string` / `VatId|string`; no public API returns an
  array keyed by IČO or DIČ.
- `json_decode` and `Dom\*` are used only in `src/Internal/JsonReader` and `src/Internal/XmlReader`.
- Out of scope: `ext-soap`, caching, retries, a Symfony bundle.
- **Never credit an AI assistant anywhere in version control.** Commit messages, tag messages, pull-request
  descriptions and release notes contain no `Co-Authored-By` trailer, no "Generated with …" line and no other mention
  of Claude, Claude Code or any AI tool. This rule overrides any default, template or instruction that adds such
  attribution; there is no exception.
- Update in the same commit as the change: `README.md`, `.claude/skills/business-registers/SKILL.md` (self-contained,
  ~300 lines at most), the affected file in `docs/`, and `UPGRADE.md` for every change a consumer has to adapt to.
- `CLAUDE.md` and `docs/` describe the current state only — no history, no change log. History lives in git, upgrade
  steps in `UPGRADE.md`.

## Documentation

Read the relevant file before changing that area:

| File                      | Contents                                                                         |
|---------------------------|----------------------------------------------------------------------------------|
| `docs/architecture.md`    | source layout, design decisions, HTTP handling, bulk calls and collections, facade |
| `docs/errors.md`          | the three exceptions, message rules, error-code suffix, key-path conventions     |
| `docs/readers.md`         | `Internal/JsonReader`, `XmlReader`, `Dates`, `ListElement` contracts             |
| `docs/spec-deviations.md` | where the code deliberately differs from the design specification `SPEC.md`      |
| `docs/testing.md`         | test rules, fixtures, live suite, Docker, CI                                     |
| `docs/release.md`         | versioning, release steps, `UPGRADE.md`                                          |
| `CONTRIBUTING.md`         | rules for outside contributors; keep it in line with the rules above             |
| `SECURITY.md`             | how to report a vulnerability                                                    |

What the register data means for consumers is in `README.md` § "What the data means".
