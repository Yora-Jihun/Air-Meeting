<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever a meeting UUID resolves to a row that a participant
 * should not be allowed to join (missing, ended, expired, locked, full,
 * or protected by a password that wasn't supplied/matched).
 */
class MeetingUnavailableException extends RuntimeException
{
    /**
     * A stable, non-translatable discriminator for callers that need to
     * branch on *why* (e.g. Join::join() only rate-limits the invalid
     * password case) without matching on the human-readable message text.
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('This meeting does not exist.', 'not_found');
    }

    public static function ended(): self
    {
        return new self('This meeting has ended.', 'ended');
    }

    public static function expired(): self
    {
        return new self('This meeting link has expired.', 'expired');
    }

    public static function locked(): self
    {
        return new self('The host has locked this meeting.', 'locked');
    }

    public static function full(): self
    {
        return new self('This meeting is full.', 'full');
    }

    public static function invalidPassword(): self
    {
        return new self('Incorrect meeting password.', 'invalid_password');
    }
}
