<?php

declare(strict_types=1);

namespace OdxProxy;

use OdxProxy\Client\OdxBrainClient;
use OdxProxy\Client\OdxClientConfig;
use OdxProxy\Client\OdxProxyClient;
use OdxProxy\Client\OdxV2Client;
use OdxProxy\Model\KeywordRequest;

class Odx
{
    private static ?OdxProxyClient $globalInstance = null;

    /**
     * initializes the "Request Scoped" singleton.
     * Best called in your AppServiceProvider (Laravel) or index.php.
     */
    public static function init(array $config): void
    {
        self::$globalInstance = new OdxProxyClient(OdxClientConfig::create($config));
    }

    /**
     * The "Context Switcher".
     * Returns a client instance for a specific configuration without modifying the Global instance.
     * Useful for Iterating through different users in a Cron Job.
     */
    public static function with(array $config): OdxProxyClient
    {
        return new OdxProxyClient(OdxClientConfig::create($config));
    }

    /**
     * Accessor for the global instance.
     */
    public static function client(): OdxProxyClient
    {
        if (self::$globalInstance === null) {
            throw new \RuntimeException("OdxProxy not initialized. Call Odx::init() globally or use Odx::with() locally.");
        }
        return self::$globalInstance;
    }

    /**
     * The v2 API (ODXProxy 0.9.0+, Odoo JSON-2, Odoo 19+) on the global instance.
     * For a specific tenant use `Odx::with($config)->v2()`.
     */
    public static function v2(): OdxV2Client
    {
        return self::client()->v2();
    }

    /**
     * ODXBrain (ODXProxy 0.10.0+ `/brain/v1/*`) on the global instance. For a
     * specific tenant use `Odx::with($config)->brain()`.
     */
    public static function brain(): OdxBrainClient
    {
        return self::client()->brain();
    }

    // --- PROXY METHODS (Forward calls to the Global Instance) ---

    public static function search(string $model, array $domain, ?KeywordRequest $kw = null): array
    {
        return self::client()->search($model, $domain, $kw);
    }

    public static function searchCount(string $model, array $domain, ?KeywordRequest $kw = null): int
    {
        return self::client()->searchCount($model, $domain, $kw);
    }

    public static function searchRead(string $model, array $domain, ?KeywordRequest $kw = null): array
    {
        return self::client()->searchRead($model, $domain, $kw);
    }

    public static function read(string $model, array $ids, ?KeywordRequest $kw = null): array
    {
        return self::client()->read($model, $ids, $kw);
    }

    public static function create(string $model, array $values, ?KeywordRequest $kw = null)
    {
        return self::client()->create($model, $values, $kw);
    }

    public static function write(string $model, array $ids, array $values, ?KeywordRequest $kw = null): bool
    {
        return self::client()->write($model, $ids, $values, $kw);
    }

    public static function unlink(string $model, array $ids): bool
    {
        return self::client()->unlink($model, $ids);
    }

    public static function call(string $model, string $method, array $args = [], ?KeywordRequest $kw = null)
    {
        return self::client()->call($model, $method, $args, $kw);
    }
}