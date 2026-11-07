<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

/**
 * -32011 / HTTP 502: ODXBrain rejected the **proxy's** own key. The caller's key was
 * accepted; the operator's ODXBrain key is wrong. Deliberately not an auth error: rotating
 * the caller's key won't help. Never retry.
 */
final class OdxBrainConfigException extends OdxBrainException
{
}
