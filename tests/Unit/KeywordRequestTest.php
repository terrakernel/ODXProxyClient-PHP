<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Unit;

use OdxProxy\Model\KeywordRequest;
use PHPUnit\Framework\TestCase;

final class KeywordRequestTest extends TestCase
{
    public function testEmptyKeywordSerializesToJsonObjectNotArray(): void
    {
        // Regression: an empty keyword must encode as {} (a JSON object), never []
        // (a JSON array) — the gateway expects `keyword` to be an object.
        self::assertSame('{}', json_encode(new KeywordRequest()));
    }

    public function testResetPaginationSerializesToObject(): void
    {
        $kw = (new KeywordRequest())
            ->setLimit(5)
            ->setOffset(10)
            ->setOrder('id desc')
            ->setFields(['id']);

        self::assertSame('{}', json_encode($kw->resetPagination()));
    }

    public function testResetPaginationKeepsContextButDropsPagination(): void
    {
        $kw = (new KeywordRequest())
            ->setLimit(5)
            ->setOffset(10)
            ->setOrder('id desc')
            ->setFields(['id'])
            ->setContext(['lang' => 'en_US']);

        self::assertSame('{"context":{"lang":"en_US"}}', json_encode($kw->resetPagination()));
    }

    public function testPopulatedSerializationPreservesInsertionOrder(): void
    {
        $kw = (new KeywordRequest())->setFields(['id', 'name'])->setLimit(5);

        self::assertSame('{"fields":["id","name"],"limit":5}', json_encode($kw));
    }

    public function testZeroOffsetIsKeptButNullsAreStripped(): void
    {
        // 0 is not null, so it must survive the null-stripping filter.
        self::assertSame('{"offset":0}', json_encode((new KeywordRequest())->setOffset(0)));
    }

    public function testResetPaginationDoesNotMutateOriginal(): void
    {
        $kw = (new KeywordRequest())->setLimit(5);
        $kw->resetPagination();

        self::assertSame('{"limit":5}', json_encode($kw));
    }
}
