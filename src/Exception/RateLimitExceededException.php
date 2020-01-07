<?php
namespace Azura\Exception;

use Azura\Exception;
use Psr\Log\LogLevel;
use Throwable;

class RateLimitExceededException extends Exception
{
    public function __construct(
        string $message = 'You have exceeded the rate limit for this application.',
        int $code = 0,
        Throwable $previous = null,
        string $loggerLevel = LogLevel::INFO
    ) {
        parent::__construct($message, $code, $previous, $loggerLevel);
    }
}