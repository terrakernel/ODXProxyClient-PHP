<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Unit;

use OdxProxy\Client\OdxV2Client;
use OdxProxy\Exception\OdxException;
use PHPUnit\Framework\TestCase;

final class OdxV2HelpersTest extends TestCase
{
    public function testBuildKwargsDropsNullsAndMergesContextInOrder(): void
    {
        $kwargs = OdxV2Client::buildKwargs(
            ['domain' => [], 'limit' => null, 'context' => ['tz' => 'inline', 'x' => 1]],
            ['lang' => 'en_US', 'tz' => 'default'],
            ['tz' => 'call']
        );
        self::assertSame(
            '{"domain":[],"context":{"lang":"en_US","tz":"call","x":1}}',
            json_encode($kwargs)
        );
    }

    public function testBuildKwargsIsAlwaysAJsonObject(): void
    {
        self::assertSame('{}', json_encode(OdxV2Client::buildKwargs([], null, null)));
        self::assertSame('{}', json_encode(OdxV2Client::buildKwargs(['ids' => null], [], [])));
    }

    public function testRpcCodeReadsTheEnvelopeOnV1NonSuccessResponses(): void
    {
        // v1 puts the HTTP status in statusCode and the whole envelope in data.
        $license = new OdxException(403, 'Invalid License', ['error' => ['code' => 0, 'message' => 'Invalid License']], 403);
        self::assertSame(0, $license->rpcCode());
        self::assertTrue($license->isLicenseError());

        $connect = new OdxException(502, 'down', ['error' => ['code' => -32004, 'message' => 'down']], 502);
        self::assertTrue($connect->isRetryable());
        self::assertNull($connect->odooStatus());
    }

    public function testCodeZeroMeansDifferentThingsByHttpStatus(): void
    {
        $curl = new OdxException(0, 'Curl Error: refused');            // no response at all
        self::assertTrue($curl->isTransportError());
        self::assertFalse($curl->isLicenseError());
        self::assertNull($curl->rpcCode());

        $odoo = new OdxException(0, 'Odoo Server Error', ['name' => 'builtins.ValueError'], 200);
        self::assertFalse($odoo->isTransportError());
        self::assertFalse($odoo->isLicenseError());
        self::assertSame('builtins.ValueError', $odoo->odooErrorName());
    }

    public function testOdooStatusOnlyForOdooErrorsOnHttp200(): void
    {
        self::assertSame(422, (new OdxException(422, 'bad', null, 200))->odooStatus());
        self::assertNull((new OdxException(422, 'body rejected', null, 422))->odooStatus());
        self::assertNull((new OdxException(200, 'v1 Odoo error', null, 200))->odooStatus());
    }

    public function testExistingThreeArgumentConstructorStillWorks(): void
    {
        $e = new OdxException(500, 'x', ['k' => 'v']);
        self::assertSame(500, $e->statusCode);
        self::assertSame(500, $e->getCode());
        self::assertNull($e->httpStatus);
    }
}
