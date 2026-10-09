# Errors

## Exceptions

All implement `Exception\ExceptionInterface`.

| Exception            | Meaning                                                              | Carries                        |
|----------------------|----------------------------------------------------------------------|--------------------------------|
| `InvalidInput`       | caller error, or the source rejected the input; retrying won't help  | `?string $errorCode`           |
| `ServiceUnavailable` | transport, timeout, unexpected HTTP status, outage, throttling, SOAP Fault — try later; never a business answer such as "not a payer" | `Source $source`, `?string $errorCode`, `bool $connectionFailed` |
| `InvalidResponse`    | the answer cannot be read: not JSON/XML, a missing mandatory value, a value outside a closed set — an error to investigate | `Source $source`, `?string $errorCode` |

`$code` of `\Exception` is an `int` and stays `0`; source codes are strings, hence `errorCode`.

## Message rules

- **No response text in any message**: not ARES `popis`, ADIS `statusText`, ISIR `textChyby` / `popisChyby`, SOAP
  `faultstring`, VIES `message`, nor any value read from a response (responses carry names, birth numbers and birth
  dates; messages end up in logs). Allowed: the caller's input id, the HTTP status, a fixed description, and for a
  transport failure the message of the HTTP client's transport exception (`Internal/HttpTransport`).
- **Error codes are appended by the exception, never by hand.** `InvalidInput`, `ServiceUnavailable` and
  `InvalidResponse` append ` (error code X)` when `errorCode` is set and matches `/^[A-Za-z0-9_.:-]{1,64}$/D`;
  otherwise the message stays the plain description. `errorCode` always keeps the raw value. The regex exists in all
  three classes; change them together with `tests/Exception/ExceptionsTest.php`.
- `InvalidResponse` messages come from the readers, in these shapes (`LABEL` = `strtoupper($source->value)`):
  - `{LABEL}: missing {path}`
  - `{LABEL}: expected {type} at {path}`, followed by ` (error code X)` when the source sent a code it cannot accept
  - `{LABEL}: response is not valid JSON` / `{LABEL}: response is not well-formed XML`
  - `{LABEL}: expected object at root`

  A mapper reports its own check through the reader's `invalid($key, $expected)`; `XmlReader::invalid()` takes the
  source's code as an optional third argument. One check sits outside the readers: `Adis\VatRegisterClient` throws
  `ADIS: expected each VAT id at most once in the response` when a batch answer repeats a VAT id.

## Key paths

- JSON (ARES, VIES): jq-style, dot-separated, 0-based list indices — `zaznamy[0].sidlo.psc`.
- XML (ADIS, ISIR): XPath-style, `/`-separated, 1-based positions, `@` for attributes, names with the registered
  prefix — `s:Body/r:StatusNespolehlivySubjektRozsirenyResponse/r:statusSubjektu[3]/@typSubjektu`; elements without a
  namespace have no prefix — `s:Body/ns2:getIsirWsCuzkDataResponse/data[2]/cisloSenatu`.

## Mapping per source

- **ARES:** 404 with `NENALEZENO` / `VYSTUP_SUBJEKT_NENALEZEN` → `null`; other 404 and any other non-200 →
  `ServiceUnavailable` (code = `subKod`); 400 → `InvalidInput` (code = `subKod`, e.g. `VYSTUP_PRILIS_MNOHO_VYSLEDKU`).
- **ADIS:** HTTP ≠ 200 → `ServiceUnavailable`, code = `faultcode` when the body is a SOAP Fault (read leniently),
  else `null`; SOAP Fault with HTTP 200 → the same; `statusCode` 2 (maintenance) and 3 (unavailable) →
  `ServiceUnavailable` with the code; 1 or unknown → `InvalidResponse` with the code; `NENALEZEN` → `null` / absent.
- **ISIR:** a blank or malformed birth number (not `^\d{6}/?\d{3,4}$` after trim), a surname or first name without a
  letter, a name that is not valid UTF-8 or holds a character XML 1.0 forbids, or a birth year outside 1–9999 →
  `InvalidInput` with a fixed message, before any request. HTTP ≠ 200 → `ServiceUnavailable` (`ISIR returned HTTP
  {status} for company id {id}`, `… for a birth number`, `… for a person`; the transport message names the same
  subject — never the birth number, name or birth date), code = `faultcode`
  when the body is a SOAP Fault (read leniently), else `null`; SOAP Fault with HTTP 200 → `ServiceUnavailable`, code =
  `faultcode`. `stav` is mandatory. No `kodChyby` → the `data` rows, `pocetVysledku` mandatory; a count above the
  number of rows is a truncated answer → `InvalidResponse` at `…/stav/pocetVysledku` (a lower count is accepted).
  The service caps distinct proceedings at `maxPocetVysledku` and returns every debtor row of each, so the client asks
  for 101: a list cut at 101 proceedings always has more than 100 `data` rows. More than 100 distinct proceedings is
  an incomplete list → `InvalidResponse` at `…/data`; up to 100 proceedings is a complete answer whatever the rows.
  `WS2` → empty collection. `WS4`, `SQL1`, `SERVER1` → `ServiceUnavailable` with the code and a fixed description
  (`ISIR data are not current`, `ISIR database error`, `ISIR application error`). `WS1`, `WS3` and any other code →
  `InvalidResponse` at `…/stav/kodChyby` with the code (impossible for a valid id); the code is read before anything
  else in `stav`. For a person lookup, a `relevanceVysledku` above the requested maximum (1 by birth number, 4 by name
  and birth date) → `InvalidResponse` at `…/stav/relevanceVysledku`; `find()` accepts any. `cisloSenatu`, `druhVec`,
  `bcVec`, `rocnik` are mandatory per row; `dalsiDluznikVRizeni` outside `T` / `F`, an `ic` that is not up to 8 digits
  or an unreadable date → `InvalidResponse` with the key path.
  `casSynchronizace` is a freshness hint and read leniently: an unreadable value is `synchronisedAt = null`, never an
  exception.
- **VIES:** an error arrives with HTTP 200 and `actionSucceed: false` / `errorWrappers` — it is never read as
  `valid: false`. `INVALID_INPUT`, `INVALID_REQUESTER_INFO` → `InvalidInput`; any other code → `ServiceUnavailable`;
  HTTP 400 → `InvalidInput`; other non-200 → `ServiceUnavailable`; both with the first `errorWrappers[0].error` read
  leniently.
