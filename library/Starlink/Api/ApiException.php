<?php

namespace Icinga\Module\Starlink\Api;

use RuntimeException;

class ApiException extends RuntimeException
{
    /** @var int HTTP status, 0 for transport errors */
    public $httpCode = 0;

    /** @var ?int seconds from Retry-After */
    public $retryAfter;

    public function __construct(string $message, int $httpCode = 0, ?int $retryAfter = null)
    {
        parent::__construct($message, $httpCode);
        $this->httpCode = $httpCode;
        $this->retryAfter = $retryAfter;
    }
}
