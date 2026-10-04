# Errors

## Exceptions

All implement `Exception\ExceptionInterface`.

| Exception            | Meaning                                                              | Carries                        |
|----------------------|----------------------------------------------------------------------|--------------------------------|
| `InvalidInput`       | caller error, or the source rejected the input; retrying won't help  | `?string $errorCode`           |
| `ServiceUnavailable` | transport, timeout, unexpected HTTP status, outage, throttling, SOAP Fault — try later; never a business answer such as "not a payer" | `Source $source`, `?string $errorCode` |
| `InvalidResponse`    | the answer cannot be read: not JSON/XML, a missing mandatory value, a value outside a closed set — an error to investigate | `Source $source` |

`$code` of `\Exception` is an `int` and stays `0`; source codes are strings, hence `errorCode`.

## Message rules

- **No response text in any message**: not ARES `popis`, ADIS `statusText`, SOAP `faultstring`, VIES `message`, nor
  any value read from a response (responses carry names and birth dates; messages end up in logs). Allowed: the
  caller's input id, the HTTP status, a fixed description.
- **Error codes are appended by the exception, never by hand.** `InvalidInput` and `ServiceUnavailable` append
  ` (error code X)` when `errorCode` is set and matches `/^[A-Za-z0-9_.:-]{1,64}$/D`; otherwise the message stays
  the plain description. `errorCode` always keeps the raw value. The regex exists in both classes; change both
  together with `tests/Exception/ExceptionsTest.php`.
- `InvalidResponse` messages come from the readers only, in these shapes (`LABEL` = `strtoupper($source->value)`):
  - `{LABEL}: missing {path}`
  - `{LABEL}: expected {type} at {path}`
  - `{LABEL}: response is not valid JSON` / `{LABEL}: response is not well-formed XML`
  - `{LABEL}: expected object at root`

  A mapper reports its own check through the reader's `invalid($key, $expected)`.

## Key paths

- JSON (ARES, VIES): jq-style, dot-separated, 0-based list indices — `zaznamy[0].sidlo.psc`.
- XML (ADIS): XPath-style, `/`-separated, 1-based positions, `@` for attributes, names with the registered prefix —
  `s:Body/r:StatusNespolehlivySubjektRozsirenyResponse/r:statusSubjektu[3]/@typSubjektu`.

## Mapping per source

- **ARES:** 404 with `NENALEZENO` / `VYSTUP_SUBJEKT_NENALEZEN` → `null`; other 404 and any other non-200 →
  `ServiceUnavailable` (code = `subKod`); 400 → `InvalidInput` (code = `subKod`, e.g. `VYSTUP_PRILIS_MNOHO_VYSLEDKU`).
- **ADIS:** HTTP ≠ 200 → `ServiceUnavailable`, code = `faultcode` when the body is a SOAP Fault (read leniently),
  else `null`; SOAP Fault with HTTP 200 → the same; `statusCode` 2 (maintenance) and 3 (unavailable) →
  `ServiceUnavailable` with the code; 1 or unknown → `InvalidResponse`; `NENALEZEN` → `null` / absent.
- **VIES:** an error arrives with HTTP 200 and `actionSucceed: false` / `errorWrappers` — it is never read as
  `valid: false`. `INVALID_INPUT`, `INVALID_REQUESTER_INFO` → `InvalidInput`; any other code → `ServiceUnavailable`;
  HTTP 400 → `InvalidInput`; other non-200 → `ServiceUnavailable`; both with the first `errorWrappers[0].error` read
  leniently.
