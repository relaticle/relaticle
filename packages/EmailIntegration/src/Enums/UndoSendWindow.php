<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use App\Models\User;
use Filament\Support\Contracts\HasLabel;

enum UndoSendWindow: int implements HasLabel
{
    case Five = 5;
    case Ten = 10;
    case Twenty = 20;
    case Thirty = 30;

    public const int MAX = 30;

    public static function forUser(User $user): self
    {
        return self::tryFrom((int) $user->email_undo_seconds) ?? self::Ten;
    }

    public function getLabel(): string
    {
        return __('filament/pages/email-accounts.undo_send.seconds', ['seconds' => $this->value]);
    }
}
