<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Live;

use OdxProxy\Model\KeywordRequest;
use OdxProxy\Odx;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in tests against a REAL ODXProxy gateway + Odoo instance.
 *
 * These are NOT part of the default suite (they live outside the unit/integration
 * testsuites in phpunit.xml.dist). Run them explicitly with:  composer test:live
 *
 * They self-skip unless every ODX_* env var is set. Supply credentials via a
 * gitignored `phpunit.xml` override, shell exports, or CI secrets — NEVER commit
 * them. See phpunit.xml.dist for the variable names.
 *
 * Keep every assertion here strictly READ-ONLY. Do not add create/write/unlink
 * calls — this may point at production data.
 */
#[Group('live')]
final class GatewayLiveTest extends TestCase
{
    /** @return array<string,string> */
    private function liveConfig(): array
    {
        $required = [
            'gateway_url' => 'ODX_GATEWAY_URL',
            'gateway_api_key' => 'ODX_GATEWAY_API_KEY',
            'url' => 'ODX_ODOO_URL',
            'user_id' => 'ODX_USER_ID',
            'db' => 'ODX_DB',
            'api_key' => 'ODX_API_KEY',
        ];

        $config = [];
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
            self::markTestSkipped('live gateway creds not configured; missing: ' . implode(', ', $missing));
        }

        return $config;
    }

    public function testSearchCountReturnsNonNegativeInt(): void
    {
        $count = Odx::with($this->liveConfig())->searchCount('res.partner', []);

        self::assertGreaterThanOrEqual(0, $count);
    }

    public function testSearchReadReturnsRowsWithRequestedFields(): void
    {
        $kw = (new KeywordRequest())->setFields(['id', 'name'])->setLimit(1);

        $rows = Odx::with($this->liveConfig())->searchRead('res.partner', [], $kw);

        self::assertIsArray($rows);
        if ($rows !== []) {
            self::assertArrayHasKey('id', $rows[0]);
        }
    }
}
