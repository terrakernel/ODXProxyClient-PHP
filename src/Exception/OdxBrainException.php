<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

/**
 * Base for every ODXBrain error on a `/brain/*` route, and itself thrown for
 * ODXBrain's own errors that have no narrower class (HTTP 200, code 500).
 *
 * On HTTP 200 a code from 100 to 599 is **ODXBrain's** HTTP status, not Odoo's,
 * and `data` is ODXBrain's error object `{message, type}`. Branch on type(),
 * never on the message. The proxy's own refusals on these routes (401 -32000,
 * 403 license) are not brain errors and stay plain OdxException.
 *
 * Subclasses:
 * - OdxBrainNotInstalledException: -32008 / 501, or a bare 404 (proxy predates 0.10.0). Never retry.
 * - OdxBrainUnavailableException:  -32009 / 502 (retry once), or 503 on HTTP 200 (index not loaded, don't retry).
 * - OdxBrainTimeoutException:      -32010 / 504. Retry once.
 * - OdxBrainConfigException:       -32011 / 502: ODXBrain rejected the *proxy's* key. Not the caller's auth.
 * - OdxBrainRequestException:      400 on HTTP 200: ODXBrain rejected the input. Fix the call.
 */
class OdxBrainException extends OdxException
{
    /** ODXBrain's frozen error types (`data.type`). */
    public const TYPE_INVALID_REQUEST = 'invalid_request_error';
    public const TYPE_MODEL_MISMATCH = 'model_mismatch';
    public const TYPE_UNSUPPORTED_VERSION = 'unsupported_version';
    public const TYPE_EMBEDDING_ERROR = 'embedding_error';
    public const TYPE_RESOLVE_ERROR = 'resolve_error';
    public const TYPE_CORPUS_UNAVAILABLE = 'corpus_unavailable';
    public const TYPE_SOPS_UNAVAILABLE = 'sops_unavailable';
    public const TYPE_INTERNAL_ERROR = 'internal_error';
    public const TYPE_NOT_INSTALLED = 'odxbrain_not_installed';

    /**
     * ODXBrain's error type from `data['type']` (one of the TYPE_* constants, or a
     * newer one). Null when the error carries none (most proxy-layer codes).
     */
    public function type(): ?string
    {
        $type = $this->data['type'] ?? null;
        return is_string($type) ? $type : null;
    }

    /**
     * ODXBrain's own HTTP status (400, 503, 500, ...) for an error it raised itself,
     * which the proxy forwards as the code on HTTP 200. Null for proxy-layer codes.
     */
    public function brainStatus(): ?int
    {
        return $this->httpStatus === 200 && $this->statusCode >= 100 && $this->statusCode <= 599
            ? $this->statusCode
            : null;
    }

    /** Always null: on `/brain/*` a 100-599 code is ODXBrain's status (brainStatus()), never Odoo's. */
    public function odooStatus(): ?int
    {
        return null;
    }

    /** Brain errors are not retryable unless a subclass says so. */
    public function isRetryable(): bool
    {
        return false;
    }
}
