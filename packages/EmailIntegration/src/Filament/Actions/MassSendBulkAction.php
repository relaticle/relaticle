<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Actions\SendEmailBatchAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final class MassSendBulkAction extends BulkAction
{
    public static function make(?string $name = null): static
    {
        /** @var static $action */
        $action = parent::make($name ?? 'massSend');

        return $action
            ->label(__('filament/actions/mass-send.label'))
            ->icon('heroicon-o-paper-airplane')
            ->modalWidth(Width::ThreeExtraLarge)
            ->visible(fn (): bool => ConnectedAccount::query()
                ->where('user_id', auth()->id())
                ->where('team_id', filament()->getTenant()?->getKey())
                ->where('status', 'active')
                ->exists()
            )
            ->schema([
                Select::make('connected_account_id')
                    ->label(__('filament/actions/mass-send.fields.from.label'))
                    ->options(fn (): array => ConnectedAccount::query()
                        ->where('user_id', auth()->id())
                        ->where('team_id', filament()->getTenant()?->getKey())
                        ->where('status', 'active')
                        ->get()
                        ->mapWithKeys(fn (ConnectedAccount $account): array => [$account->getKey() => $account->label])
                        ->all()
                    )
                    ->required(),

                Select::make('template_id')
                    ->label(__('filament/actions/mass-send.fields.template.label'))
                    ->placeholder(__('filament/actions/mass-send.fields.template.placeholder'))
                    ->options(fn (): array => EmailTemplate::query()
                        ->where('team_id', filament()->getTenant()?->getKey())
                        ->where(fn (Builder $q) => $q
                            ->where('created_by', auth()->id())
                            ->orWhere('is_shared', true)
                        )
                        ->pluck('name', 'id')
                        ->all()
                    )
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $template = EmailTemplate::query()
                            ->where('team_id', filament()->getTenant()?->getKey())
                            ->find($state);

                        if ($template === null) {
                            return;
                        }

                        $set('subject', $template->subject ?? '');
                    }),

                TextInput::make('subject')
                    ->required()
                    ->maxLength(255),

                RichEditor::make('body_html')
                    ->label(__('filament/actions/mass-send.fields.body.label'))
                    ->required()
                    ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                    ->toolbarButtons([
                        'bold', 'italic', 'underline',
                        'link', 'bulletList', 'orderedList',
                    ]),
            ])
            ->action(function (Collection $records, array $data): void {
                /** @var User $user */
                $user = auth()->user();

                // Resolve each selected person's send-to address from the People EMAILS
                // custom field — the canonical source LinkEmailAction matches participants
                // against. Resolving from participant rows alone would silently drop people
                // you have never emailed (those rows are a side effect of past exchange).
                $emailsField = CustomField::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', filament()->getTenant()?->getKey())
                    ->where('entity_type', 'people')
                    ->where('code', PeopleField::EMAILS->value)
                    ->first();

                /** @var list<array{person: People, email: string}> $recipients */
                $recipients = [];
                $skipped = 0;

                // ponytail: getCustomFieldValue lazy-loads per person (N+1) — fine for a
                // manual bulk selection; eager-load customFieldValues if selections grow huge.
                foreach ($records as $person) {
                    if (! $person instanceof People) {
                        continue;
                    }

                    $email = self::resolvePrimaryEmail($person, $emailsField);

                    if ($email === null) {
                        $skipped++;

                        continue;
                    }

                    $recipients[] = ['person' => $person, 'email' => $email];
                }

                if ($recipients === []) {
                    Notification::make()
                        ->title(__('filament/actions/mass-send.notifications.no_recipients.title'))
                        ->body(__('filament/actions/mass-send.notifications.no_recipients.body'))
                        ->warning()
                        ->send();

                    return;
                }

                /** @var EmailTemplate|null $template */
                $template = isset($data['template_id']) ? EmailTemplate::query()
                    ->where('team_id', filament()->getTenant()?->getKey())
                    ->whereKey($data['template_id'])
                    ->first() : null;

                resolve(SendEmailBatchAction::class)->execute(
                    user: $user,
                    recipients: $recipients,
                    payload: [
                        'connected_account_id' => $data['connected_account_id'],
                        'subject' => (string) $data['subject'],
                        'body_html' => (string) $data['body_html'],
                    ],
                    template: $template,
                );

                Notification::make()
                    ->title(__('filament/actions/mass-send.notifications.queued.title'))
                    ->body($skipped > 0
                        ? __('filament/actions/mass-send.notifications.queued.body_with_skipped', [
                            'count' => count($recipients),
                            'skipped' => $skipped,
                        ])
                        : __('filament/actions/mass-send.notifications.queued.body', ['count' => count($recipients)]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Resolve a person's first email address from the EMAILS custom field
     * (stored as a JSON array). Returns null when the person has no email.
     */
    private static function resolvePrimaryEmail(People $person, ?CustomField $emailsField): ?string
    {
        if (! $emailsField instanceof CustomField) {
            return null;
        }

        $value = $person->getCustomFieldValue($emailsField);
        $email = is_array($value) ? ($value[0] ?? null) : $value;

        return filled($email) ? (string) $email : null;
    }
}
