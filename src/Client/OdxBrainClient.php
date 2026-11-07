<?php

declare(strict_types=1);

namespace OdxProxy\Client;

use OdxProxy\Exception\OdxBrainConfigException;
use OdxProxy\Exception\OdxBrainException;
use OdxProxy\Exception\OdxBrainNotInstalledException;
use OdxProxy\Exception\OdxBrainRequestException;
use OdxProxy\Exception\OdxBrainTimeoutException;
use OdxProxy\Exception\OdxBrainUnavailableException;
use OdxProxy\Exception\OdxException;
use OdxProxy\Utils\IdHelper;

/**
 * ODXBrain through ODXProxy 0.10.0+ (`/brain/v1/*`): an optional sidecar that tells
 * an LLM which Odoo model holds some data, or which SOP covers a task. It never
 * contacts Odoo, so no `odoo_instance` is sent; only the proxy's `X-Api-Key`.
 *
 * Get one from `Odx::brain()` or `Odx::with($config)->brain()`.
 *
 *     $brain = Odx::brain();
 *     if ($brain->isAvailable()) {
 *         $facts = Odx::v2()->brainFacts();        // ['odooVersion' => '19.0', 'edition' => 'enterprise']
 *         $hit = $brain->resolveModel('which invoice is not paid yet', ...$facts);
 *         foreach ($hit['candidates'] as $c) {     // every candidate, in rank order
 *             $fields = Odx::v2()->fieldsGet($c['model']);
 *         }
 *     }
 *
 * Results are ODXBrain's response body, decoded as-is, with every candidate in
 * order and unknown fields kept. Nothing is thresholded on `score`: the order is
 * the contract, the scale is not. Watch the conditional fields: `excluded`,
 * `filtered`, `version_unaudited`, `candidates[].provisional`, and for SOPs
 * `match` (`"none"` = no confident SOP: stop and ask) and `facts_missing`.
 * Note json_decode turns `{}` into `[]`, so take care if you re-encode a result.
 *
 * Errors are OdxBrainException subclasses (see that class), except the proxy's
 * own 401 / 403 refusals, which stay plain OdxException.
 */
final class OdxBrainClient
{
    public const PATH_RESOLVE = '/brain/v1/resolve';
    public const PATH_SOPS_RESOLVE = '/brain/v1/sops/resolve';
    public const PATH_INFO = '/brain/v1/info';
    public const PATH_ABOUT = '/_/about';

    /** Never taken from `$extra`: `id` is the envelope's, and `odoo_instance` carries Odoo credentials. */
    private const RESERVED = ['id', 'odoo_instance'];

    /** @var array<string, array{odooVersion: string, edition: string}> Odoo URL => facts. */
    private static array $factsCache = [];

    public function __construct(private OdxProxyClient $client)
    {
    }

    /**
     * Whether this proxy is configured to forward to ODXBrain, from `GET /_/about`
     * `features.odxbrain`. A proxy older than 0.10.0 has no `features` and reads as
     * false. True means "configured", not "up right now". Proxy errors are thrown.
     */
    public function isAvailable(): bool
    {
        [$body, $status] = $this->client->request('GET', self::PATH_ABOUT);
        $decoded = json_decode($body, true);
        if ($status !== 200 || !is_array($decoded) || isset($decoded['error'])) {
            $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            throw new OdxException(
                is_int($err['code'] ?? null) ? $err['code'] : $status,
                is_string($err['message'] ?? null) ? $err['message'] : "GET /_/about failed with HTTP $status",
                is_array($err['data'] ?? null) ? $err['data'] : null,
                $status
            );
        }
        return ($decoded['result']['features']['odxbrain'] ?? false) === true;
    }

    /**
     * Ranked Odoo model names for a question (`POST /brain/v1/resolve`).
     *
     * Leave `$odooVersion` / `$edition` null when you don't know them; they are then
     * omitted, which filters nothing. Never guess one: a wrong version silently
     * removes correct answers. Get both from brainFacts(). `$extra` passes request
     * fields this SDK has no parameter for yet; named arguments win over it.
     * `$timeout` (seconds) is sent as `x-request-timeout` for the proxy's hop.
     *
     * @return array ODXBrain's result: `candidates` ({model, score, provisional?}),
     *               `model`, `dim`, `corpus_version`, `usage`, and conditional fields.
     */
    public function resolveModel(
        string $query,
        ?string $odooVersion = null,
        ?string $edition = null,
        ?int $k = null,
        array $extra = [],
        ?string $requestId = null,
        ?int $timeout = null
    ): array {
        $fields = ['odoo_version' => $odooVersion, 'edition' => $edition, 'k' => $k];
        return $this->post(self::PATH_RESOLVE, self::buildPayload($query, $fields, $extra, $requestId), $timeout);
    }

    /**
     * Ranked SOP ids for a task (`POST /brain/v1/sops/resolve`). Same rules as
     * resolveModel(), plus `$installedModules` (module names with state
     * `installed`) and `$company` (the key the SOPs were published under). `k` is
     * capped at 10 upstream.
     *
     * `match === 'none'` in the result means no SOP is a confident match: stop and
     * ask, don't run the nearest one. Check the chosen SOP's `title` against the task.
     * A candidate's `version` is opaque: compare it, don't parse it. The SOP body
     * itself comes from the doc manager (odxdocs), not from the proxy.
     *
     * @return array ODXBrain's result: `candidates` ({id, version, title, score}),
     *               `model`, `dim`, `sops` ({generation, root}), `usage`, and
     *               conditional fields (`match`, `excluded`, `facts_missing`).
     */
    public function resolveSop(
        string $query,
        ?string $odooVersion = null,
        ?string $edition = null,
        ?array $installedModules = null,
        ?string $company = null,
        ?int $k = null,
        array $extra = [],
        ?string $requestId = null,
        ?int $timeout = null
    ): array {
        $fields = [
            'odoo_version' => $odooVersion,
            'edition' => $edition,
            'installed_modules' => $installedModules === null ? null : array_values($installedModules),
            'company' => $company,
            'k' => $k,
        ];
        return $this->post(self::PATH_SOPS_RESOLVE, self::buildPayload($query, $fields, $extra, $requestId), $timeout);
    }

    /**
     * ODXBrain's pre-flight (`GET /brain/v1/info`): the embedding model, the
     * corpus and SOP set loaded, and the Odoo version range covered.
     */
    public function info(?int $timeout = null): array
    {
        [$body, $status] = $this->client->request('GET', self::PATH_INFO, null, $timeout);
        return self::parseResult($body, $status);
    }

    // --- Wire helpers (public for unit tests) ---------------------------------------

    /**
     * @internal The `/brain/v1/*resolve` body: `$extra`, then `$fields` over it,
     * then `query` and `id`. Null values are dropped from both, never sent.
     */
    public static function buildPayload(string $query, array $fields, array $extra, ?string $requestId): array
    {
        $reserved = array_values(array_intersect(self::RESERVED, array_map('strval', array_keys($extra))));
        if ($reserved !== []) {
            throw new \InvalidArgumentException('$extra may not carry ' . implode(', ', $reserved));
        }
        $body = array_filter($extra, static fn($value) => $value !== null);
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }
        $body['query'] = $query;
        $body['id'] = $requestId ?? IdHelper::generate();
        return $body;
    }

    /**
     * @internal Turns a `/brain/*` response into its `result`, or throws.
     *
     * A bare 404 (no envelope) means the proxy predates ODXBrain: same as -32008.
     * A non-2xx without an envelope (plain-text 400 / 422 for a malformed body) is
     * a plain OdxException carrying the text, never parsed as JSON-RPC. An error
     * envelope maps by code; on HTTP 200 a code of 100-599 is ODXBrain's status.
     */
    public static function parseResult(string $body, int $status): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : null;

        if ($error !== null) {
            throw self::exceptionFor($error, $status);
        }
        if ($status === 404) {
            throw new OdxBrainNotInstalledException(
                OdxException::BRAIN_NOT_INSTALLED,
                'This proxy has no /brain routes (it predates ODXBrain, ODXProxy < 0.10.0)',
                null,
                $status
            );
        }
        if ($status >= 300 || $status < 200) {
            $text = trim(substr($body, 0, 512));
            throw new OdxException($status, "HTTP Error $status" . ($text === '' ? '' : ": $text"), null, $status);
        }
        if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
            throw new OdxException(500, 'Invalid response from proxy: ' . substr($body, 0, 200), null, $status);
        }
        $result = $decoded['result'];
        if (!is_array($result)) {
            throw new OdxException(500, 'ODXBrain result was not an object: ' . substr($body, 0, 200), null, $status);
        }
        return $result;
    }

    /** @internal Maps a `/brain/*` error envelope to its exception. */
    public static function exceptionFor(array $error, int $status): OdxException
    {
        $code = is_int($error['code'] ?? null) ? $error['code'] : $status;
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown ODXBrain error';
        $data = is_array($error['data'] ?? null) ? $error['data'] : null;

        $class = match (true) {
            $code === OdxException::BRAIN_NOT_INSTALLED => OdxBrainNotInstalledException::class,
            $code === OdxException::BRAIN_UNREACHABLE => OdxBrainUnavailableException::class,
            $code === OdxException::BRAIN_TIMEOUT => OdxBrainTimeoutException::class,
            $code === OdxException::BRAIN_MISCONFIGURED => OdxBrainConfigException::class,
            $code === 400 => OdxBrainRequestException::class,
            $code === 503 => OdxBrainUnavailableException::class,
            $code >= 100 && $code <= 599 => OdxBrainException::class,
            // 0 (license), -32000 (auth) and any other proxy code keep the plain type.
            default => OdxException::class,
        };
        return new $class($code, $message, $data, $status);
    }

    // --- Instance facts ---------------------------------------------------------------

    /**
     * @internal Facts from a v1 `/api/odoo/version` result: `server_serie`, and
     * enterprise iff `+e` is in `server_version`.
     */
    public static function factsFromV1Version(array $result): array
    {
        $serie = $result['server_serie'] ?? null;
        $version = $result['server_version'] ?? null;
        if (!is_string($serie) || !is_string($version)) {
            throw new OdxException(500, 'Odoo version result lacks server_serie / server_version');
        }
        return ['odooVersion' => $serie, 'edition' => self::editionOf($version)];
    }

    /**
     * @internal Facts from a v2 `/v2/odoo/version` result. JSON-2 has no
     * `server_serie`, so it is rebuilt as Odoo's release.py does: the first two
     * `version_info` parts joined with `.`. Edition comes from `version`, which is
     * not cut at the `+` ("20.0+e-20260925"), and never from `version_info[5]`.
     */
    public static function factsFromV2Version(array $result): array
    {
        $info = $result['version_info'] ?? null;
        $version = $result['version'] ?? null;
        if (!is_array($info) || count($info) < 2 || !is_string($version)) {
            throw new OdxException(500, 'Odoo version result lacks version_info / version');
        }
        $parts = array_map(static fn($part) => is_scalar($part) ? (string) $part : '', array_slice(array_values($info), 0, 2));
        return ['odooVersion' => implode('.', $parts), 'edition' => self::editionOf($version)];
    }

    /** @internal brainFacts() cache, keyed by Odoo URL. */
    public static function cachedFacts(string $url, bool $refresh, callable $fetch): array
    {
        if ($refresh || !array_key_exists($url, self::$factsCache)) {
            self::$factsCache[$url] = $fetch();
        }
        return self::$factsCache[$url];
    }

    /** Forget cached brainFacts() answers (mainly for tests). */
    public static function clearFactsCache(): void
    {
        self::$factsCache = [];
    }

    private static function editionOf(string $version): string
    {
        return str_contains($version, '+e') ? 'enterprise' : 'community';
    }

    private function post(string $path, array $payload, ?int $timeout): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        [$body, $status] = $this->client->request('POST', $path, $json, $timeout);
        return self::parseResult($body, $status);
    }
}
