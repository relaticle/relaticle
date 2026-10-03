<?php

declare(strict_types=1);

namespace Relaticle\Chat\Exceptions;

use Laravel\Ai\Streaming\Events\Error;
use RuntimeException;

final class ProviderStreamRejectedException extends RuntimeException
{
    public function __construct(public readonly Error $error)
    {
        parent::__construct("Provider stream error [{$error->type}]: {$error->message}");
    }
}
