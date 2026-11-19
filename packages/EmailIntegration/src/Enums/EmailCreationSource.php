<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailCreationSource: string implements HasLabel
{
    case SYNC = 'sync';
    case COMPOSE = 'compose';
    case FORWARD = 'forward';
    case BCC_INBOUND = 'bcc_inbound';
    case REPLY = 'reply';
    case REPLY_ALL = 'reply_all';
    case MASS_SEND = 'mass_send';
    case MCP = 'mcp';
    case CHAT = 'chat';

    public function getLabel(): string
    {
        return match ($this) {
            self::SYNC => __('enums/email-creation-source.sync'),
            self::COMPOSE => __('enums/email-creation-source.compose'),
            self::FORWARD => __('enums/email-creation-source.forward'),
            self::BCC_INBOUND => __('enums/email-creation-source.bcc_inbound'),
            self::REPLY => __('enums/email-creation-source.reply'),
            self::REPLY_ALL => __('enums/email-creation-source.reply_all'),
            self::MASS_SEND => __('enums/email-creation-source.mass_send'),
            self::MCP => __('enums/email-creation-source.mcp'),
            self::CHAT => (string) config('chat.assistant_name'),
        };
    }
}
