# Internal readers

`src/Internal/` is `@internal`. Mappers and parsers receive a reader object, never arrays or DOM nodes.

## JsonReader / XmlReader

- **Naming:** a method without prefix is mandatory — missing, `null`, empty after trimming or wrong type →
  `InvalidResponse`. `optional…` returns `null` (an empty list for list methods) for absent, `null` or empty, but
  still throws for a value of the wrong type.
- Every reader carries its `Source` and its key path; nested readers extend the path (conventions in `docs/errors.md`).
- Texts are trimmed; an empty string counts as missing. Empty text also counts as missing for `int` and dates.
- `JsonReader::string()` also accepts a JSON integer (ARES sends `psc`, house numbers and RÚIAN codes as numbers); a
  format change in a source must not break every lookup.
- `JsonReader::fromJson()` for 200 bodies; `tryFromJson()` returns `null` instead of throwing (lenient error bodies).
  Methods: `string`, `int`, `bool`, `date`, `dateTimeUtc`, `object`, `objectList`, `stringList`, each with an
  `optional…` twin, plus `invalid($key, $expected)`.
- `XmlReader::fromString($xml, Source, [prefix => uri])` parses with `LIBXML_NONET`, guards and restores
  `libxml_use_internal_errors`, and treats an empty body or a missing root as not well-formed. Only the registered
  prefixes resolve; an unprefixed name matches elements without a namespace. Lookups see direct children only.
  Methods: `element` / `optionalElement`, `elements`, `attribute`, `dateAttribute`, and over child text `string`,
  `int` (through `Integers::fromDigits()`), `optionalDate` (through `Dates::date()`, no mandatory twin) and
  `optionalDateTimePrague` (through `Dates::dateTimePrague()`, no mandatory twin), each mandatory/optional where it
  applies, plus `invalid($relative, $expected)`. `JsonReader::int` uses `Integers::fromDigits()` for text values too.
- New source needs (e.g. `int` or `date` on child elements) are added as new methods; existing contracts do not change.

## Dates

- `Dates::date()` strips one trailing `Z` (the insolvency register sends `2022-09-13Z`; `XmlReader::optionalDate` on
  child text first drops one `±hh:mm` offset, which `xsd:date` allows), parses `!Y-m-d` in
  `Europe/Prague` and rejects overflow (`2022-13-45`) by a round trip.
- `Dates::dateTimeUtc()` accepts ISO 8601 date-times only (`Z`, `±hh:mm`, `±hhmm`, fractions, space-separated form
  read as UTC) and rejects overflow and relative words.
- `Dates::dateTimePrague()` accepts the same shapes as `dateTimeUtc()`, drops the fraction and any zone suffix and
  reads the clock value as `Europe/Prague` local time (ISIR `casSynchronizace` is Prague local time wrongly suffixed
  `Z`; verified in summer time only). Overflow is rejected through `DateTimeImmutable::getLastErrors()`, not a round
  trip, so a clock value in the hour skipped by the spring switch is accepted (PHP moves it forward); the hour repeated
  in autumn is read as one of its two instants.
- All return `null`; the readers turn it into a path-bearing `InvalidResponse`.

## Integers

`Integers::fromDigits()` reads digits only (no sign, no decimals) and rejects a value beyond `PHP_INT_MAX` by a round
trip, because the cast saturates; it returns `null` and the reader adds the key path.

## StreetLine

The street-line rule of `Address` (`docs/architecture.md` § Address): `compose($name, $houseNumber, $orientationNumber)`
writes "Duhová 1444/2"; `splitNumbers()` reads an ISIR house-number field (`123`, `123/4`, `123/4a`, `čp.123`) and
`splitLine()` an ADIS street line with trailing numbers (`Kobližná 70/4`, `Masarykovo nám. 292`, `153`). Both accept a
known label right before the numbers (`č.p.`, `čp.` → type 1, `č.ev.`, `ev.č.` → type 2) and return it as
`houseNumberType`; `splitLine()` returns `null` when the last word of the name looks like a label of an unknown
spelling (`č.pop.`, `čís.`, `čp`). Digits are ASCII only. Any other shape is `null`.

## ListElement

`ListElement::idOrString($element, $index, $class)` validates one element of a caller's id list: a `CompanyId` /
`VatId` or a string passes, anything else is an `InvalidInput` naming the index or key. It takes `mixed` on purpose:
PHPStan treats the PHPDoc list type as certain and would report an `is_string()` check as always true.

## Identifiers

The one place for the two id rules of `Ares\AresClient`, `Ares\Companies`, `Adis\VatRegisterClient`, `Adis\VatSubjects`
and `Isir\InsolvencyClient`. `Identifiers::companyId()` parses a string strictly through `CompanyId::parse()` and takes
a `CompanyId` as it is, so a register id built with `CompanyId::fromRegister()` passes even when it fails the check
digit. `Identifiers::czechVatId()` parses a string with `CZ` as the default country and rejects a non-Czech VAT id,
string or `VatId`, with an `InvalidInput`.

## Account numbers

`Adis\Internal\BankAccountNumber` normalises both sides of an account comparison: all whitespace including Unicode
spaces is removed (`/\s+/u`), dash variants (`\p{Pd}` and U+2212) become `-`, nothing else is converted. Canonical
form: prefix and number without leading zeros, four-digit bank code; a Czech IBAN is split by its fixed structure;
anything else is compared literally, case-insensitive. Unrecognisable input compares as `false`.
