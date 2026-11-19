<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImportStatus: string implements HasLabel
{
    case Uploading = 'uploading';
    case Mapping = 'mapping';
    case Reviewing = 'reviewing';
    case Previewing = 'previewing';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('enums/import-status.'.$this->value);
    }
}
