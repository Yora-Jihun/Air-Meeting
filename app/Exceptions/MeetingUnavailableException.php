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
    public static function notFound(): self
    {
        return new self('This meeting does not exist.');
    }

    public static function ended(): self
    {
        return new self('This meeting has ended.');
    }

    public static function expired(): self
    {
        return new self('This meeting link has expired.');
    }

    public static function locked(): self
    {
        return new self('The host has locked this meeting.');
    }

    public static function full(): self
    {
        return new self('This meeting is full.');
    }

    public static function invalidPassword(): self
    {
        return new self('Incorrect meeting password.');
    }
}
