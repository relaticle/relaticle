<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum EmailParticipantRole: string
{
    case FROM = 'from';
    case TO = 'to';
    case CC = 'cc';
    case BCC = 'bcc';
}
