<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Referral\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\Cloud\Analytics\Attribution;
use Relaticle\Cloud\Referral\Actions\EndReferralPrompt;
use Relaticle\Cloud\Referral\Enums\ReferralPromptOutcome;

final class ReferralPromptCard extends Component
{
    public ?string $outcome = null;

    public function copied(): void
    {
        $this->end(ReferralPromptOutcome::Copied);
    }

    public function dismiss(): void
    {
        $this->end(ReferralPromptOutcome::Dismissed);
    }

    #[Computed]
    public function link(): string
    {
        return url()->getPublicUrl('?utm_source='.Attribution::REFERRAL_SOURCE);
    }

    public function render(): View
    {
        return view('cloud::livewire.referral-prompt');
    }

    private function end(ReferralPromptOutcome $outcome): void
    {
        $user = $this->user();

        if (! $user instanceof User || $this->outcome !== null) {
            return;
        }

        resolve(EndReferralPrompt::class)->execute($user, $outcome);

        $this->outcome = $outcome->value;
    }

    private function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
