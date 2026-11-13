<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use RuntimeException;

final class EmptyDraft extends RuntimeException
{
    public static function create(): self
    {
        return new self('Cannot save an empty draft.');
    }
}
