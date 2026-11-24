<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Input could not be read or is unsafe/unusable. The message is shown to the
 * member so they can fall back to pasting text manually.
 */
class AiInputException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
