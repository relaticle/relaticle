<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\People;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final class MassSendBulkAction extends BulkAction
{
    public static function make(?string $name = null): static
    {
        /** @var static $action */
        $action = parent::make($name ?? 'massSend');

        return $action
            ->label('Send Email')
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
                    ->label('From')
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
                    ->label('Template')
                    ->placeholder('None — write below')
                    ->options(fn (): array => EmailTemplate::query()
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

                        $template = EmailTemplate::query()->find($state);

                        if ($template === null) {
                            return;
                        }

                        $set('subject', $template->subject ?? '');
                    }),

                TextInput::make('subject')
                    ->required()
                    ->maxLength(255),

                RichEditor::make('body_html')
                    ->label('Body')
                    ->required()
                    ->helperText('Use {name} for full name, {first_name} for first name, {company} for company name.')
                    ->toolbarButtons([
                        'bold', 'italic', 'underline',
                        'link', 'bulletList', 'orderedList',
                    ]),
            ])
            ->action(function (Collection $records, array $data): void {
                $teamId = filament()->getTenant()?->getKey();
                $userId = auth()->id();
                $accountId = $data['connected_account_id'];

                // Resolve email address for each person from their participant records
                $personEmails = EmailParticipant::query()
                    ->whereIn('contact_id', $records->pluck('id'))
                    ->whereNotNull('email_address')
                    ->select('contact_id', 'email_address')
                    ->distinct()
                    ->get()
                    ->groupBy('contact_id')
                    ->map(fn ($participants) => $participants->first()->email_address);

                // Filter out people without a known email address
                $validRecipients = $records->filter(
                    fn ($person): bool => $person instanceof People && filled($personEmails->get($person->getKey()))
                );

                if ($validRecipients->isEmpty()) {
                    Notification::make()
                        ->title('No valid recipients')
                        ->body('None of the selected people have an email address.')
                        ->warning()
                        ->send();

                    return;
                }

                $batch = EmailBatch::query()->create([
                    'team_id' => $teamId,
                    'user_id' => $userId,
                    'connected_account_id' => $accountId,
                    'subject' => $data['subject'],
                    'total_recipients' => $validRecipients->count(),
                    'status' => EmailBatchStatus::Queued,
                ]);

                $renderService = resolve(EmailTemplateRenderService::class);
                /** @var EmailTemplate|null $template */
                $template = isset($data['template_id']) ? EmailTemplate::query()->whereKey($data['template_id'])->first() : null;

                foreach ($validRecipients as $person) {
                    $rendered = $template !== null
                        ? $renderService->render($template, $person)
                        : ['subject' => $data['subject'], 'body_html' => $data['body_html']];

                    resolve(SendEmailAction::class)->execute(
                        data: [
                            'connected_account_id' => $accountId,
                            'subject' => $rendered['subject'],
                            'body_html' => $rendered['body_html'],
                            'to' => [['email' => (string) $personEmails->get($person->getKey()), 'name' => $person->name]],
                            'cc' => [],
                            'bcc' => [],
                            'in_reply_to_email_id' => null,
                            'creation_source' => EmailCreationSource::MASS_SEND,
                            'privacy_tier' => EmailPrivacyTier::FULL,
                            'batch_id' => $batch->getKey(),
                        ],
                        linkToType: People::class,
                        linkToId: $person->getKey(),
                    );
                }

                Notification::make()
                    ->title('Mass email queued')
                    ->body("Sending to {$validRecipients->count()} recipient(s).")
                    ->success()
                    ->send();
            });
    }
}
