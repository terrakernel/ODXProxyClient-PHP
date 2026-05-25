<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Unit;

use OdxProxy\Client\OdxProxyClient;
use OdxProxy\Odx;
use PHPUnit\Framework\TestCase;

final class OdxFacadeTest extends TestCase
{
    private const CONFIG = [
        'gateway_api_key' => 'k',
        'url' => 'https://odoo.example.com',
        'user_id' => 1,
        'db' => 'd',
        'api_key' => 'a',
    ];

    protected function setUp(): void
    {
        // Reset the request-scoped singleton so tests don't leak state into each other.
        (new \ReflectionProperty(Odx::class, 'globalInstance'))->setValue(null, null);
    }

    public function testClientThrowsBeforeInit(): void
    {
        $this->expectException(\RuntimeException::class);
        Odx::client();
    }

    public function testInitThenClientReturnsInstance(): void
    {
        Odx::init(self::CONFIG);

        self::assertInstanceOf(OdxProxyClient::class, Odx::client());
    }

    public function testWithReturnsInstanceWithoutSettingGlobal(): void
    {
        $client = Odx::with(self::CONFIG);
        self::assertInstanceOf(OdxProxyClient::class, $client);

        // with() must not touch the global singleton.
        $this->expectException(\RuntimeException::class);
        Odx::client();
    }
}
