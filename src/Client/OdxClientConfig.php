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
    /**
     * v2 only (Odx::v2()): Odoo context merged into every v2 call (lang, tz,
     * allowed_company_ids, ...); a call's own context keys win. Odoo applies no
     * company selection unless allowed_company_ids is sent. v1 calls ignore it.
     */
    public ?array $defaultContext;

    public function __construct(
        string $gatewayUrl,
        string $apiKey,
        string $instanceUrl,
        int $userId,
        string $db,
        string $instanceApiKey,
        ?array $defaultContext = null
    ) {
        $this->gatewayUrl = rtrim($gatewayUrl, '/');
        $this->apiKey = $apiKey;
        $this->instanceUrl = $instanceUrl;
        $this->userId = $userId;
        $this->db = $db;
        $this->instanceApiKey = $instanceApiKey;
        $this->defaultContext = $defaultContext;
    }

    /**
     * Helper to simplify initialization from Database rows or Env vars.
     * `user_id` is required by v1 only; v2 derives the user from the API key.
     * `context` is the optional v2 default context.
     */
    public static function create(array $config): self
    {
        return new self(
            $config['gateway_url'] ?? 'https://gateway.odxproxy.io', // Default logic
            $config['gateway_api_key'],
            $config['url'],
            (int)($config['user_id'] ?? 0),
            $config['db'],
            $config['api_key'],
            $config['context'] ?? null
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

    /** `odoo_instance` for v2: no user_id, because JSON-2 derives the user from the API key. */
    public function toV2InstanceArray(): array
    {
        return [
            'url' => $this->instanceUrl,
            'db' => $this->db,
            'api_key' => $this->instanceApiKey
        ];
    }
}
