<?php

declare(strict_types=1);

namespace OdxProxy\Client;

use OdxProxy\Exception\OdxException;
use OdxProxy\Model\KeywordRequest;
use OdxProxy\Utils\IdHelper;

class OdxProxyClient
{
    private OdxClientConfig $config;
    
    /** Local curl timeout in seconds, above the proxy's own upstream timeouts. */
    private const DEFAULT_TIMEOUT = 45;

    // Reuse curl handle for keep-alive connections in the same request
    private static $ch = null;

    public function __construct(OdxClientConfig $config)
    {
        $this->config = $config;
    }

    // --- CONVENIENCE METHODS (idiomatic wrappers over execute) ---

    public function search(string $model, array $domain, ?KeywordRequest $kw = null): array
    {
        return $this->execute('search', $model, [$domain], $kw?->resetPagination());
    }

    public function searchCount(string $model, array $domain, ?KeywordRequest $kw = null): int
    {
        return (int) $this->execute('search_count', $model, [$domain], $kw?->resetPagination());
    }

    public function searchRead(string $model, array $domain, ?KeywordRequest $kw = null): array
    {
        return $this->execute('search_read', $model, [$domain], $kw);
    }

    public function read(string $model, array $ids, ?KeywordRequest $kw = null): array
    {
        return $this->execute('read', $model, [$ids], $kw?->resetPagination());
    }

    public function create(string $model, array $values, ?KeywordRequest $kw = null)
    {
        return $this->execute('create', $model, [$values], $kw?->resetPagination());
    }

    public function write(string $model, array $ids, array $values, ?KeywordRequest $kw = null): bool
    {
        return (bool) $this->execute('write', $model, [$ids, $values], $kw?->resetPagination());
    }

    public function unlink(string $model, array $ids): bool
    {
        return (bool) $this->execute('unlink', $model, [$ids]);
    }

    public function call(string $model, string $method, array $args = [], ?KeywordRequest $kw = null)
    {
        return $this->execute('call_method', $model, $args, $kw, $method);
    }

    /**
     * The v2 API (ODXProxy 0.9.0+ `/v2/odoo/*`, Odoo JSON-2, Odoo 19+) for this
     * client's configuration. Works the same on the global client
     * (`Odx::v2()`) and on a disposable one (`Odx::with($cfg)->v2()`).
     */
    public function v2(): OdxV2Client
    {
        return new OdxV2Client($this, $this->config);
    }

    /**
     * ODXBrain (ODXProxy 0.10.0+ `/brain/v1/*`): which Odoo model holds some data,
     * or which SOP covers a task. Needs no Odoo credentials and never contacts
     * Odoo. Works the same on `Odx::brain()` and `Odx::with($cfg)->brain()`.
     */
    public function brain(): OdxBrainClient
    {
        return new OdxBrainClient($this);
    }

    /**
     * Odoo's version banner over v1 (`POST /api/odoo/version`), e.g.
     * `['server_version' => '19.0+e', 'server_serie' => '19.0', ...]`.
     * Defaults to the configured instance URL. Needs no Odoo credentials.
     */
    public function version(?string $url = null, ?string $requestId = null): array
    {
        $payload = ['id' => $requestId ?? IdHelper::generate(), 'url' => $url ?? $this->config->instanceUrl];
        $result = $this->postEnvelope('/api/odoo/version', $payload);
        return is_array($result) ? $result : [];
    }

    /**
     * The configured instance's facts for ODXBrain, from the v1 version banner:
     * `['odooVersion' => '19.0', 'edition' => 'enterprise'|'community']`. The keys
     * match OdxBrainClient's parameter names, so they spread as named arguments:
     *
     *     $brain->resolveModel('unpaid invoices', ...$client->brainFacts());
     *
     * Cached per Odoo URL for the life of the PHP process. For Odoo 19+ through
     * v2 use `->v2()->brainFacts()`.
     */
    public function brainFacts(bool $refresh = false): array
    {
        return OdxBrainClient::cachedFacts(
            $this->config->instanceUrl,
            $refresh,
            fn() => OdxBrainClient::factsFromV1Version($this->version())
        );
    }

    /**
     * Generic execution method replacing postRequest/postRequestList
     * @return mixed (The 'result' part of the response)
     */
    public function execute(
        string $action,
        string $model,
        array $params,
        ?KeywordRequest $keyword = null,
        ?string $fnName = null,
        ?string $requestId = null
    ) {
        $payload = [
            'id' => $requestId ?? IdHelper::generate(),
            'action' => $action,
            'model_id' => $model,
            'keyword' => $keyword, // JsonSerializable handles nulls
            'fn_name' => $fnName,
            'params' => $params, // PHP Arrays json_encode naturally to [] or {}
            'odoo_instance' => $this->config->toInstanceArray()
        ];

        // Clean nulls from top level to match Kotlin "encodeDefaults = true" behavior logic
        $payload = array_filter($payload, fn($v) => $v !== null);

        [$responseBody, $httpCode] = $this->post('/api/odoo/execute', json_encode($payload));

        if ($httpCode >= 400) {
            // Attempt to parse error from body. v1 keeps the HTTP status as the code and
            // the whole envelope as data; OdxException::rpcCode() reads the envelope code.
            $err = json_decode($responseBody, true);
            $msg = $err['error']['message'] ?? "HTTP Error $httpCode";
            throw new OdxException($httpCode, $msg, is_array($err) ? $err : null, $httpCode);
        }

        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OdxException(500, "JSON Decode Error: " . json_last_error_msg(), null, $httpCode);
        }

        // Handle Protocol Errors
        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            throw new OdxException(
                $err['code'] ?? 500, 
                $err['message'] ?? 'Unknown Error', 
                $err['data'] ?? null,
                $httpCode
            );
        }

        return $decoded['result'] ?? null;
    }

    /**
     * @internal Used by OdxV2Client. POSTs `$payload` to `$path` and returns the
     * envelope's `result`, or throws.
     *
     * Unlike v1's execute(), the code is always the envelope's JSON-RPC code when
     * there is one (e.g. -32000 on a 401, -32007 on a 400, or Odoo's HTTP status
     * on a 200), with the HTTP status in `httpStatus` and Odoo's error object in
     * `data`. Only a non-2xx with no envelope uses the HTTP status as the code.
     */
    public function postEnvelope(string $path, array $payload)
    {
        [$responseBody, $httpCode] = $this->post($path, json_encode($payload));
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $err = $decoded['error'];
            $data = $err['data'] ?? null;
            throw new OdxException(
                is_int($err['code'] ?? null) ? $err['code'] : $httpCode,
                is_string($err['message'] ?? null) ? $err['message'] : 'Unknown Error',
                is_array($data) ? $data : null,
                $httpCode
            );
        }
        if ($httpCode >= 400) {
            throw new OdxException($httpCode, "HTTP Error $httpCode", null, $httpCode);
        }
        if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
            throw new OdxException(500, 'Invalid response from proxy: ' . substr($responseBody, 0, 200), null, $httpCode);
        }
        return $decoded['result'];
    }

    /**
     * POST a JSON body to `{gatewayUrl}{$path}` over the shared curl handle.
     * Returns [body, httpStatus]; throws only when no response arrived.
     *
     * @return array{0: string, 1: int}
     */
    private function post(string $path, string $jsonPayload): array
    {
        return $this->request('POST', $path, $jsonPayload);
    }

    /**
     * @internal Used by OdxBrainClient. Sends one request over the shared curl
     * handle: a POST with `$jsonPayload` as the body, or a GET when it is null.
     * `$requestTimeout` (seconds) is sent as `x-request-timeout`, which bounds the
     * proxy's upstream hop; the local curl timeout is kept above it.
     * Returns [body, httpStatus]; throws only when no response arrived.
     *
     * @return array{0: string, 1: int}
     */
    public function request(string $method, string $path, ?string $jsonPayload = null, ?int $requestTimeout = null): array
    {
        if (self::$ch === null) {
            self::$ch = curl_init();
            curl_setopt_array(self::$ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
        }

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->config->apiKey
        ];
        if ($requestTimeout !== null && $requestTimeout > 0) {
            $headers[] = 'X-Request-Timeout: ' . $requestTimeout;
        }

        curl_setopt(self::$ch, CURLOPT_URL, $this->config->gatewayUrl . $path);
        curl_setopt(self::$ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt(self::$ch, CURLOPT_TIMEOUT, max(self::DEFAULT_TIMEOUT, ($requestTimeout ?? 0) + 5));
        if ($method === 'GET') {
            curl_setopt(self::$ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt(self::$ch, CURLOPT_POST, true);
            curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $jsonPayload ?? '');
        }

        $response = curl_exec(self::$ch);
        $httpCode = curl_getinfo(self::$ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new OdxException(0, 'Curl Error: ' . curl_error(self::$ch));
        }

        return [$response, (int) $httpCode];
    }

    // Allow releasing the connection explicitly if needed.
    // curl_close() has been a no-op since PHP 8.0; dropping the last reference frees the handle.
    public static function close(): void {
        self::$ch = null;
    }
}