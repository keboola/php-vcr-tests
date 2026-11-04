# keboola/php-vcr-tests

Record the HTTP traffic of a PHP component's datadir tests once, and replay it offline in CI, with no change to
the component code. The PHP side of [keboola.vcr](https://github.com/keboola/python-vcr-tests): the recording
proxy (`python -m keboola.vcr.proxy`, a mitmproxy addon) does the recording, sanitizing and replay; this package
only tells the proxy which datadir case is running.

- Cassettes use the keboola.vcr format, in the same place as for Python components:
  `tests/<suite>/<case>/source/data/cassettes/requests.json`. The cassette validator and
  `tools/component cassettes` read them unchanged.
- Sanitizers are declared in `tests/vcr.json` (the proxy reads it), not in component code.
- Without the proxy (`VCR_CONTROL_URL` unset) nothing changes: the tests run live as before.

Requirements: PHP 7.4 or newer, `keboola/datadir-tests` 5.x or 6.x, a Debian-based test image (for
`update-ca-certificates`), and HTTP clients that honour `HTTPS_PROXY` (Guzzle does, unless the code passes its own
`proxy` option).

## How it works

```
 test container (component image)                 vcr container (python -m keboola.vcr.proxy)
 ┌──────────────────────────────┐   HTTPS_PROXY    ┌──────────────────────────────────────────┐
 │ phpunit  ── POST /start ──────┼───────────────▶ │ :8081 control: active cassette per case   │
 │   └─ php src/run.php ─────────┼── :8080 ──────▶ │ :8080 mitmproxy: record (live, sanitize)  │
 │ phpunit  ── /status, /stop ───┼───────────────▶ │        or replay (offline, from cassette) │
 └──────────────────────────────┘                  └──────────────────────────────────────────┘
```

1. `VcrDatadirTestTrait::getTempDatadir()` calls `POST /start` with the case's cassette path.
2. The component process inherits `HTTPS_PROXY`, `HTTP_PROXY` and `NO_PROXY` from the container environment.
3. `VcrDatadirTestTrait::runScript()` calls `GET /status` after each component run and fails the case at once if
   replay saw a request with no recorded answer. The case stays open, so a test may run the component twice.
4. After the test (`@after` hook `vcrStopCase()`), `POST /stop` ends the case. Record mode writes the sanitized
   cassette then.

## Set up a component

1. Add the package as a dev dependency (until it is on Packagist, from GitHub):

   ```bash
   composer config repositories.php-vcr-tests vcs https://github.com/keboola/php-vcr-tests
   composer require --dev keboola/php-vcr-tests:^0.1
   ```

2. Use the trait in the datadir test class:

   ```php
   use Keboola\VcrTests\VcrDatadirTestTrait;

   class DatadirTest extends DatadirTestCase
   {
       use VcrDatadirTestTrait;
   }
   ```

   If the class overrides `getTempDatadir()` or `runScript()` itself, its method wins over the trait: call the
   client from there (`VcrControlClient::fromEnvironment()`, `start()`, `status()`; the trait still ends the case).

3. Declare secrets and sanitizers in `tests/vcr.json`:

   ```json
   {
     "secret_env": ["OAUTH_APP_SECRET", "OAUTH_REFRESH_TOKEN"],
     "sanitizers": [
       {"class": "DefaultSanitizer", "additional_sensitive_fields": ["sessionId"]},
       {"class": "BodyFieldSanitizer", "fields": ["email", "displayName"]}
     ],
     "match": {"ignore_body_fields": ["requestId"]}
   }
   ```

   Field reference: the keboola.vcr README, section "Recording proxy".

4. Add the proxy and a test service to the component's `docker-compose.yml`:

   ```yaml
   services:
     vcr:
       build:
         context: .
         dockerfile_inline: |
           FROM python:3.13-slim
           ARG VCR_REF=v0.8.0
           RUN pip install --no-cache-dir "keboola.vcr[proxy] @ https://github.com/keboola/python-vcr-tests/archive/$${VCR_REF}.tar.gz"
       command: python -m keboola.vcr.proxy --config /code/tests/vcr.json --confdir /vcr-ca
       environment:
         - VCR_MODE=${VCR_MODE:-replay}
         - OAUTH_REFRESH_TOKEN   # the secret_env names: values only when recording
       volumes: [ "./tests:/code/tests", "vcr-ca:/vcr-ca" ]
       healthcheck:
         test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8081/health')"]
         interval: 2s
         retries: 30
     tests-vcr:
       build: .
       depends_on: { vcr: { condition: service_healthy } }
       environment:
         VCR_MODE: ${VCR_MODE:-replay}
         VCR_CONTROL_URL: http://vcr:8081
         HTTPS_PROXY: http://vcr:8080
         HTTP_PROXY: http://vcr:8080
         NO_PROXY: vcr,localhost,127.0.0.1
       volumes: [ "./tests:/code/tests", "vcr-ca:/vcr-ca:ro" ]
       command: sh -c "vendor/bin/vcr-trust-ca && composer tests-datadir"
   volumes:
     vcr-ca: {}
   ```

   `tests/` is mounted into both containers at the same path, so the proxy writes the cassettes next to the cases.

## Record and replay

Both run locally in the component's compose stack: the component's own image (its PHP version and extensions) plus
the proxy container. Python components record in-process and need neither.

```bash
# replay, offline, no secrets (what CI runs on every pull request)
docker compose run --rm tests-vcr

# record all cases against the live API: credentials in the folder's git-ignored .env (never commit them)
VCR_MODE=record docker compose run --rm tests-vcr

# record one case: delete its cassette, then filter
VCR_MODE=record docker compose run --rm tests-vcr sh -c "vendor/bin/vcr-trust-ca && vendor/bin/phpunit tests/datadir --filter 'testDatadir@<case>'"
```

Record mode overwrites the cassette of every case it runs. The proxy refuses to write a cassette that still holds
the value of a `secret_env` variable. Before you commit a recording, check it: in the keboola/components repo run
`tools/component cassettes <folder>` and the `component-developer:vcr-cassette-validator` agent (secrets, personal
data, and that each case recorded what it claims).

**Credentials only in GitHub?** Some test credentials exist only as GitHub environment secrets (copied there during
the monorepo migration, unreadable to anyone). Then record in a `workflow_dispatch` job that runs the same compose
command with those secrets and uploads only the cassettes as an artifact. Example: `.github/workflows/ex-onedrive.yml`
in keboola/components.

**Not supported: recording a platform debug job.** The proxy is a second container, and a platform job runs only the
component image. Python components can do it in-process (`VCRRecorder.record_debug_run`).

## What the proxy matches on

Method, scheme, host, port, path and the sorted query; the JSON or form body (except on OAuth token endpoints);
never on headers. Each recorded interaction plays once, in recorded order among equal requests. A request without a
recorded answer gets HTTP 418 with an explanation, and the trait fails the case with the list of such requests.

## Development

```bash
composer install
composer ci
```

## License

MIT licensed, see [LICENSE](./LICENSE) file.
