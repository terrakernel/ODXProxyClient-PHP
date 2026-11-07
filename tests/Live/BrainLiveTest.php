<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Live;

use OdxProxy\Client\OdxBrainClient;
use OdxProxy\Client\OdxProxyClient;
use OdxProxy\Exception\OdxBrainException;
use OdxProxy\Exception\OdxBrainNotInstalledException;
use OdxProxy\Exception\OdxBrainUnavailableException;
use OdxProxy\Odx;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in ODXBrain tests against a REAL ODXProxy 0.10.0+. Run with `composer test:live`.
 * Read-only: /brain/* never contacts Odoo, and the facts test only reads Odoo's version.
 *
 * They self-skip unless the ODX_BRAIN_* env vars are set (separate from ODX_* / ODX_V2_*):
 *   ODX_BRAIN_GATEWAY_URL, ODX_BRAIN_API_KEY   proxy with ODXBrain (+ SOPs)
 *   ODX_BRAIN_NOBRAIN_URL                      optional: a proxy without ODXBrain
 *   ODX_BRAIN_NOSOPS_URL                       optional: ODXBrain without an SOP index
 *   ODX_BRAIN_ODOO_URL, ODX_BRAIN_ODOO_DB, ODX_BRAIN_ODOO_API_KEY   optional: for brainFacts()
 */
#[Group('live')]
final class BrainLiveTest extends TestCase
{
    protected function setUp(): void
    {
        OdxBrainClient::clearFactsCache();
    }

    protected function tearDown(): void
    {
        OdxProxyClient::close();
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? null : $value;
    }

    private function client(string $urlVar = 'ODX_BRAIN_GATEWAY_URL', bool $withOdoo = false): OdxProxyClient
    {
        $gateway = self::env($urlVar);
        $key = self::env('ODX_BRAIN_API_KEY');
        if ($gateway === null || $key === null) {
            self::markTestSkipped("ODXBrain live config not set; needs $urlVar and ODX_BRAIN_API_KEY");
        }
        $odoo = ['url' => 'https://unused.invalid', 'db' => 'unused', 'api_key' => 'unused'];
        if ($withOdoo) {
            foreach (['url' => 'ODX_BRAIN_ODOO_URL', 'db' => 'ODX_BRAIN_ODOO_DB', 'api_key' => 'ODX_BRAIN_ODOO_API_KEY'] as $k => $var) {
                $odoo[$k] = self::env($var) ?? self::markTestSkipped("brainFacts live test needs $var");
            }
        }
        return Odx::with(['gateway_url' => $gateway, 'gateway_api_key' => $key] + $odoo);
    }

    public function testAvailabilityAndInfo(): void
    {
        $brain = $this->client()->brain();
        self::assertTrue($brain->isAvailable());
        $info = $brain->info();
        self::assertSame('odxbrain', $info['service']);
        self::assertTrue($info['corpus']['loaded']);
    }

    public function testResolveModelAndSop(): void
    {
        $brain = $this->client()->brain();

        $models = $brain->resolveModel('which invoice is not paid yet', odooVersion: '19.0', edition: 'enterprise', requestId: 'live-r1');
        self::assertNotEmpty($models['candidates']);
        self::assertContains('account.move', array_column($models['candidates'], 'model'));
        self::assertIsString($models['corpus_version']);

        $sops = $brain->resolveSop('record a vendor bill from a purchase order', installedModules: ['account', 'purchase']);
        self::assertTrue(isset($sops['match']) || $sops['candidates'] !== []);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sops['sops']['root']);
    }

    public function testBrainRejectsBadInputOnHttp200(): void
    {
        try {
            $this->client()->brain()->resolveModel('anything', k: 0);
            self::fail('expected ODXBrain to reject k = 0');
        } catch (OdxBrainException $e) {
            self::assertSame(200, $e->httpStatus);
            self::assertSame(400, $e->brainStatus());
            self::assertNotNull($e->type());
        }
    }

    public function testProxyWithoutBrain(): void
    {
        $brain = $this->client('ODX_BRAIN_NOBRAIN_URL')->brain();
        self::assertFalse($brain->isAvailable());
        $this->expectException(OdxBrainNotInstalledException::class);
        $brain->resolveModel('which invoice is not paid yet');
    }

    public function testBrainWithoutSops(): void
    {
        $brain = $this->client('ODX_BRAIN_NOSOPS_URL')->brain();
        self::assertNotEmpty($brain->resolveModel('which invoice is not paid yet')['candidates']);
        try {
            $brain->resolveSop('record a vendor bill from a purchase order');
            self::fail('expected sops_unavailable');
        } catch (OdxBrainUnavailableException $e) {
            self::assertSame(OdxBrainException::TYPE_SOPS_UNAVAILABLE, $e->type());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testBrainFactsFromBothApis(): void
    {
        $client = $this->client(withOdoo: true);
        $v1 = $client->brainFacts();
        OdxBrainClient::clearFactsCache();
        $v2 = $client->v2()->brainFacts();

        self::assertSame($v1, $v2);
        self::assertContains($v2['edition'], ['community', 'enterprise']);
        self::assertNotEmpty($client->brain()->resolveModel('which invoice is not paid yet', ...$v2)['candidates']);
    }
}
