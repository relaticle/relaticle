<?php

declare(strict_types=1);

namespace App\Livewire\App\Email;

use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

final class UserEmailPrivacySettings extends Component
{
    public ?string $default_email_sharing_tier = null;

    /** @var array<int, array{type: string, value: string}> */
    public array $blocklist = [];

    public function mount(): void
    {
        $user = auth()->user();

        $this->default_email_sharing_tier = $user->default_email_sharing_tier?->value;

        $this->blocklist = EmailBlocklist::query()->where('user_id', $user->getKey())
            ->where('team_id', $user->currentTeam->getKey())
            ->get(['id', 'type', 'value'])
            ->toArray();
    }

    public function addBlocklistEntry(): void
    {
        $this->blocklist[] = ['type' => 'email', 'value' => ''];
    }

    public function removeBlocklistEntry(int $index): void
    {
        array_splice($this->blocklist, $index, 1);
        $this->blocklist = array_values($this->blocklist);
    }

    public function save(): void
    {
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        $user->update([
            'default_email_sharing_tier' => $this->default_email_sharing_tier ?: null,
        ]);

        EmailBlocklist::query()->where('user_id', $user->getKey())
            ->where('team_id', $teamId)
            ->delete();

        foreach ($this->blocklist as $entry) {
            if (in_array(trim($entry['value']), ['', '0'], true)) {
                continue;
            }

            EmailBlocklist::query()->create([
                'user_id' => $user->getKey(),
                'team_id' => $teamId,
                'type' => $entry['type'],
                'value' => strtolower(trim($entry['value'])),
            ]);
        }

        Notification::make()
            ->success()
            ->title('Email privacy settings saved.')
            ->send();
    }

    /** @return array<string, string> */
    public function getTierOptions(): array
    {
        return collect(EmailPrivacyTier::cases())
            ->mapWithKeys(fn (EmailPrivacyTier $tier): array => [$tier->value => $tier->getLabel()])
            ->prepend('Use workspace default', '')
            ->all();
    }

    /** @return array<string, string> */
    public function getTypeOptions(): array
    {
        return collect(EmailBlocklistType::cases())
            ->mapWithKeys(fn (EmailBlocklistType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }
}
