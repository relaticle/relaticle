<?php

declare(strict_types=1);

namespace Relaticle\Chat\Exceptions;

use RuntimeException;

final class ProposalNotResolvable extends RuntimeException
{
    private function __construct(string $message, public readonly string $userMessage)
    {
        parent::__construct($message);
    }

    public static function expired(): self
    {
        return new self('This action has expired', __('This action has expired'));
    }

    public static function alreadyResolved(): self
    {
        return new self('This action has already been resolved', __('This action has already been resolved'));
    }

    public static function recordNotFound(): self
    {
        return new self('Record not found', __('Record not found'));
    }

    public static function itemOutOfRange(): self
    {
        return new self('Item index out of range', __('Item index out of range'));
    }

    public static function actionNotAllowlisted(): self
    {
        return new self('Action class not allowlisted', __('Action class not allowlisted'));
    }
}
