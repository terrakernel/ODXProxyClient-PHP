<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Integration;

use OdxProxy\Client\OdxV2Client;
use OdxProxy\Exception\OdxException;
use OdxProxy\Odx;
use PHPUnit\Framework\TestCase;

/**
 * v2 (Odoo JSON-2) end to end against the local `php -S` mock gateway: the exact
 * `/v2/odoo/execute` body each OdxV2Client method sends, and how proxy / Odoo errors
 * surface. The wire shapes are the ODXProxy contract in SYSTEM_ARCHITECTURE.md §4.6.
 */
final class V2RoundTripTest extends TestCase
{
    use MockGatewayServer;

    private const DEFAULT_CONTEXT = ['lang' => 'en_US', 'tz' => 'Asia/Jakarta'];

    protected function setUp(): void
    {
        $this->startGateway();
        OdxV2Client::clearSupportCache();
    }

    protected function tearDown(): void
    {
        $this->stopGateway();
    }

    private function v2(?array $context = self::DEFAULT_CONTEXT): OdxV2Client
    {
        $config = [
            'gateway_url' => $this->baseUrl,
            'gateway_api_key' => 'proxy-key',
            'url' => 'https://erp.example.com',
            'user_id' => 2,
            'db' => 'prod',
            'api_key' => 'odoo-key',
        ];
        if ($context !== null) {
            $config['context'] = $context;
        }
        return Odx::with($config)->v2();
    }

    public function testEnvelopeIsV2ShapedWithNoUserId(): void
    {
        $rows = $this->v2()->searchRead('res.partner', [['is_company', '=', true]], fields: ['name'], limit: 5, requestId: 'req-7');

        self::assertSame([['id' => 1, 'name' => 'Acme']], $rows);
        self::assertSame([
            'id' => 'req-7',
            'model_id' => 'res.partner',
            'method' => 'search_read',
            'kwargs' => [
                'domain' => [['is_company', '=', true]],
                'fields' => ['name'],
                'limit' => 5,
                'context' => self::DEFAULT_CONTEXT,
            ],
            'odoo_instance' => ['url' => 'https://erp.example.com', 'db' => 'prod', 'api_key' => 'odoo-key'],
        ], $this->lastV2Request());
    }

    /** @return iterable<string, array{0: callable, 1: string, 2: array}> */
    public static function methodCases(): iterable
    {
        yield 'search' => [fn(OdxV2Client $c) => $c->search('res.partner', [], offset: 10, limit: 3, order: 'id desc'),
            'search', ['domain' => [], 'offset' => 10, 'limit' => 3, 'order' => 'id desc']];
        yield 'searchCount' => [fn(OdxV2Client $c) => $c->searchCount('res.partner', [['active', '=', true]]),
            'search_count', ['domain' => [['active', '=', true]]]];
        yield 'read' => [fn(OdxV2Client $c) => $c->read('res.partner', [3, 4], fields: ['name']),
            'read', ['ids' => [3, 4], 'fields' => ['name']]];
        yield 'fieldsGet' => [fn(OdxV2Client $c) => $c->fieldsGet('res.partner', allfields: ['name'], attributes: ['type']),
            'fields_get', ['allfields' => ['name'], 'attributes' => ['type']]];
        yield 'create list' => [fn(OdxV2Client $c) => $c->create('res.partner', [['name' => 'A'], ['name' => 'B']]),
            'create', ['vals_list' => [['name' => 'A'], ['name' => 'B']]]];
        yield 'create single record is wrapped' => [fn(OdxV2Client $c) => $c->create('res.partner', ['name' => 'A']),
            'create', ['vals_list' => [['name' => 'A']]]];
        yield 'write' => [fn(OdxV2Client $c) => $c->write('res.partner', [7], ['comment' => 'x']),
            'write', ['ids' => [7], 'vals' => ['comment' => 'x']]];
        yield 'unlink' => [fn(OdxV2Client $c) => $c->unlink('res.partner', [7]),
            'unlink', ['ids' => [7]]];
        yield 'call with ids' => [fn(OdxV2Client $c) => $c->call('account.move', 'action_post', ids: [9]),
            'action_post', ['ids' => [9]]];
        yield 'call with named kwargs' => [fn(OdxV2Client $c) => $c->call('res.partner', 'name_search', ['name' => 'Acm', 'limit' => 5]),
            'name_search', ['name' => 'Acm', 'limit' => 5]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('methodCases')]
    public function testEachMethodSendsOdooParameterNames(callable $call, string $method, array $kwargs): void
    {
        $call($this->v2());
        $sent = $this->lastV2Request();
        self::assertSame($method, $sent['method']);
        self::assertEquals($kwargs + ['context' => self::DEFAULT_CONTEXT], $sent['kwargs']);
    }

    public function testNullArgumentsOmittedAndApiModelMethodsNeverGetIds(): void
    {
        $v2 = $this->v2();
        $v2->searchRead('res.partner');
        self::assertSame(['context' => self::DEFAULT_CONTEXT], $this->lastV2Request()['kwargs']);

        foreach ([
            fn() => $v2->search('res.partner', []),
            fn() => $v2->searchCount('res.partner', []),
            fn() => $v2->fieldsGet('res.partner'),
            fn() => $v2->create('res.partner', ['name' => 'A']),
            fn() => $v2->call('res.partner', 'name_search', ['name' => 'x']),
        ] as $call) {
            $call();
            self::assertArrayNotHasKey('ids', $this->lastV2Request()['kwargs']);
        }
    }

    public function testEmptyKwargsAndEmptyValuesEncodeAsJsonObjects(): void
    {
        $this->v2(context: null)->call('res.users', 'context_get');
        self::assertStringContainsString('"kwargs":{}', $this->lastV2RequestRaw());

        $this->v2(context: null)->write('res.partner', [1], []);
        self::assertStringContainsString('"vals":{}', $this->lastV2RequestRaw());
    }

    public function testCallContextOverridesDefaultContext(): void
    {
        $this->v2()->search('res.partner', [], context: ['tz' => 'UTC', 'allowed_company_ids' => [1]]);
        self::assertSame(
            ['lang' => 'en_US', 'tz' => 'UTC', 'allowed_company_ids' => [1]],
            $this->lastV2Request()['kwargs']['context']
        );
    }

    public function testCreateReturnsListAndCreateOneReturnsInt(): void
    {
        self::assertSame([41, 42], $this->v2()->create('res.partner', [['name' => 'A'], ['name' => 'B']]));
        self::assertSame(41, $this->v2()->createOne('res.partner', ['name' => 'A']));
    }

    public function testFacadeV2UsesTheGlobalInstance(): void
    {
        Odx::init([
            'gateway_url' => $this->baseUrl, 'gateway_api_key' => 'k',
            'url' => 'https://global.example.com', 'db' => 'g', 'api_key' => 'gk',
        ]); // no user_id: not needed for v2
        Odx::v2()->unlink('res.partner', [1]);
        self::assertSame(['url' => 'https://global.example.com', 'db' => 'g', 'api_key' => 'gk'],
            $this->lastV2Request()['odoo_instance']);
    }

    public function testVersionAndIsSupported(): void
    {
        $v2 = $this->v2();
        $info = $v2->version(requestId: 'v-1');
        self::assertSame('20.0+e', $info['version']);
        self::assertSame(['id' => 'v-1', 'url' => 'https://erp.example.com'], $this->lastV2Request());

        self::assertTrue($v2->isSupported());
        self::assertFalse($v2->isSupported('https://old-odoo.test'));   // -32006
        self::assertFalse($v2->isSupported('https://eighteen.test'));   // version_info[0] = 18
    }

    /** @return iterable<string, array{0: string, 1: int, 2: int, 3: string}> */
    public static function errorCases(): iterable
    {
        // model => [statusCode, httpStatus, which helper is true]
        yield 'odoo 422' => ['err.v2.422', 422, 200, 'odoo'];
        yield 'odoo 401' => ['err.v2.401', 401, 200, 'odoo'];
        yield 'json2 unavailable' => ['err.v2.json2', -32006, 200, 'json2'];
        yield 'invalid request' => ['err.v2.invalid', -32007, 400, 'invalid'];
        yield 'license' => ['err.v2.license', 0, 403, 'license'];
        yield 'upstream connect' => ['err.v2.connect', -32004, 502, 'retry'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errorCases')]
    public function testV2Errors(string $model, int $code, int $http, string $flag): void
    {
        try {
            $this->v2()->search($model, []);
            self::fail('expected OdxException');
        } catch (OdxException $e) {
            self::assertSame($code, $e->statusCode);
            self::assertSame($http, $e->httpStatus);
            self::assertSame($flag === 'odoo' ? $code : null, $e->odooStatus());
            self::assertSame($flag === 'json2', $e->isJson2Unavailable());
            self::assertSame($flag === 'invalid', $e->isInvalidRequest());
            self::assertSame($flag === 'license', $e->isLicenseError());
            self::assertSame($flag === 'retry', $e->isRetryable());
        }
    }

    public function testOdooErrorCarriesOdooDataAndName(): void
    {
        try {
            $this->v2()->createOne('err.v2.422', []);
            self::fail('expected OdxException');
        } catch (OdxException $e) {
            self::assertSame('odoo.exceptions.ValidationError', $e->odooErrorName());
            self::assertSame('Name is required', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testLockConflictIsRetryable(): void
    {
        try {
            $this->v2()->write('err.v2.409', [1], ['name' => 'x']);
            self::fail('expected OdxException');
        } catch (OdxException $e) {
            self::assertSame(409, $e->odooStatus());
            self::assertTrue($e->isRetryable());
        }
    }

    public function testProxyBodyRejectionIsNotAnOdooError(): void
    {
        try {
            $this->v2()->search('err.v2.body422', []);
            self::fail('expected OdxException');
        } catch (OdxException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame(422, $e->httpStatus);
            self::assertNull($e->odooStatus());
        }
    }

    public function testV1OdooZeroCodeErrorIsNotALicenseOrTransportError(): void
    {
        // Odoo 19+ /jsonrpc errors are code 0 on HTTP 200 (forwarded by the proxy).
        try {
            Odx::with([
                'gateway_url' => $this->baseUrl, 'gateway_api_key' => 'k',
                'url' => 'https://erp.example.com', 'user_id' => 2, 'db' => 'prod', 'api_key' => 'ok',
            ])->search('err.odoo0', []);
            self::fail('expected OdxException');
        } catch (OdxException $e) {
            self::assertSame(0, $e->statusCode);
            self::assertSame(200, $e->httpStatus);
            self::assertFalse($e->isLicenseError());
            self::assertFalse($e->isTransportError());
        }
    }
}
