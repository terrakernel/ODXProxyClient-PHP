<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

/**
 * ODXBrain can't answer. -32009 / HTTP 502: installed but unreachable; one retry
 * after a short delay is safe (isRetryable()). 503 on HTTP 200: ODXBrain is up but lacks
 * that index (type() `corpus_unavailable` or `sops_unavailable`); don't retry.
 */
final class OdxBrainUnavailableException extends OdxBrainException
{
    public function isRetryable(): bool
    {
        return $this->rpcCode() === self::BRAIN_UNREACHABLE;
    }
}
