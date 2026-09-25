<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaCollection: string
{
    case Logo = 'logo';
    case PendingUploads = 'pending-uploads';
    case Attachments = 'attachments';
    case ChatAttachments = 'chat-attachments';
}
