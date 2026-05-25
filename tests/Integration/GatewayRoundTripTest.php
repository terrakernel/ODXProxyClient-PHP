<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Integration;

use OdxProxy\Client\OdxProxyClient;
use OdxProxy\Exception\OdxException;
use OdxProxy\Model\KeywordRequest;
use OdxProxy\Odx;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test of the curl transport + payload building + response parsing,
 * driven against a local `php -S` mock gateway (see server/router.php). No external
 * services or credentials required, so it is safe to run in CI.
 */
final class GatewayRoundTripTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private string $baseUrl = '';

    protected function setUp(): void
    {
        $port = $this->freePort();
        $this->baseUrl = "http://127.0.0.1:{$port}";
        $router = __DIR__ . '/server/router.php';

        $this->proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );

        if (!is_resource($this->proc)) {
            self::fail('could not start mock gateway');
        }

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }

        self::fail('mock gateway did not become ready');
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        OdxProxyClient::close(); // reset the shared static curl handle between tests
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function config(): array
    {
        return [
            'gateway_url' => $this->baseUrl,
            'gateway_api_key' => 'TEST_GATEWAY_KEY',
            'url' => 'https://odoo.example.com',
            'user_id' => 7,
            'db' => 'testdb',
            'api_key' => 'ODOO_USER_KEY',
        ];
    }

    public function testSearchReadRoundTripAndWirePayload(): void
    {
        $kw = (new KeywordRequest())->setFields(['id', 'name'])->setLimit(5);

        $result = Odx::with($this->config())
            ->searchRead('res.partner', [['is_company', '=', true]], $kw);

        // The gateway's `result` was parsed and handed back.
        self::assertSame([['id' => 1, 'name' => 'Acme']], $result['records']);

        // Gateway key travels in the header; correct endpoint was hit.
        self::assertSame('TEST_GATEWAY_KEY', $result['apiKey']);
        self::assertSame('/api/odoo/execute', $result['path']);

        // Inspect the exact bytes that went on the wire.
        $sent = json_decode($result['raw'], true);
        self::assertSame('search_read', $sent['action']);
        self::assertSame('res.partner', $sent['model_id']);
        self::assertSame([[['is_company', '=', true]]], $sent['params']);
        self::assertSame(['fields' => ['id', 'name'], 'limit' => 5], $sent['keyword']);
        self::assertSame([
            'url' => 'https://odoo.example.com',
            'user_id' => 7,
            'db' => 'testdb',
            'api_key' => 'ODOO_USER_KEY',
        ], $sent['odoo_instance']);
        self::assertArrayHasKey('id', $sent);
    }

    public function testEmptyKeywordIsEncodedAsObjectOnTheWire(): void
    {
        // Regression for the [] -> {} bug: search() resets pagination, so a
        // pagination-only keyword becomes empty and must serialize as {} not [].
        $kw = (new KeywordRequest())->setLimit(5)->setOffset(0);

        $result = Odx::with($this->config())->search('res.partner', [], $kw);

        self::assertStringContainsString('"keyword":{}', $result['raw']);
        self::assertStringNotContainsString('"keyword":[]', $result['raw']);
    }

    public function testLogicErrorOn200ThrowsOdxException(): void
    {
        $this->expectException(OdxException::class);
        $this->expectExceptionCode(200);
        $this->expectExceptionMessage('Access Denied');

        try {
            Odx::with($this->config())->searchRead('err.logic', []);
        } catch (OdxException $e) {
            self::assertSame(['name' => 'AccessError'], $e->data);
            throw $e;
        }
    }

    public function testHttpErrorThrowsOdxException(): void
    {
        $this->expectException(OdxException::class);
        $this->expectExceptionCode(401);

        Odx::with($this->config())->searchRead('err.http', []);
    }
}
