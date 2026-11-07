# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A zero-dependency PHP 8.1+ client for talking to Odoo instances through the **ODXProxy Gateway**. The library never speaks to Odoo directly — it POSTs a JSON envelope to the gateway, which proxies the call to the actual Odoo instance. The only runtime dependencies are `ext-curl` and `ext-json`; do not introduce Composer package dependencies.

This is a PHP port of an Android/Kotlin SDK, and several design choices deliberately mirror that SDK (see the Kotlin references in code comments, e.g. `encodeDefaults`, data-class `copy()`). Preserve that parity when changing payload shape or method signatures.

## Commands

```bash
composer install                          # install dev deps (PHPUnit) + generate autoloader
composer test                             # run the whole suite (alias: vendor/bin/phpunit)
composer test:unit                        # unit suite only (no network/subprocess)
composer test:integration                 # integration suite only
composer test:live                         # opt-in tests vs a REAL gateway; skips unless ODX_* env set
vendor/bin/phpunit --filter testEmptyKeyword   # run a single test by name
vendor/bin/phpunit tests/Unit/KeywordRequestTest.php   # run one file
find src -name '*.php' -exec php -l {} \;  # repo-wide syntax lint
```

The runtime itself is zero-dependency; PHPUnit is the only `require-dev`. There is no committed `composer.lock` (this is a library), so `composer install` resolves the newest PHPUnit your PHP allows (10.x on 8.1, up to 12.x on 8.3+) — the tests are written to pass across that range.

Tests live in `tests/` (PSR-4 `OdxProxy\Tests\`). The **integration** suite (`tests/Integration/`) needs no credentials or network: each test boots a local `php -S` mock gateway from `tests/Integration/server/router.php`, which echoes the raw request body back so tests can assert the exact wire payload (e.g. `keyword == {}`) and branches on `model_id` to exercise the error paths. The **live** suite (`tests/Live/`) is opt-in — excluded from the default run, invoked only via `composer test:live`, and self-skips unless the `ODX_*` env vars are set (names documented in `phpunit.xml.dist`); supply them through a gitignored `phpunit.xml` override or CI secrets, never committed, and keep its assertions read-only. Local throwaway scripts named `test_local.php` / `*.local.php` are gitignored.

## Architecture

Three layers, all under PSR-4 namespace `OdxProxy\` → `src/`:

1. **`Odx` (`src/Odx.php`)** — static facade over a request-scoped singleton (`$globalInstance`). Its CRUD/RPC methods (`search`, `searchCount`, `searchRead`, `read`, `create`, `write`, `unlink`, `call`) are **thin delegators** — each just forwards to the same method on `self::client()`. The real bodies live on `OdxProxyClient` (see below), so the facade and a `with()` client share one code path.
   - `Odx::init($config)` sets the global singleton — call once per request (bootstrap / service provider / per-user middleware).
   - `Odx::with($config)` returns a **disposable** client without touching global state. This is the multi-tenant path (e.g. each user supplies their own Odoo `api_key`); never call `init()` in a loop. Because the convenience methods live on the client, `Odx::with($cfg)->searchRead(...)` works directly.

2. **`OdxProxyClient` (`src/Client/OdxProxyClient.php`)** — owns both the convenience methods (`search`/`searchCount`/`searchRead`/`read`/`create`/`write`/`unlink`/`call`) and the single chokepoint they all funnel through: `execute($action, $model, $params, $keyword, $fnName, $requestId)`, which builds the payload for **every** operation. The convenience methods differ only by the `$action` string (`search`, `search_count`, `search_read`, `read`, `create`, `write`, `unlink`, `call_method`) and how they shape `$params`. All requests go to `POST {gatewayUrl}/api/odoo/execute`.
   - The curl handle is a **shared static** (`self::$ch`) reused across calls for keep-alive within a request. `OdxProxyClient::close()` releases it.
   - The gateway key goes in the `X-Api-Key` header; the *target Odoo* credentials travel inside the JSON body as `odoo_instance` (from `OdxClientConfig::toInstanceArray()`). Keep these two separate.

3. **`OdxClientConfig` (`src/Client/OdxClientConfig.php`)** — immutable config value object. `create($array)` maps the public array keys (`gateway_url`, `gateway_api_key`, `url`, `user_id`, `db`, `api_key`) to typed properties and defaults `gateway_url` to `https://gateway.odxproxy.io`.

### v2 API (`src/Client/OdxV2Client.php`)

`OdxV2Client` covers ODXProxy 0.9.0+ `/v2/odoo/*` (Odoo JSON-2, Odoo 19+). Get one from `Odx::v2()` (global) or `Odx::with($cfg)->v2()` (tenant). Both come from `OdxProxyClient::v2()`.
- **Methods:** `search`, `searchRead`, `searchCount`, `read`, `fieldsGet`, `create`, `createOne`, `write`, `unlink`, `call($model, $method, $kwargs = [], ids:, context:)`, `execute`, `version`, `isSupported`. They are designed for PHP 8 named arguments.
- **Wire format:** `kwargs` keys are Odoo's Python parameter names **verbatim**. `buildKwargs()` drops nulls and merges config `context` < `$kwargs['context']` < call `context`. It always returns a `stdClass`, so an empty `kwargs` encodes as `{}`; JSON-2 ignores non-object bodies. Empty `vals` likewise become `{}`.
- **Instance and results:** `odoo_instance` comes from `OdxClientConfig::toV2InstanceArray()`, with no `user_id` (`user_id` is now optional in `create()`). `create` always sends `vals_list` and returns `int[]`.
- **Transport:** v2 goes through `OdxProxyClient::postEnvelope()`. v1's `execute()` and v2 share the private `post($path, $json)`, which returns `[body, httpStatus]`.
- **Error codes:** on v2 the exception code is always the envelope's JSON-RPC code, with HTTP status in `httpStatus`. **v1 keeps its old semantics:** a non-2xx response puts the HTTP status in `statusCode` and the whole envelope in `data`. Don't "fix" that; `OdxException::rpcCode()` reads either form.
- **Mock gateway:** `tests/Integration/server/router.php` handles `/v2/odoo/execute` and `/v2/odoo/version`. It saves each v2 request body to `sys_get_temp_dir()/odx-mock-v2-<port>.json`, which `V2RoundTripTest` reads via the `MockGatewayServer` trait, and branches on `model_id` (`err.v2.*`) for the error paths.
- **Live tests:** `tests/Live/GatewayV2LiveTest.php` uses separate `ODX_V2_*` env vars, so a production `ODX_*` config never triggers it. Writes need `ODX_V2_ALLOW_WRITES=1`. When running it locally, pass `--configuration phpunit.xml.dist` so the secrets-bearing `phpunit.xml` isn't loaded. Verified 2026-10-01 against a local ODXProxy 0.9.0 → Odoo 20 (4/4, and v1 live 2/2).

### ODXBrain (`src/Client/OdxBrainClient.php`)

`OdxBrainClient` covers ODXProxy 0.10.0+ `/brain/v1/*` (resolve, sops/resolve, info) plus `GET /_/about` for `isAvailable()`. Get one from `Odx::brain()` or `Odx::with($cfg)->brain()`. Spec: TheODXProxy `SYSTEM_ARCHITECTURE.md` §4.8–§4.10, §6, §7.2; the Python SDK (`ODXProxyClient-Python`) is the reference implementation.
- **No `odoo_instance`.** ODXBrain needs no Odoo credentials. `buildPayload()` drops nulls everywhere (an explicit `null` is ODXBrain's 400), lets named args win over `$extra`, and rejects `id` / `odoo_instance` in `$extra`. Never default or guess `odoo_version` / `edition`.
- **Results** are returned decoded as-is. Never threshold on `score`, never drop candidates or unknown fields.
- **Errors:** `parseResult()` / `exceptionFor()` map to the `OdxBrain*Exception` subclasses of `OdxException`. A bare 404 means not installed, the same as -32008. `-32011` must never look like the caller's auth error. On HTTP 200 a 100–599 code is ODXBrain's status (`brainStatus()`; `odooStatus()` is null). Plain-text 400/422 bodies are not parsed as JSON-RPC. -32000 and 403/0 stay plain `OdxException`.
- **Facts:** `OdxProxyClient::brainFacts()` (v1 `/api/odoo/version`) and `OdxV2Client::brainFacts()` return `['odooVersion', 'edition']`, keyed to spread as named args, and cached per Odoo URL (`OdxBrainClient::clearFactsCache()`).
- **Transport:** `OdxProxyClient::request($method, $path, $json, $timeout)` is the shared curl call (GET or POST, optional `X-Request-Timeout`, curl timeout kept above it). v1/v2 `post()` wraps it.
- **Mock/live tests:** `router.php` picks the deployment by `X-Api-Key` (`old-proxy`, `no-brain`, `bad-key`) and the error by `query` (`err.brain.*`). It saves each `/brain` request to `odx-mock-brain-<port>.json` (`lastBrainRequest()`). `tests/Live/BrainLiveTest.php` uses `ODX_BRAIN_*` vars and is read-only. Verified 2026-11-07 (6/6) against the local `~/odx-it` stack: `:6600` full, `:6601` no ODXBrain, `:6602` no SOPs, with v1 and v2 facts read from Odoo 20.0+e.

### Supporting pieces

- **`KeywordRequest` (`src/Model/KeywordRequest.php`)** — fluent options object (`fields`, `order`, `limit`, `offset`, `context`), `JsonSerializable`. Its `jsonSerialize()` strips nulls via `array_filter`, so unset options never reach the gateway. `resetPagination()` returns a **clone** with `fields`/`order`/`limit`/`offset` cleared (PHP has no data-class `copy()`); the facade calls this for actions where pagination is meaningless (`search`, `read`, `create`, `write`) while `searchRead` passes the keyword through untouched.
- **`OdxException` (`src/Exception/OdxException.php`)** — the base exception type. The only subclasses are the `OdxBrain*Exception` family for `/brain/*`. Carries `statusCode`, structured `data`, and `httpStatus` (null when no response arrived). Thrown for curl failures (code `0`), HTTP ≥ 400, JSON decode errors (`500`), and protocol-level `error` objects inside a 200 response body. The parent `getCode()` and `$statusCode` hold the same value.
  - Helpers: `rpcCode()`, `odooStatus()`, `odooErrorName()`, `isLicenseError()` (code 0 **on HTTP 403** only), `isTransportError()`, `isJson2Unavailable()` (-32006), `isInvalidRequest()` (-32007), `isRetryable()`, plus code constants.
  - Code `0` is ambiguous: a curl failure and every Odoo 19+ v1 `/jsonrpc` error (on HTTP 200) also use it.
- **`IdHelper` (`src/Utils/IdHelper.php`)** — `generate()` makes a 13-byte hex request-correlation id (used as payload `id`); `normalizeId()` collapses Odoo's polymorphic id values (int / string / `false` / `[id, name]` Many2One tuples) to a string or null.

### Payload contract

`execute()` assembles `id`, `action`, `model_id`, `keyword`, `fn_name`, `params`, `odoo_instance`, then `array_filter`s top-level nulls before encoding (mirroring the Kotlin SDK's serialization). When changing the wire format, update both the payload builder in `execute()` and `OdxClientConfig::toInstanceArray()` together, and keep it consistent with the gateway and the Kotlin SDK.