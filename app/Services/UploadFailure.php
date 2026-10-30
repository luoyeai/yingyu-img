<?php

namespace App\Services;

class UploadFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly bool $retryable = false, public readonly string $scope = 'queue')
    {
        parent::__construct($message);
    }
}
