<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum MailboxImportPass: string
{
    case Full = 'full';
    case Recent = 'recent';

    public const int RECENT_DAYS = 90;
}
