<?php
namespace Azura\Exception;

use Azura\Exception;
use Psr\Log\LogLevel;
use Throwable;

class NoGetterAvailableException extends Exception
{
    public function __construct(
        string $message = 'No getter available for this variable.',
        int $code = 0,
        Throwable $previous = null,
        string $loggerLevel = LogLevel::INFO
    ) {
        parent::__construct($message, $code, $previous, $loggerLevel);
    }
}