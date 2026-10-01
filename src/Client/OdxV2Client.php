<?php

declare(strict_types=1);

namespace OdxProxy\Client;

use OdxProxy\Exception\OdxException;
use OdxProxy\Utils\IdHelper;

/**
 * The v2 data API: ODXProxy `/v2/odoo/execute` (ODXProxy 0.9.0+), which reaches Odoo
 * over its JSON-2 API instead of `/jsonrpc`. Needs **Odoo 19+**. Odoo 22 removes
 * `/jsonrpc`, so from then on v2 is the only way in.
 *
 * Get one from the global client (`Odx::v2()`) or a disposable one
 * (`Odx::with($config)->v2()`). The config's `user_id` is not sent: JSON-2 derives
 * the user from the API key, which must be an Odoo **API key**, not a password.
 *
 * Differences from the v1 methods:
 * - **Named arguments only.** Each argument is sent under Odoo's Python parameter
 *   name (`domain`, `fields`, `vals_list`, `ids`, ...). There is no positional
 *   `$args`/params and no KeywordRequest. `$domain` is the domain list itself,
 *   e.g. `[['is_company', '=', true]]`, without v1's extra wrapping array.
 * - Arguments left `null` are omitted, so Odoo's own defaults apply.
 * - `create()` always returns a list of ids; use `createOne()` for a single id.
 * - `$context` is merged over the config's `context` (call keys win).
 * - Errors are OdxException as in v1, with `statusCode` = the JSON-RPC code,
 *   `httpStatus` = the HTTP status, and `odooStatus()` = Odoo's HTTP status for
 *   Odoo-side errors (e.g. 422). `isJson2Unavailable()` / `isInvalidRequest()`
 *   cover the v2-specific proxy errors.
 */
final class OdxV2Client
{
    /** @var array<string, bool> Odoo URL => supports v2, for isSupported(). */
    private static array $supportCache = [];

    public function __construct(
        private OdxProxyClient $client,
        private OdxClientConfig $config
    ) {
    }

    // --- Data API --------------------------------------------------------------

    /** Ids of the records of `$model` matching `$domain`. */
    public function search(
        string $model,
        array $domain,
        ?int $offset = null,
        ?int $limit = null,
        ?string $order = null,
        ?array $context = null,
        ?string $requestId = null
    ): array {
        $kwargs = ['domain' => $domain, 'offset' => $offset, 'limit' => $limit, 'order' => $order];
        return $this->execute($model, 'search', $kwargs, $context, $requestId);
    }

    /** Records of `$model` matching `$domain` (all records when null). */
    public function searchRead(
        string $model,
        ?array $domain = null,
        ?array $fields = null,
        ?int $offset = null,
        ?int $limit = null,
        ?string $order = null,
        ?array $context = null,
        ?string $requestId = null
    ): array {
        $kwargs = [
            'domain' => $domain, 'fields' => $fields, 'offset' => $offset, 'limit' => $limit, 'order' => $order,
        ];
        return $this->execute($model, 'search_read', $kwargs, $context, $requestId);
    }

    /** Number of records of `$model` matching `$domain`. */
    public function searchCount(
        string $model,
        array $domain,
        ?int $limit = null,
        ?array $context = null,
        ?string $requestId = null
    ): int {
        return (int) $this->execute($model, 'search_count', ['domain' => $domain, 'limit' => $limit], $context, $requestId);
    }

    /** The records `$ids` of `$model`. */
    public function read(
        string $model,
        array $ids,
        ?array $fields = null,
        ?string $load = null,
        ?array $context = null,
        ?string $requestId = null
    ): array {
        $kwargs = ['ids' => $ids, 'fields' => $fields, 'load' => $load];
        return $this->execute($model, 'read', $kwargs, $context, $requestId);
    }

    /** Field descriptions of `$model`, keyed by field name. */
    public function fieldsGet(
        string $model,
        ?array $allfields = null,
        ?array $attributes = null,
        ?array $context = null,
        ?string $requestId = null
    ): array {
        $kwargs = ['allfields' => $allfields, 'attributes' => $attributes];
        return $this->execute($model, 'fields_get', $kwargs, $context, $requestId);
    }

    /**
     * Create one record (an associative array) or several (a list of them).
     * Always sends `vals_list` and **always returns a list of ids**; see createOne().
     */
    public function create(string $model, array $values, ?array $context = null, ?string $requestId = null): array
    {
        $isListOfRecords = array_is_list($values) && ($values === [] || is_array($values[0]));
        $valsList = array_map(
            static fn(array $vals) => self::asObject($vals),
            $isListOfRecords ? $values : [$values]
        );
        return $this->execute($model, 'create', ['vals_list' => $valsList], $context, $requestId);
    }

    /** Create a single record and return its id. */
    public function createOne(string $model, array $values, ?array $context = null, ?string $requestId = null): int
    {
        return (int) $this->create($model, [$values], $context, $requestId)[0];
    }

    /** Write `$values` to the records `$ids`. Returns true. */
    public function write(string $model, array $ids, array $values, ?array $context = null, ?string $requestId = null): bool
    {
        $kwargs = ['ids' => $ids, 'vals' => self::asObject($values)];
        return (bool) $this->execute($model, 'write', $kwargs, $context, $requestId);
    }

    /** Delete the records `$ids`. Returns true. */
    public function unlink(string $model, array $ids, ?array $context = null, ?string $requestId = null): bool
    {
        return (bool) $this->execute($model, 'unlink', ['ids' => $ids], $context, $requestId);
    }

    /**
     * Call any public method of `$model`.
     *
     * `$ids` is for record methods only; Odoo rejects it on `@api.model` methods.
     * Every other argument goes in `$kwargs` under its Python parameter name. There
     * are no positional arguments in v2:
     *
     *     $v2->call('account.move', 'action_post', ids: [7]);
     *     $v2->call('res.partner', 'name_search', ['name' => 'Acm', 'limit' => 5]);
     */
    public function call(
        string $model,
        string $method,
        array $kwargs = [],
        ?array $ids = null,
        ?array $context = null,
        ?string $requestId = null
    ): mixed {
        $kwargs['ids'] = $ids;
        return $this->execute($model, $method, $kwargs, $context, $requestId);
    }

    /** Low-level: call `$method` with exactly these named arguments (null values dropped). */
    public function execute(
        string $model,
        string $method,
        array $kwargs,
        ?array $context = null,
        ?string $requestId = null
    ): mixed {
        $payload = [
            'id' => $requestId ?? IdHelper::generate(),
            'model_id' => $model,
            'method' => $method,
            'kwargs' => self::buildKwargs($kwargs, $this->config->defaultContext, $context),
            'odoo_instance' => $this->config->toV2InstanceArray(),
        ];
        return $this->client->postEnvelope('/v2/odoo/execute', $payload);
    }

    // --- Version -----------------------------------------------------------------

    /**
     * Odoo's version over JSON-2 (`POST /v2/odoo/version`), e.g.
     * `['version_info' => [20, 0, 0, 'final', 0, 'e'], 'version' => '20.0+e']`.
     * Defaults to the configured instance URL. Throws an OdxException with
     * `isJson2Unavailable()` when the server has no JSON-2.
     */
    public function version(?string $url = null, ?string $requestId = null): array
    {
        $payload = ['id' => $requestId ?? IdHelper::generate(), 'url' => $url ?? $this->config->instanceUrl];
        return $this->client->postEnvelope('/v2/odoo/version', $payload);
    }

    /**
     * Whether the Odoo at `$url` (default: the configured instance) can be reached
     * through v2, i.e. runs Odoo 19+. Cached per URL for the life of the PHP
     * process. Proxy/network errors are thrown, not cached.
     */
    public function isSupported(?string $url = null): bool
    {
        $target = $url ?? $this->config->instanceUrl;
        if (array_key_exists($target, self::$supportCache)) {
            return self::$supportCache[$target];
        }
        try {
            $info = $this->version($target);
            $major = $info['version_info'][0] ?? 0;
            $supported = is_int($major) && $major >= 19;
        } catch (OdxException $e) {
            if (!$e->isJson2Unavailable()) {
                throw $e;
            }
            $supported = false;
        }
        return self::$supportCache[$target] = $supported;
    }

    // --- Helpers -----------------------------------------------------------------

    /**
     * Builds the wire `kwargs`: drops null values (so Odoo defaults apply), merges
     * `$defaultContext` < `$kwargs['context']` < `$context` (later wins) and omits
     * `context` when the result is empty. Always encodes as a JSON **object**, even
     * when empty, because JSON-2 silently ignores a non-object body.
     */
    public static function buildKwargs(array $kwargs, ?array $defaultContext, ?array $context): \stdClass
    {
        $inlineContext = is_array($kwargs['context'] ?? null) ? $kwargs['context'] : [];
        $out = [];
        foreach ($kwargs as $key => $value) {
            if ($value !== null && $key !== 'context') {
                $out[(string) $key] = $value;
            }
        }
        $merged = array_merge($defaultContext ?? [], $inlineContext, $context ?? []);
        if ($merged !== []) {
            $out['context'] = (object) $merged;
        }
        return (object) $out;
    }

    /** An empty PHP array encodes as `[]`; Odoo needs `{}` for a record's values. */
    private static function asObject(array $values): array|\stdClass
    {
        return $values === [] ? new \stdClass() : $values;
    }

    /** Forget cached isSupported() answers (mainly for tests). */
    public static function clearSupportCache(): void
    {
        self::$supportCache = [];
    }
}
