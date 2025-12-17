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

        $responseBody = $this->sendViaCurl(json_encode($payload));
        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OdxException(500, "JSON Decode Error: " . json_last_error_msg());
        }

        // Handle Protocol Errors
        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            throw new OdxException(
                $err['code'] ?? 500, 
                $err['message'] ?? 'Unknown Error', 
                $err['data'] ?? null
            );
        }

        return $decoded['result'] ?? null;
    }

    private function sendViaCurl(string $jsonPayload): string
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

        curl_setopt(self::$ch, CURLOPT_URL, $this->config->gatewayUrl . '/api/odoo/execute');
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

        if ($httpCode >= 400) {
            // Attempt to parse error from body
            $err = json_decode($response, true);
            $msg = $err['error']['message'] ?? "HTTP Error $httpCode";
            throw new OdxException($httpCode, $msg, $err);
        }

        return $response;
    }
    
    // Allow closing resource explicitly if needed
    public static function close(): void {
        if (self::$ch) {
            curl_close(self::$ch);
            self::$ch = null;
        }
    }
}