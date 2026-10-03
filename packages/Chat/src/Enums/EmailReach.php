<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use App\Features\EmailIntegration;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

enum EmailReach
{
    case Off;
    case NoMailbox;
    case Ready;

    public static function for(User $user, Workspace $workspace): self
    {
        return match (true) {
            ! Feature::active(EmailIntegration::class) => self::Off,
            ! ConnectedAccount::hasSendableFor($user, $workspace) => self::NoMailbox,
            default => self::Ready,
        };
    }

    public function promptLine(): string
    {
        return match ($this) {
            self::Off => 'Email: this workspace cannot send email, and neither can you. When asked to email someone, draft the message in your reply for the user to send from their own mail app.',
            self::NoMailbox => 'Email: you cannot send email yourself, and sending from Relaticle needs a connected Gmail or Microsoft mailbox, which this user has not connected. When asked to email someone, draft the message, then give the "email_accounts" destination link so they can connect one.',
            self::Ready => 'Email: you cannot send email yourself. When asked to email someone, draft the message, then tell the user to select the people or companies in their table and use the Send Email bulk action.',
        };
    }
}
