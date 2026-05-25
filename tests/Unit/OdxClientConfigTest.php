<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Unit;

use OdxProxy\Client\OdxClientConfig;
use PHPUnit\Framework\TestCase;

final class OdxClientConfigTest extends TestCase
{
    public function testCreateMapsKeysTrimsGatewayAndCastsUserId(): void
    {
        $cfg = OdxClientConfig::create([
            'gateway_url' => 'https://gw.example.com/',
            'gateway_api_key' => 'GKEY',
            'url' => 'https://odoo.example.com',
            'user_id' => '7',
            'db' => 'mydb',
            'api_key' => 'UKEY',
        ]);

        self::assertSame('https://gw.example.com', $cfg->gatewayUrl); // trailing slash trimmed
        self::assertSame('GKEY', $cfg->apiKey);
        self::assertSame('https://odoo.example.com', $cfg->instanceUrl);
        self::assertSame(7, $cfg->userId); // string cast to int
        self::assertSame('mydb', $cfg->db);
        self::assertSame('UKEY', $cfg->instanceApiKey);
    }

    public function testGatewayUrlDefaultsWhenOmitted(): void
    {
        $cfg = OdxClientConfig::create([
            'gateway_api_key' => 'GKEY',
            'url' => 'https://odoo.example.com',
            'user_id' => 1,
            'db' => 'mydb',
            'api_key' => 'UKEY',
        ]);

        self::assertSame('https://gateway.odxproxy.io', $cfg->gatewayUrl);
    }

    public function testToInstanceArrayShapeAndOrder(): void
    {
        $cfg = OdxClientConfig::create([
            'gateway_api_key' => 'GKEY',
            'url' => 'https://odoo.example.com',
            'user_id' => 7,
            'db' => 'mydb',
            'api_key' => 'UKEY',
        ]);

        self::assertSame([
            'url' => 'https://odoo.example.com',
            'user_id' => 7,
            'db' => 'mydb',
            'api_key' => 'UKEY',
        ], $cfg->toInstanceArray());
    }
}
