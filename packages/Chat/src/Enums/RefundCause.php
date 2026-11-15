<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum RefundCause: string
{
    case Timeout = 'timeout';
    case BlankReply = 'blank_reply';
    case Failed = 'failed';
    case NotStarted = 'not_started';
    case Refused = 'refused';
    case Orphaned = 'orphaned';

    public function providerBilled(): bool
    {
        return match ($this) {
            self::Timeout, self::BlankReply => true,
            self::Failed, self::NotStarted, self::Refused, self::Orphaned => false,
        };
    }
}
