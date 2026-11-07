<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

/**
 * -32008 / HTTP 501: this proxy has no ODXBrain. Also thrown for a bare HTTP 404 on a
 * `/brain/*` route, from a proxy that predates ODXBrain (before 0.10.0). A fact about the
 * deployment: hide the feature, never retry.
 */
final class OdxBrainNotInstalledException extends OdxBrainException
{
}
