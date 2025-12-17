<?php

declare(strict_types=1);

namespace OdxProxy\Exception;

class OdxException extends \RuntimeException
{
    public int $statusCode;
    public ?array $data;

    public function __construct(int $code, string $message, ?array $data = null)
    {
        parent::__construct($message, $code);
        $this->statusCode = $code;
        $this->data = $data;
    }
}