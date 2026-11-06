<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\User\UpdateKeyboardShortcuts as UpdateKeyboardShortcutsAction;
use App\Livewire\BaseLivewireComponent;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;

final class UpdateKeyboardShortcuts extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['single_key_shortcuts' => $this->authUser()->single_key_shortcuts ?? true]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.keyboard_shortcuts.title'))
                    ->description(__('profile.sections.keyboard_shortcuts.description'))
                    ->schema([
                        Toggle::make('single_key_shortcuts')
                            ->label(__('profile.form.single_key_shortcuts.label'))
                            ->helperText(__('profile.form.single_key_shortcuts.helper_text'))
                            ->live()
                            ->afterStateUpdated(function (bool $state): void {
                                $this->save($state);
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    private function save(bool $enabled): void
    {
        resolve(UpdateKeyboardShortcutsAction::class)->execute($this->authUser(), $enabled);

        $flag = $enabled ? 'true' : 'false';
        $this->js("document.querySelector('[data-single-key-shortcuts]')?.setAttribute('data-single-key-shortcuts', '{$flag}'); window.Alpine?.store('shortcuts')?.readPreference()");
        $this->sendNotification(__('profile.notifications.keyboard_shortcuts_saved'));
    }

    public function render(): View
    {
        return view('livewire.app.profile.update-keyboard-shortcuts');
    }
}
