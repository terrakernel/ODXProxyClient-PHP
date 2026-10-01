<?php

declare(strict_types=1);

namespace OdxProxy\Client;

use OdxProxy\Exception\OdxException;
use OdxProxy\Model\KeywordRequest;
use OdxProxy\Utils\IdHelper;

class OdxProxyClient
{
    private OdxClientConfig $config;
    
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
        if (self::$ch === null) {
            self::$ch = curl_init();
            curl_setopt_array(self::$ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json'
                ],
            ]);
        }

        curl_setopt(self::$ch, CURLOPT_URL, $this->config->gatewayUrl . $path);
        curl_setopt(self::$ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->config->apiKey
        ]);
        curl_setopt(self::$ch, CURLOPT_POST, true);
        curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $jsonPayload);

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