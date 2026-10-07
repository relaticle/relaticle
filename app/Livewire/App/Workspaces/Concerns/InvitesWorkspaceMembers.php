<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces\Concerns;

use App\Enums\WorkspaceRole;
use App\Support\Workspaces\RoleOptions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

trait InvitesWorkspaceMembers
{
    use SendsWorkspaceInvitations;

    // The addresses and the role live in the modal rather than on the page: the
    // page is a roster, and the form only has anything to say while inviting.
    public function invitePeopleAction(): Action
    {
        return Action::make('invitePeople')
            ->label(__('workspaces.actions.invite_people'))
            ->icon('heroicon-m-user-plus')
            ->button()
            ->authorize(fn (): bool => Gate::check('addWorkspaceMember', $this->workspace))
            ->modalHeading(__('workspaces.actions.invite_people'))
            ->modalDescription(fn (): string => __('workspaces.sections.invite_people.description', ['workspace' => $this->workspace->name]))
            ->modalIcon('heroicon-o-user-plus')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('workspaces.actions.send_invitations'))
            ->schema([
                Textarea::make('emails')
                    ->label(__('workspaces.form.emails.label'))
                    ->placeholder(__('workspaces.form.emails.placeholder'))
                    ->helperText(__('workspaces.form.emails.helper'))
                    ->rows(3)
                    ->autofocus()
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $emails = $this->parseEmails(is_string($value) ? $value : '');

                        if ($emails === []) {
                            $fail(__('workspaces.validation.no_valid_emails'));

                            return;
                        }

                        if (count($emails) > self::MAX_INVITES_PER_SUBMISSION) {
                            $fail(__('workspaces.validation.too_many_invites', ['max' => self::MAX_INVITES_PER_SUBMISSION]));
                        }
                    }),
                Select::make('role')
                    ->label(__('workspaces.form.invite_as.label'))
                    ->options(fn (): array => RoleOptions::assignable($this->authUser(), $this->workspace))
                    ->in(fn (): array => array_keys(RoleOptions::assignable($this->authUser(), $this->workspace)))
                    ->helperText(fn (Get $get): ?string => RoleOptions::descriptions()[$get('role')] ?? null)
                    ->hintAction(RoleOptions::compareAction())
                    ->default(WorkspaceRole::Member->value)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->sendInvitations(
                    $this->parseEmails((string) $data['emails']),
                    (string) $data['role'],
                );
            });
    }
}
