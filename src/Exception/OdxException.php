<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

class OdxException extends \RuntimeException
{
    /** Proxy error codes (SYSTEM_ARCHITECTURE.md §6). */
    public const LICENSE_INVALID = 0;
    public const AUTH_FAILED = -32000;
    public const INVALID_ACTION = -32001;
    public const MISSING_FN_NAME = -32002;
    public const UPSTREAM_TIMEOUT = -32003;
    public const UPSTREAM_CONNECT = -32004;
    public const PROXY_INTERNAL = -32005;
    public const JSON2_UNAVAILABLE = -32006;
    public const INVALID_REQUEST = -32007;
    /** ODXBrain (`/brain/*`, ODXProxy 0.10.0+); see OdxBrainException and its subclasses. */
    public const BRAIN_NOT_INSTALLED = -32008;
    public const BRAIN_UNREACHABLE = -32009;
    public const BRAIN_TIMEOUT = -32010;
    public const BRAIN_MISCONFIGURED = -32011;

    public int $statusCode;
    public ?array $data;

    /**
     * HTTP status of the proxy response that carried the error, or null when no
     * response arrived (curl failure). Odoo-side errors arrive on HTTP 200;
     * proxy-layer failures use other statuses.
     */
    public ?int $httpStatus;

    public function __construct(int $code, string $message, ?array $data = null, ?int $httpStatus = null)
    {
        parent::__construct($message, $code);
        $this->statusCode = $code;
        $this->data = $data;
        $this->httpStatus = $httpStatus;
    }

    /**
     * The JSON-RPC error code, whichever path built this exception. For non-2xx
     * responses the v1 client puts the HTTP status in `statusCode` and keeps the
     * whole envelope in `data`; this reads the envelope's code in that case. Null
     * for a curl failure.
     */
    public function rpcCode(): ?int
    {
        $envelopeCode = $this->data['error']['code'] ?? null;
        if (is_int($envelopeCode)) {
            return $envelopeCode;
        }
        return $this->httpStatus === null ? null : $this->statusCode;
    }

    /**
     * For an Odoo-side error, the HTTP status Odoo answered with, which the proxy
     * forwards as the code: 401 Odoo API key invalid/expired, 403 access rights or
     * a private method, 404 unknown model/method or missing record, 409 lock
     * conflict, 422 validation error or bad arguments, 5xx Odoo server error.
     * Always set on v2 (Odx::v2()); on v1 only when Odoo itself answered non-2xx.
     */
    public function odooStatus(): ?int
    {
        return $this->httpStatus === 200 && $this->statusCode >= 400 && $this->statusCode <= 599
            ? $this->statusCode
            : null;
    }

    /** Odoo's exception class from `data['name']` (e.g. "odoo.exceptions.ValidationError"), when present. */
    public function odooErrorName(): ?string
    {
        $name = $this->data['name'] ?? null;
        return is_string($name) ? $name : null;
    }

    /**
     * The proxy's license is expired or invalid: code 0 on HTTP 403. Odoo 19+ also
     * sends code 0 for every /jsonrpc error (on HTTP 200), and a curl failure is
     * code 0 as well, so `statusCode === 0` alone means neither.
     */
    public function isLicenseError(): bool
    {
        return $this->httpStatus === 403 && $this->rpcCode() === self::LICENSE_INVALID;
    }

    /** v2: no JSON-2 on that Odoo (18 or older, use v1), or the database is not selectable on that host (dbfilter). */
    public function isJson2Unavailable(): bool
    {
        return $this->rpcCode() === self::JSON2_UNAVAILABLE;
    }

    /** v2: invalid model/method name, or db/api_key not valid as an HTTP header. Odoo was not contacted. */
    public function isInvalidRequest(): bool
    {
        return $this->rpcCode() === self::INVALID_REQUEST;
    }

    /** No response from the proxy at all (DNS, TCP, TLS, local timeout). */
    public function isTransportError(): bool
    {
        return $this->httpStatus === null;
    }

    /**
     * Whether retrying the same call (with backoff) can succeed: the proxy could
     * not reach Odoo, or an Odoo lock conflict (409). Timeouts are excluded because
     * the upstream call may already have run.
     */
    public function isRetryable(): bool
    {
        return $this->rpcCode() === self::UPSTREAM_CONNECT || $this->odooStatus() === 409;
    }
}
