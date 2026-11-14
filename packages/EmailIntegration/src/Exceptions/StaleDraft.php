<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use RuntimeException;

final class StaleDraft extends RuntimeException
{
    public function __construct(public readonly bool $gone = false)
    {
        parent::__construct('This draft was changed, sent or discarded elsewhere.');
    }
}
