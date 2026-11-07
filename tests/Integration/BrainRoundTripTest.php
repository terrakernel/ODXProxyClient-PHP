<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Integration;

use OdxProxy\Client\OdxBrainClient;
use OdxProxy\Client\OdxProxyClient;
use OdxProxy\Exception\OdxBrainConfigException;
use OdxProxy\Exception\OdxBrainException;
use OdxProxy\Exception\OdxBrainNotInstalledException;
use OdxProxy\Exception\OdxBrainRequestException;
use OdxProxy\Exception\OdxBrainTimeoutException;
use OdxProxy\Exception\OdxBrainUnavailableException;
use OdxProxy\Exception\OdxException;
use OdxProxy\Odx;
use PHPUnit\Framework\TestCase;

/**
 * ODXBrain (`/brain/v1/*`, ODXProxy 0.10.0) end to end against the `php -S` mock
 * gateway: the exact bodies sent, results passed through unaltered, and the error
 * mapping, covering SYSTEM_ARCHITECTURE.md §7.2's definition of done.
 */
final class BrainRoundTripTest extends TestCase
{
    use MockGatewayServer;

    protected function setUp(): void
    {
        $this->startGateway();
        OdxBrainClient::clearFactsCache();
    }

    protected function tearDown(): void
    {
        $this->stopGateway();
    }

    private function client(string $gatewayKey = 'proxy-key', string $odooUrl = 'https://erp.example.com'): OdxProxyClient
    {
        return Odx::with([
            'gateway_url' => $this->baseUrl,
            'gateway_api_key' => $gatewayKey,
            'url' => $odooUrl,
            'user_id' => 2,
            'db' => 'prod',
            'api_key' => 'odoo-key',
        ]);
    }

    private function brain(string $gatewayKey = 'proxy-key'): OdxBrainClient
    {
        return $this->client($gatewayKey)->brain();
    }

    /** @return OdxException the exception $call throws */
    private static function thrown(callable $call): OdxException
    {
        try {
            $call();
        } catch (OdxException $e) {
            return $e;
        }
        self::fail('expected an OdxException');
    }

    // --- Requests --------------------------------------------------------------------

    public function testResolveModelSendsOnlyWhatWasSetAndNoInstance(): void
    {
        $this->brain()->resolveModel('which invoice is not paid yet', requestId: 'r1');
        $sent = $this->lastBrainRequest();

        self::assertSame('POST', $sent['method']);
        self::assertSame('/brain/v1/resolve', $sent['path']);
        self::assertSame('proxy-key', $sent['apiKey']);
        self::assertSame('{"query":"which invoice is not paid yet","id":"r1"}', $sent['body']);
        self::assertNull($sent['timeout']);
    }

    public function testResolveModelWithFactsKAndExtra(): void
    {
        $this->brain()->resolveModel(
            'which invoice is not paid yet',
            odooVersion: '19.0',
            edition: 'enterprise',
            k: 5,
            extra: ['lang' => 'id'],
            requestId: 'r2',
            timeout: 8
        );
        $sent = $this->lastBrainRequest();

        self::assertSame(
            ['lang' => 'id', 'odoo_version' => '19.0', 'edition' => 'enterprise', 'k' => 5,
                'query' => 'which invoice is not paid yet', 'id' => 'r2'],
            json_decode($sent['body'], true)
        );
        self::assertSame('8', $sent['timeout']);
    }

    public function testResolveSopSendsModulesAndCompany(): void
    {
        $this->brain()->resolveSop(
            'record a vendor bill from a purchase order',
            installedModules: ['account', 'purchase'],
            company: 'acme',
            requestId: 's1'
        );
        $sent = $this->lastBrainRequest();

        self::assertSame('/brain/v1/sops/resolve', $sent['path']);
        self::assertSame(
            '{"installed_modules":["account","purchase"],"company":"acme","query":"record a vendor bill from a purchase order","id":"s1"}',
            $sent['body']
        );
    }

    public function testInfoIsABodylessGetWithANullId(): void
    {
        $info = $this->brain()->info();
        $sent = $this->lastBrainRequest();

        self::assertSame('GET', $sent['method']);
        self::assertSame('/brain/v1/info', $sent['path']);
        self::assertSame('', $sent['body']);
        self::assertSame('odxbrain', $info['service']);
        self::assertSame('19.0', $info['corpus']['odoo']['max_version']);
    }

    public function testCurlHandleSwitchesBetweenGetAndPost(): void
    {
        $brain = $this->brain();
        $brain->info();
        $brain->resolveModel('q', requestId: 'after-get');
        self::assertSame('POST', $this->lastBrainRequest()['method']);
        $brain->info();
        self::assertSame('GET', $this->lastBrainRequest()['method']);
        // A v1 call on the same shared handle still POSTs its body (the mock echoes it).
        self::assertSame('search_read', json_decode($this->client()->searchRead('res.partner', [])['raw'], true)['action']);
    }

    // --- Results ---------------------------------------------------------------------

    public function testResultsArePassedThroughUnaltered(): void
    {
        $result = $this->brain()->resolveModel('which invoice is not paid yet');

        // Every candidate, in order, including the low-score provisional one.
        self::assertSame(['account.move', 'account.payment', 'account.payment.term'], array_column($result['candidates'], 'model'));
        self::assertTrue($result['candidates'][2]['provisional']);
        // Conditional and identity fields stay reachable, and unknown fields survive.
        self::assertSame('account.move', $result['excluded'][0]['successor']);
        self::assertSame('19.0', $result['version_unaudited']);
        self::assertSame(['bge-m3', 1024, '2026.09.4'], [$result['model'], $result['dim'], $result['corpus_version']]);
        self::assertSame(['kept' => true], $result['future_field']);
    }

    public function testSopResultKeepsVersionRootAndMatchNone(): void
    {
        $hit = $this->brain()->resolveSop('record a vendor bill from a purchase order');
        self::assertSame('181c84a3b7906648', $hit['candidates'][0]['version']);
        self::assertSame(str_repeat('cd', 32), $hit['sops']['root']);

        $none = $this->brain()->resolveSop('nothing matches');
        self::assertSame('none', $none['match']);
        self::assertSame([], $none['candidates']);
        self::assertSame(['installed_modules'], $none['facts_missing']);
    }

    // --- Availability ----------------------------------------------------------------

    public function testIsAvailableReadsFeaturesAndTreatsMissingAsFalse(): void
    {
        self::assertTrue($this->brain()->isAvailable());
        self::assertFalse($this->brain('no-brain')->isAvailable());
        self::assertFalse($this->brain('old-proxy')->isAvailable());   // pre-0.10.0: no `features`
    }

    // --- Errors ----------------------------------------------------------------------

    public function testNotInstalledAndPre010ProxyRaiseTheSameType(): void
    {
        $e = self::thrown(fn() => $this->brain('no-brain')->resolveModel('q'));
        self::assertInstanceOf(OdxBrainNotInstalledException::class, $e);
        self::assertSame([501, -32008, 'odxbrain_not_installed'], [$e->httpStatus, $e->getCode(), $e->type()]);
        self::assertFalse($e->isRetryable());

        $old = self::thrown(fn() => $this->brain('old-proxy')->info());
        self::assertInstanceOf(OdxBrainNotInstalledException::class, $old);
        self::assertSame([404, -32008], [$old->httpStatus, $old->getCode()]);
    }

    public function testProxyLayerErrors(): void
    {
        $unreachable = self::thrown(fn() => $this->brain()->resolveModel('err.brain.unreachable'));
        self::assertInstanceOf(OdxBrainUnavailableException::class, $unreachable);
        self::assertTrue($unreachable->isRetryable());

        $timeout = self::thrown(fn() => $this->brain()->resolveModel('err.brain.timeout'));
        self::assertInstanceOf(OdxBrainTimeoutException::class, $timeout);
        self::assertTrue($timeout->isRetryable());

        $proxyKey = self::thrown(fn() => $this->brain()->resolveModel('err.brain.proxykey'));
        self::assertInstanceOf(OdxBrainConfigException::class, $proxyKey);
        self::assertFalse($proxyKey->isRetryable());
    }

    public function testCallerAuthAndLicenseStayPlainOdxException(): void
    {
        $auth = self::thrown(fn() => $this->brain('bad-key')->info());
        self::assertSame(OdxException::class, $auth::class);
        self::assertSame([401, OdxException::AUTH_FAILED], [$auth->httpStatus, $auth->getCode()]);

        $license = self::thrown(fn() => $this->brain()->resolveModel('err.brain.license'));
        self::assertSame(OdxException::class, $license::class);
        self::assertTrue($license->isLicenseError());
    }

    public function testBrainOwnErrorsOnHttp200RaiseWithTheirType(): void
    {
        $bad = self::thrown(fn() => $this->brain()->resolveModel('err.brain.400'));
        self::assertInstanceOf(OdxBrainRequestException::class, $bad);
        self::assertSame([200, 400, OdxBrainException::TYPE_INVALID_REQUEST], [$bad->httpStatus, $bad->getCode(), $bad->type()]);
        self::assertSame(400, $bad->brainStatus());
        self::assertNull($bad->odooStatus());   // on /brain/* a 400 is ODXBrain's, not Odoo's

        $version = self::thrown(fn() => $this->brain()->resolveModel('err.brain.version'));
        self::assertSame(OdxBrainException::TYPE_UNSUPPORTED_VERSION, $version->type());

        $noSops = self::thrown(fn() => $this->brain()->resolveSop('err.brain.503'));
        self::assertInstanceOf(OdxBrainUnavailableException::class, $noSops);
        self::assertSame(OdxBrainException::TYPE_SOPS_UNAVAILABLE, $noSops->type());
        self::assertFalse($noSops->isRetryable());

        $internal = self::thrown(fn() => $this->brain()->resolveModel('err.brain.500'));
        self::assertSame(OdxBrainException::class, $internal::class);
        self::assertSame(['message' => 'boom', 'type' => 'internal_error'], $internal->data);
    }

    public function testPlainText422IsNotParsedAsJsonRpc(): void
    {
        $e = self::thrown(fn() => $this->brain()->resolveModel('err.brain.body422'));
        self::assertSame(OdxException::class, $e::class);
        self::assertSame([422, 422], [$e->httpStatus, $e->getCode()]);
        self::assertStringContainsString('missing field `query`', $e->getMessage());
    }

    // --- Facts -----------------------------------------------------------------------

    public function testBrainFactsFromV1AndV2SpreadIntoResolve(): void
    {
        self::assertSame(['odooVersion' => '19.0', 'edition' => 'enterprise'], $this->client()->brainFacts());
        self::assertSame(
            ['odooVersion' => '19.0', 'edition' => 'community'],
            $this->client(odooUrl: 'https://community.test')->brainFacts()
        );

        OdxBrainClient::clearFactsCache();
        $packaged = $this->client(odooUrl: 'https://packaged.test');
        $facts = $packaged->v2()->brainFacts();
        self::assertSame(['odooVersion' => '20.0', 'edition' => 'enterprise'], $facts);
        // version_info[5] is 'e' on the community mock too; edition comes from `version`.
        OdxBrainClient::clearFactsCache();
        self::assertSame('community', $this->client(odooUrl: 'https://community.test')->v2()->brainFacts()['edition']);

        $packaged->brain()->resolveModel('unpaid invoices', ...$facts, requestId: 'f1');
        self::assertSame(
            ['odoo_version' => '20.0', 'edition' => 'enterprise', 'query' => 'unpaid invoices', 'id' => 'f1'],
            json_decode($this->lastBrainRequest()['body'], true)
        );
    }

    public function testBrainFactsAreCachedPerUrl(): void
    {
        $client = $this->client();
        $first = $client->brainFacts();
        $this->stopGateway();               // no proxy now: a cache miss would throw
        self::assertSame($first, $client->brainFacts());
        $this->startGateway();
    }
}
