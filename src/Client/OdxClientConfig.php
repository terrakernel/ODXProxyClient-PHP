<?php

declare(strict_types=1);

namespace OdxProxy\Client;

class OdxClientConfig
{
    public string $gatewayUrl;
    public string $apiKey;
    public string $instanceUrl;
    public int $userId;
    public string $db;
    public string $instanceApiKey;

    public function __construct(
        string $gatewayUrl,
        string $apiKey,
        string $instanceUrl,
        int $userId,
        string $db,
        string $instanceApiKey
    ) {
        $this->gatewayUrl = rtrim($gatewayUrl, '/');
        $this->apiKey = $apiKey;
        $this->instanceUrl = $instanceUrl;
        $this->userId = $userId;
        $this->db = $db;
        $this->instanceApiKey = $instanceApiKey;
    }

    /** 
     * Helper to simplify initialization from Database rows or Env vars 
     */
    public static function create(array $config): self
    {
        return new self(
            $config['gateway_url'] ?? 'https://gateway.odxproxy.io', // Default logic
            $config['gateway_api_key'],
            $config['url'],
            (int)$config['user_id'],
            $config['db'],
            $config['api_key']
        );
    }

    public function toInstanceArray(): array
    {
        return [
            'url' => $this->instanceUrl,
            'user_id' => $this->userId,
            'db' => $this->db,
            'api_key' => $this->instanceApiKey
        ];
    }
}