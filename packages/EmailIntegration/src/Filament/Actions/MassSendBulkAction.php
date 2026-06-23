<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

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
use Illuminate\Support\Facades\DB;
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
                    ->label(__('filament/actions/mass-send.fields.body.label'))
                    ->required()
                    ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
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
                    ->map(fn (Collection $participants): string => $participants->first()->email_address);

                // Filter out people without a known email address
                $validRecipients = $records->filter(
                    fn (mixed $person): bool => $person instanceof People && filled($personEmails->get($person->getKey()))
                );

                if ($validRecipients->isEmpty()) {
                    Notification::make()
                        ->title(__('filament/actions/mass-send.notifications.no_recipients.title'))
                        ->body(__('filament/actions/mass-send.notifications.no_recipients.body'))
                        ->warning()
                        ->send();

                    return;
                }

                $renderService = resolve(EmailTemplateRenderService::class);
                /** @var EmailTemplate|null $template */
                $template = isset($data['template_id']) ? EmailTemplate::query()->whereKey($data['template_id'])->first() : null;

                // All-or-nothing: SendEmailAction throws once the per-user queue cap is
                // hit. Without a transaction a mid-loop failure would leave the batch with
                // total_recipients set to the full count but only some emails queued, so
                // sent_count+failed_count can never reach total and the batch is stuck
                // Queued forever. Wrap creation + every send so a failure rolls all of it
                // back, leaving no orphaned batch.
                DB::transaction(function () use ($validRecipients, $personEmails, $template, $renderService, $data, $teamId, $userId, $accountId): void {
                    $batch = EmailBatch::query()->create([
                        'team_id' => $teamId,
                        'user_id' => $userId,
                        'connected_account_id' => $accountId,
                        'subject' => $data['subject'],
                        'total_recipients' => $validRecipients->count(),
                        'status' => EmailBatchStatus::Queued,
                    ]);

                    foreach ($validRecipients as $person) {
                        $rendered = $template !== null
                            ? $renderService->render($template, $person)
                            : [
                                'subject' => $renderService->renderContent((string) $data['subject'], $person),
                                'body_html' => $renderService->renderContent((string) $data['body_html'], $person),
                            ];

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
                });

                Notification::make()
                    ->title(__('filament/actions/mass-send.notifications.queued.title'))
                    ->body(__('filament/actions/mass-send.notifications.queued.body', ['count' => $validRecipients->count()]))
                    ->success()
                    ->send();
            });
    }
}
