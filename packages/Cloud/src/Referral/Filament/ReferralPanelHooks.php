<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Referral\Filament;

use App\Enums\BillingStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Cloud\Referral\Models\ReferralPrompt;

final readonly class ReferralPanelHooks
{
    public function sidebarPrompt(): string
    {
        $user = Filament::auth()->user();

        if (! BillingStatus::billingEnabled() || ! $user instanceof User || ! $this->due($user)) {
            return '';
        }

        return Blade::render('@livewire(\Relaticle\Cloud\Referral\Livewire\ReferralPromptCard::class)');
    }

    private function due(User $user): bool
    {
        return ! ReferralPrompt::query()->whereKey($user->getKey())->exists()
            && PendingAction::query()->approvedBy($user)->exists();
    }
}
