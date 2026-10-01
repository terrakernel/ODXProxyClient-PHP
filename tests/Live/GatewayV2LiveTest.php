<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Live;

use OdxProxy\Client\OdxV2Client;
use OdxProxy\Exception\OdxException;
use OdxProxy\Odx;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in v2 (Odoo JSON-2) tests against a REAL ODXProxy 0.9.0+ in front of Odoo 19+.
 * Run with `composer test:live`.
 *
 * They self-skip unless the ODX_V2_* env vars are set. These are separate from
 * GatewayLiveTest's ODX_* vars, so a production config never runs them by accident:
 *   ODX_V2_GATEWAY_URL, ODX_V2_API_KEY, ODX_V2_ODOO_URL, ODX_V2_ODOO_DB, ODX_V2_ODOO_API_KEY
 *
 * Read-only by default. The create/write/unlink lifecycle also needs
 * ODX_V2_ALLOW_WRITES=1, because it creates and deletes res.partner records.
 */
#[Group('live')]
final class GatewayV2LiveTest extends TestCase
{
    private function v2(): OdxV2Client
    {
        $required = [
            'gateway_url' => 'ODX_V2_GATEWAY_URL',
            'gateway_api_key' => 'ODX_V2_API_KEY',
            'url' => 'ODX_V2_ODOO_URL',
            'db' => 'ODX_V2_ODOO_DB',
            'api_key' => 'ODX_V2_ODOO_API_KEY',
        ];
        $config = ['context' => ['lang' => 'en_US']];
        $missing = [];
        foreach ($required as $key => $env) {
            $value = getenv($env);
            if ($value === false || $value === '') {
                $missing[] = $env;
                continue;
            }
            $config[$key] = $value;
        }
        if ($missing !== []) {
            self::markTestSkipped('v2 live creds not configured; missing: ' . implode(', ', $missing));
        }
        return Odx::with($config)->v2();
    }

    private static function odooStatusOf(callable $call): ?int
    {
        try {
            $call();
        } catch (OdxException $e) {
            return $e->odooStatus();
        }
        self::fail('expected OdxException');
    }

    public function testVersionAndIsSupported(): void
    {
        $v2 = $this->v2();
        self::assertGreaterThanOrEqual(19, $v2->version()['version_info'][0]);
        self::assertTrue($v2->isSupported());
    }

    public function testReadsAgreeWithEachOther(): void
    {
        $v2 = $this->v2();
        $rows = $v2->searchRead('res.partner', [], fields: ['name'], limit: 3, order: 'id asc');
        self::assertNotEmpty($rows);
        $ids = array_column($rows, 'id');
        self::assertSame($ids, $v2->search('res.partner', [], limit: 3, order: 'id asc'));
        self::assertSame(count($ids), $v2->searchCount('res.partner', [['id', 'in', $ids]]));
        self::assertSame($ids[0], $v2->read('res.partner', [$ids[0]], fields: ['name'])[0]['id']);
        self::assertSame('char', $v2->fieldsGet('res.partner', allfields: ['name'], attributes: ['type'])['name']['type']);
        self::assertArrayHasKey('lang', $v2->call('res.users', 'context_get'));
    }

    public function testOdooErrorsCarryOdooStatus(): void
    {
        $v2 = $this->v2();
        self::assertSame(422, self::odooStatusOf(fn() => $v2->call('res.partner', 'search', ['domain' => []], ids: [1])));
        self::assertSame(422, self::odooStatusOf(fn() => $v2->call('res.partner', 'search_count', ['filter' => []])));
        self::assertSame(404, self::odooStatusOf(fn() => $v2->searchCount('no.such.model', [])));
        self::assertSame(403, self::odooStatusOf(fn() => $v2->call('res.partner', '_compute_display_name', ids: [1])));
        try {
            $v2->searchCount('..', []);
            self::fail('expected OdxException');
        } catch (OdxException $e) {
            self::assertTrue($e->isInvalidRequest());
        }
    }

    public function testWriteLifecycle(): void
    {
        $v2 = $this->v2();
        if (getenv('ODX_V2_ALLOW_WRITES') !== '1') {
            self::markTestSkipped('set ODX_V2_ALLOW_WRITES=1 to create/delete records');
        }
        $id = $v2->createOne('res.partner', ['name' => 'ODX v2 PHP Test Partner']);
        try {
            self::assertTrue($v2->write('res.partner', [$id], ['name' => 'ODX v2 PHP Test Partner (edited)']));
            self::assertSame('ODX v2 PHP Test Partner (edited)', $v2->read('res.partner', [$id], fields: ['name'])[0]['name']);
            $many = $v2->create('res.partner', [['name' => 'ODX v2 A'], ['name' => 'ODX v2 B']]);
            self::assertCount(2, $many);
            self::assertTrue($v2->unlink('res.partner', $many));
        } finally {
            self::assertTrue($v2->unlink('res.partner', [$id]));
        }
        self::assertSame(0, $v2->searchCount('res.partner', [['id', '=', $id]]));
    }
}
