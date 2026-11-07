<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

/**
 * 400 on HTTP 200: ODXBrain rejected the input (empty query, bad edition, k < 1, an
 * Odoo version below 14.0); see type(). Fix the call, don't retry.
 */
final class OdxBrainRequestException extends OdxBrainException
{
}
