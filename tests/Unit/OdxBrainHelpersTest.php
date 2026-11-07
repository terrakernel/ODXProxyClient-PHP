<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Unit;

use OdxProxy\Client\OdxBrainClient;
use OdxProxy\Exception\OdxBrainConfigException;
use OdxProxy\Exception\OdxBrainException;
use OdxProxy\Exception\OdxBrainNotInstalledException;
use OdxProxy\Exception\OdxBrainRequestException;
use OdxProxy\Exception\OdxBrainTimeoutException;
use OdxProxy\Exception\OdxBrainUnavailableException;
use OdxProxy\Exception\OdxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** OdxBrainClient's pure helpers: payload building, error mapping, instance facts. */
final class OdxBrainHelpersTest extends TestCase
{
    public function testPayloadOmitsNullsAndNeverGuessesFacts(): void
    {
        $body = OdxBrainClient::buildPayload('q', ['odoo_version' => null, 'edition' => null, 'k' => null], [], 'r1');

        self::assertSame(['query' => 'q', 'id' => 'r1'], $body);
        self::assertStringNotContainsString('null', json_encode($body));
    }

    public function testPayloadKeepsSetFieldsAndExtraWithNamedFieldsWinning(): void
    {
        $body = OdxBrainClient::buildPayload(
            'q',
            ['odoo_version' => '19.0', 'edition' => 'enterprise', 'k' => 5],
            ['k' => 9, 'query' => 'overridden', 'future_flag' => true, 'dropped' => null],
            'r1'
        );

        self::assertSame(
            ['k' => 5, 'query' => 'q', 'future_flag' => true, 'odoo_version' => '19.0', 'edition' => 'enterprise', 'id' => 'r1'],
            $body
        );
        self::assertArrayNotHasKey('dropped', $body);
    }

    public function testPayloadGeneratesAnIdWhenNoneGiven(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{26}$/', OdxBrainClient::buildPayload('q', [], [], null)['id']);
    }

    public function testExtraMayNotCarryIdOrOdooInstance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('odoo_instance');
        OdxBrainClient::buildPayload('q', [], ['odoo_instance' => ['api_key' => 'secret']], null);
    }

    /** @return iterable<string, array{0: int, 1: int, 2: class-string, 3: bool}> */
    public static function errorCases(): iterable
    {
        // [HTTP status, error.code, expected class, retryable]
        yield '501 not installed' => [501, -32008, OdxBrainNotInstalledException::class, false];
        yield '502 unreachable' => [502, -32009, OdxBrainUnavailableException::class, true];
        yield '504 timeout' => [504, -32010, OdxBrainTimeoutException::class, true];
        yield '502 proxy key rejected' => [502, -32011, OdxBrainConfigException::class, false];
        yield '200 brain 400' => [200, 400, OdxBrainRequestException::class, false];
        yield '200 brain 503' => [200, 503, OdxBrainUnavailableException::class, false];
        yield '200 brain 500' => [200, 500, OdxBrainException::class, false];
        yield '200 brain 422 (unknown status)' => [200, 422, OdxBrainException::class, false];
        yield '401 proxy auth' => [401, -32000, OdxException::class, false];
        yield '403 license' => [403, 0, OdxException::class, false];
    }

    #[DataProvider('errorCases')]
    public function testErrorMapping(int $status, int $code, string $class, bool $retryable): void
    {
        $e = OdxBrainClient::exceptionFor(['code' => $code, 'message' => 'm', 'data' => ['type' => 't']], $status);

        self::assertSame($class, $e::class);
        self::assertSame($code, $e->getCode());
        self::assertSame($code, $e->rpcCode());
        self::assertSame($status, $e->httpStatus);
        self::assertSame($retryable, $e->isRetryable());
        self::assertSame(['type' => 't'], $e->data);
    }

    public function testProxyKeyRejectionIsNotTheCallersAuthError(): void
    {
        $e = OdxBrainClient::exceptionFor(['code' => -32011, 'message' => 'm'], 502);
        self::assertNotSame(OdxException::AUTH_FAILED, $e->rpcCode());
        self::assertInstanceOf(OdxBrainException::class, $e);
    }

    public function testLicenseErrorStaysALicenseError(): void
    {
        $e = OdxBrainClient::exceptionFor(['code' => 0, 'message' => 'Invalid License'], 403);
        self::assertTrue($e->isLicenseError());
        self::assertNotInstanceOf(OdxBrainException::class, $e);
    }

    public function testBareNotFoundMeansNotInstalled(): void
    {
        try {
            OdxBrainClient::parseResult('', 404);
            self::fail('expected OdxBrainNotInstalledException');
        } catch (OdxBrainNotInstalledException $e) {
            self::assertSame(OdxException::BRAIN_NOT_INSTALLED, $e->getCode());
            self::assertSame(404, $e->httpStatus);
            self::assertFalse($e->isRetryable());
        }
    }

    public function testPlainTextRejectionIsNotParsedAsJsonRpc(): void
    {
        foreach ([400 => 'Failed to parse the request body as JSON', 422 => 'missing field `query`'] as $status => $text) {
            try {
                OdxBrainClient::parseResult($text, $status);
                self::fail('expected OdxException');
            } catch (OdxException $e) {
                self::assertSame(OdxException::class, $e::class);
                self::assertSame($status, $e->getCode());
                self::assertStringContainsString($text, $e->getMessage());
            }
        }
    }

    public function testErrorOnHttp200RaisesInsteadOfReturningEmpty(): void
    {
        $this->expectException(OdxBrainUnavailableException::class);
        OdxBrainClient::parseResult(json_encode(['jsonrpc' => '2.0', 'id' => 'x', 'error' => [
            'code' => 503, 'message' => 'no index', 'data' => ['message' => 'no index', 'type' => 'corpus_unavailable'],
        ]]), 200);
    }

    public function testNullIdDoesNotBreakParsing(): void
    {
        $result = OdxBrainClient::parseResult('{"jsonrpc":"2.0","id":null,"result":{"service":"odxbrain"}}', 200);
        self::assertSame(['service' => 'odxbrain'], $result);
    }

    public function testFactsFromV1Version(): void
    {
        self::assertSame(
            ['odooVersion' => '17.0', 'edition' => 'enterprise'],
            OdxBrainClient::factsFromV1Version(['server_serie' => '17.0', 'server_version' => '17.0+e'])
        );
        self::assertSame(
            ['odooVersion' => 'saas~16.3', 'edition' => 'community'],
            OdxBrainClient::factsFromV1Version(['server_serie' => 'saas~16.3', 'server_version' => 'saas~16.3'])
        );
    }

    public function testFactsFromV2VersionKeepsTheWholeVersionAndIgnoresVersionInfo5(): void
    {
        self::assertSame(
            ['odooVersion' => '20.0', 'edition' => 'enterprise'],
            OdxBrainClient::factsFromV2Version(['version_info' => [20, 0, 0, 'final', 0, ''], 'version' => '20.0+e-20260925'])
        );
        self::assertSame(
            ['odooVersion' => '20.0', 'edition' => 'enterprise'],
            OdxBrainClient::factsFromV2Version(['version_info' => [20, 0, 0, 'alpha', 1, ''], 'version' => '20.0alpha1+e'])
        );
        self::assertSame(
            ['odooVersion' => 'saas~18.2', 'edition' => 'community'],
            OdxBrainClient::factsFromV2Version(['version_info' => ['saas~18', 2, 0, 'final', 0, 'e'], 'version' => 'saas~18.2'])
        );
    }

    public function testFactsRejectAMalformedVersionResult(): void
    {
        $this->expectException(OdxException::class);
        OdxBrainClient::factsFromV2Version(['version' => '20.0+e']);
    }
}
