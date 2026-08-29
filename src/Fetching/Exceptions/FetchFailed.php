<?php

declare(strict_types=1);

namespace AiBlog\Fetching\Exceptions;

/**
 * The fetch was allowed but did not work: the host did not answer, took too
 * long, or answered with an error status. A timeout, a connection failure,
 * a 429 or a 5xx may work later; any other 4xx will not.
 */
final class FetchFailed extends FetchException
{
    public function __construct(
        string $message,
        bool $retryable,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, $retryable);
    }

    public static function unreachable(string $url, string $why): self
    {
        return new self('Could not fetch '.self::printable($url).': '.$why.'.', retryable: true);
    }

    public static function status(string $url, int $status): self
    {
        return new self(
            'Fetching '.self::printable($url).' returned HTTP '.$status.'.',
            retryable: $status === 429 || $status >= 500,
            status: $status,
        );
    }
}
