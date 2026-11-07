<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

/**
 * -32010 / HTTP 504: the proxy's hop to ODXBrain timed out. Both resolves are
 * read-only, so one retry is safe.
 */
final class OdxBrainTimeoutException extends OdxBrainException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
