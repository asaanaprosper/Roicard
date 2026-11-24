<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * A provider/network failure that must degrade to manual editing.
 *
 * The message is safe for logs and for the member; provider response bodies
 * (which may echo prompt content) are never attached or logged.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
