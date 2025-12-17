<?php

declare(strict_types=1);

namespace OdxProxy\Utils;

class IdHelper
{
    /**
     * Generates a random ID to replace ULID (Standard PHP doesn't have ULID native).
     * This is sufficient for request correlation.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(13));
    }

    /**
     * Handles the complex logic of Odoo IDs (can be int, string, false, or array).
     * Returns string or null.
     * @param mixed $value
     */
    public static function normalizeId($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        if (is_array($value) && !empty($value)) {
            // Handle Many2One [id, "name"]
            return (string)$value[0];
        }
        return (string)$value;
    }
}