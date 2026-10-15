<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Fetching\Exceptions;

use RuntimeException;

/**
 * A document that is not an RSS, Atom or JSON feed this parser will read:
 * malformed, the wrong format, too large, or an XML document carrying a DTD
 * or entity declarations (refused outright, so no external entity or entity
 * expansion attack can reach the XML parser). Reading it again gives the same
 * answer.
 */
final class FeedUnreadable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('The feed could not be read: '.$reason.'.');
    }
}
