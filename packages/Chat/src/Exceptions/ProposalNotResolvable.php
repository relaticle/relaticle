<?php

declare(strict_types=1);

namespace Relaticle\Chat\Exceptions;

use RuntimeException;

final class ProposalNotResolvable extends RuntimeException
{
    private const string EXPIRED = 'This action has expired';

    private const string ALREADY_RESOLVED = 'This action has already been resolved';

    private const string RECORD_NOT_FOUND = 'Record not found';

    private const string ITEM_OUT_OF_RANGE = 'Item index out of range';

    private const string ACTION_NOT_ALLOWLISTED = 'Action class not allowlisted';

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    public static function alreadyResolved(): self
    {
        return new self(self::ALREADY_RESOLVED);
    }

    public static function recordNotFound(): self
    {
        return new self(self::RECORD_NOT_FOUND);
    }

    public static function itemOutOfRange(): self
    {
        return new self(self::ITEM_OUT_OF_RANGE);
    }

    public static function actionNotAllowlisted(): self
    {
        return new self(self::ACTION_NOT_ALLOWLISTED);
    }

    public function userMessage(): string
    {
        return match ($this->getMessage()) {
            self::EXPIRED => __('This action has expired'),
            self::ALREADY_RESOLVED => __('This action has already been resolved'),
            self::RECORD_NOT_FOUND => __('Record not found'),
            self::ITEM_OUT_OF_RANGE => __('Item index out of range'),
            default => __('Action class not allowlisted'),
        };
    }
}
