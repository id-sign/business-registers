# Testing

## Unit suite (`composer test`)

- Offline: `MockHttpClient` plus recorded responses in `tests/Fixtures/{Ares,Adis,Vies,Isir}/`. The test tree mirrors
  `src/`; helpers live in `tests/Double/`.
- PHPUnit fails on deprecations, notices, warnings and risky tests.
- Fixtures with data of natural persons use a fictitious name and address; ISIR ones also the birth number
  `XXXXXX/XXXX` and IČO `00000000`. No real birth number is in the repository. States that cannot be observed live
  (ADIS status codes 1–3, ended accounts, some VIES error bodies, ISIR `WS4` / `SQL1` / `SERVER1`) and contract
  violations use hand-made fixtures that keep the real envelope and attribute shapes; a hand-made fixture never pins
  an unobserved combination of observed elements.
- Leak tests plant a sentinel string in a fixture and assert it never appears in an exception message.
- Concurrency tests count open requests with `tests/Double/CountingHttpClient`, a `MockHttpClient` whose responses
  have a generator body: the generator runs on the first read, so `open` (raised when a request is issued, lowered in
  the generator) is the number of requests issued and not yet read, `maxOpen` its peak and `issued` the requests
  attempted. A request whose answer closure throws counts as issued but never open. Cancellation is asserted in
  `HttpTransportTest`, where the test's sender closures keep the responses they issued (`getInfo('canceled')`).
- Facade tests use stubs of `CompanyDirectory`, `VatRegister` and `Vies`; no HTTP.
- PHPStan analyses the tests too. Avoid assertions it can prove statically (`assertInstanceOf` on `new X()`, a
  constant against its own literal, enum values with literal arguments): pin contracts through observable behaviour,
  data providers or `ReflectionClass` / `ReflectionEnum`. Narrow `mixed` before `assertContains` or array access. To
  pass a wrong-typed list element, call the public method through `ReflectionMethod::invoke()`.

## Live suite (`composer test:live`)

- `tests/Live/`, `#[Group('live')]`, excluded from the default run. Assertions are loose (types, non-emptiness,
  documented outcomes), never exact register values.
- Subjects: ČEZ `45274649`, Komerční banka `45317054` (group `CZ699001182`), Praha `00064581`, deleted `04957423`,
  Knihovna J. Mahena `CZ00101494`, LIDRU `CZ00121100`, `CZ11111111`, VIES test service numbers `DE100`–`DE601`
  (passed as `$endpoint`); ISIR: Sberbank `25083325`, ČEZ `45274649`, České aerolinie `45795908`, LIDRU `121100`
  (legal persons only).
- The live suite stays within the operator limits: no test sends more than one batch, so `maxConcurrency` never
  applies and requests run sequentially. ISIR has no batches; its live tests send four single requests.
- ADIS is down every night 0:00–0:10; ADIS live tests skip then. A VIES test skips when production VIES throttles;
  re-run.

## Docker and CI

- `make test | test-live | phpstan | cs-fix | test-matrix` run in `php:X-cli` (`PHP_VERSION=8.4` by default;
  `test-matrix` runs 8.4 and 8.5). They run `composer update` on the mounted project and leave a root-owned,
  gitignored `composer.phar` in the project root.
- `.github/workflows/ci.yaml`: cs-fixer, PHPStan and PHPUnit on PHP 8.4/8.5 with `symfony/http-client` 7.4 and 8.x,
  plus a `--prefer-lowest` run; the client version is forced with `composer require --dev --no-update`.
- `.github/workflows/live.yaml` runs the live suite manually; it is not a pull-request check.
