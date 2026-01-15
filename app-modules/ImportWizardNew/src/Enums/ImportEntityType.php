<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Enums;

enum ImportEntityType: string
{
    case Company = 'company';
    case People = 'people';
    case Opportunity = 'opportunity';
    case Task = 'task';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Companies',
            self::People => 'People',
            self::Opportunity => 'Opportunities',
            self::Task => 'Tasks',
            self::Note => 'Notes',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::People => 'Person',
            self::Opportunity => 'Opportunity',
            self::Task => 'Task',
            self::Note => 'Note',
        };
    }
}
