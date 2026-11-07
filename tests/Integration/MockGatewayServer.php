<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Integration;

use OdxProxy\Client\OdxProxyClient;

/**
 * Boots the `php -S` mock gateway (server/router.php) on a free port for one test.
 * Same lifecycle as GatewayRoundTripTest; v2 tests read the last v2 request body
 * from lastV2Request().
 */
trait MockGatewayServer
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private string $baseUrl = '';
    private int $port = 0;

    private function startGateway(): void
    {
        $this->port = $this->freePort();
        $this->baseUrl = "http://127.0.0.1:{$this->port}";
        @unlink($this->rawFile());
        @unlink($this->brainFile());

        $this->proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/server/router.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );
        if (!is_resource($this->proc)) {
            self::fail('could not start mock gateway');
        }

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }
        self::fail('mock gateway did not become ready');
    }

    private function stopGateway(): void
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
        @unlink($this->rawFile());
        @unlink($this->brainFile());
        OdxProxyClient::close(); // reset the shared static curl handle between tests
    }

    private function rawFile(): string
    {
        return sys_get_temp_dir() . "/odx-mock-v2-{$this->port}.json";
    }

    private function brainFile(): string
    {
        return sys_get_temp_dir() . "/odx-mock-brain-{$this->port}.json";
    }

    /**
     * The last /brain request the mock gateway received:
     * `['method', 'path', 'body' (raw string), 'timeout' (x-request-timeout or null), 'apiKey']`.
     */
    private function lastBrainRequest(): array
    {
        return json_decode((string) file_get_contents($this->brainFile()), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The decoded body of the last v2 request the mock gateway received. */
    private function lastV2Request(): array
    {
        return json_decode((string) file_get_contents($this->rawFile()), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The raw bytes of the last v2 request (to check `{}` vs `[]`). */
    private function lastV2RequestRaw(): string
    {
        return (string) file_get_contents($this->rawFile());
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
