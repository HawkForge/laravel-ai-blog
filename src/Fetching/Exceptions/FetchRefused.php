<?php

declare(strict_types=1);

namespace AiBlog\Fetching\Exceptions;

use AiBlog\Fetching\FetchRefusal;

/**
 * The fetcher would not fetch, or would not keep, this URL: a policy said no.
 * Asking again gets the same answer, except for a robots.txt that could not
 * be read, which may come back later.
 */
final class FetchRefused extends FetchException
{
    public function __construct(
        public readonly FetchRefusal $reason,
        string $message,
    ) {
        parent::__construct($message, retryable: $reason === FetchRefusal::RobotsUnavailable);
    }

    public static function because(FetchRefusal $reason, string $url, ?string $detail = null): self
    {
        $message = 'Refused to fetch '.self::printable($url).': '.$reason->describe();

        if ($detail !== null && $detail !== '') {
            $message .= ' ('.$detail.')';
        }

        return new self($reason, $message.'.');
    }
}
