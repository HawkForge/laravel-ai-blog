<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching\Exceptions;

use RuntimeException;

/**
 * Anything that stopped a research fetch.
 *
 * The message is safe to store and show: it names the URL and the reason,
 * never any of the response body. `retryable` says whether the same fetch
 * later can succeed, which is what a queued job needs to choose between a
 * retry and a final failure.
 */
abstract class FetchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /**
     * The URL as it may appear in a message: bounded and without control
     * characters, since it came from somewhere untrusted.
     */
    protected static function printable(string $url): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $url);

        return mb_strlen($clean) > 300 ? mb_substr($clean, 0, 300).'...' : $clean;
    }
}
