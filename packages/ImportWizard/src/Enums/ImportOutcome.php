<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum ImportOutcome: string
{
    case Completed = 'completed';
    case CompletedWithFailures = 'completed_with_failures';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::CompletedWithFailures => 'warning',
            self::Failed => 'danger',
        };
    }
}
