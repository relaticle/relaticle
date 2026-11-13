<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SsrfGuardException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $hostUnresolved = false)
    {
        parent::__construct($message);
    }

    public static function unresolvedHost(string $host): self
    {
        return new self("Could not resolve host: {$host}", hostUnresolved: true);
    }
}
