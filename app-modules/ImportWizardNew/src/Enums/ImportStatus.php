<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Enums;

enum ImportStatus: string
{
    case Uploading = 'uploading';
    case Mapping = 'mapping';
    case Reviewing = 'reviewing';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';
}
