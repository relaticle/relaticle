<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HasEmailComposeActions;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

abstract class BaseRecordEmailsPage extends Page
{
    use HasEmailComposeActions;
    use InteractsWithRecord;

    protected string $view = 'filament.pages.record-emails';

    public EmailFolder $folder = EmailFolder::Inbox;

    public ?string $selectedEmailId = null;

    public string $search = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->selectedEmailId = $this->emails()->first()?->id;
    }

    protected function getCrmRecord(): Model
    {
        return $this->getRecord();
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return ['reply-email' => 'openReplyModal'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->composeEmailAction(),
        ];
    }

    /**
     * @return Collection<int, Email>
     */
    #[Computed]
    public function emails(): Collection
    {
        $user = $this->authUser();

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        $query = $record
            ->emails()
            ->with(['from', 'labels'])
            ->withGlobalScope('visible', new VisibleEmailScope($user));

        if ($this->folder === EmailFolder::Sent) {
            $query->where('direction', EmailDirection::OUTBOUND);
        } elseif ($this->folder === EmailFolder::Inbox) {
            $query->where('direction', EmailDirection::INBOUND);
        }

        if (filled($this->search)) {
            $query->where(function (Builder $q): void {
                $q->where('subject', 'ilike', '%'.$this->search.'%')
                    ->orWhere('snippet', 'ilike', '%'.$this->search.'%');
            });
        }

        return $query->latest('sent_at')->get();
    }

    #[Computed]
    public function selectedEmail(): ?Email
    {
        if ($this->selectedEmailId === null) {
            return null;
        }

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        /** @var Email|null */
        return $record
            ->emails()
            ->with(['body', 'participants', 'labels', 'attachments', 'from'])
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->whereKey($this->selectedEmailId)
            ->first();
    }

    public function selectEmail(string $id): void
    {
        $this->selectedEmailId = $id;
    }

    public function setFolder(string $folder): void
    {
        $this->folder = EmailFolder::from($folder);
        $this->search = '';
        $this->selectedEmailId = $this->emails()->first()?->id;
    }

    protected function manageSharingAction(): Action
    {
        return Action::make('manageSharing')
            ->label('Sharing')
            ->icon('heroicon-o-lock-open')
            ->modalHeading('Sharing settings')
            ->modalSubmitActionLabel('Save')
            ->schema([
                Select::make('privacy_tier')
                    ->label('Who can see this email?')
                    ->options(EmailPrivacyTier::class)
                    ->required(),

                Repeater::make('shares')
                    ->label('Share with specific teammates')
                    ->defaultItems(0)
                    ->addActionLabel('Add teammate')
                    ->columns(2)
                    ->schema([
                        Select::make('shared_with')
                            ->label('Teammate')
                            ->options(function (): array {
                                $user = $this->authUser();

                                return User::query()
                                    ->where('current_team_id', $user->current_team_id)
                                    ->where('id', '!=', $user->getKey())
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->required()
                            ->distinct(),

                        Select::make('tier')
                            ->label('Access level')
                            ->options(EmailPrivacyTier::class)
                            ->required(),
                    ]),
            ])
            ->fillForm(function (array $arguments): array {
                $email = Email::query()->whereKey($arguments['emailId'] ?? null)->first();

                if ($email === null) {
                    return [];
                }

                return [
                    'privacy_tier' => $email->privacy_tier->value,
                    'shares' => $email->shares()
                        ->get()
                        ->map(fn (EmailShare $share): array => [
                            'shared_with' => $share->shared_with,
                            'tier' => $share->tier,
                        ])
                        ->all(),
                ];
            })
            ->action(function (array $data, array $arguments, EmailSharingService $sharingService): void {
                $email = Email::query()->whereKey($arguments['emailId'] ?? null)->first();

                if ($email === null) {
                    return;
                }

                $sharer = $this->authUser();

                $sharingService->setEmailTier($email, $data['privacy_tier']);
                $email->shares()->where('shared_by', $sharer->getKey())->delete();

                foreach ($data['shares'] ?? [] as $share) {
                    /** @var User $sharedWithUser */
                    $sharedWithUser = User::query()->findOrFail($share['shared_with']);
                    $sharingService->shareEmail(
                        $email,
                        $sharer,
                        $sharedWithUser,
                        EmailPrivacyTier::from($share['tier']),
                    );
                }

                Notification::make()
                    ->success()
                    ->title('Sharing settings saved.')
                    ->send();
            });
    }

    protected function summarizeThreadAction(): Action
    {
        return Action::make('summarizeThread')
            ->label('Summarize Thread')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalHeading('AI Thread Summary')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments): View {
                $email = Email::query()->whereKey($arguments['emailId'] ?? null)->first();

                if ($email === null) {
                    return view('filament.actions.ai-summary', ['summary' => null]);
                }

                return $this->buildThreadSummaryView($email);
            });
    }

    protected function requestAccessAction(): Action
    {
        return Action::make('requestAccess')
            ->label('Request Access')
            ->icon('heroicon-o-key')
            ->schema([
                Select::make('tier_requested')
                    ->label('Access level requested')
                    ->options([
                        EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                        EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                    ])
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $email = Email::query()->whereKey($arguments['emailId'] ?? null)->first();

                if ($email === null) {
                    return;
                }

                $requester = $this->authUser();

                $existing = EmailAccessRequest::query()
                    ->where('email_id', $email->getKey())
                    ->where('requester_id', $requester->getKey())
                    ->where('status', 'pending')
                    ->exists();

                if ($existing) {
                    Notification::make()
                        ->warning()
                        ->title('You already have a pending request for this email.')
                        ->send();

                    return;
                }

                $request = EmailAccessRequest::query()->create([
                    'email_id' => $email->getKey(),
                    'requester_id' => $requester->getKey(),
                    'owner_id' => $email->user_id,
                    'tier_requested' => $data['tier_requested'],
                    'status' => 'pending',
                ]);

                $email->user?->notify(new EmailAccessRequestedNotification($request));

                Notification::make()
                    ->success()
                    ->title('Access request sent.')
                    ->send();
            });
    }

    private function buildThreadSummaryView(Email $email): View
    {
        $thread = EmailThread::query()
            ->where('thread_id', $email->thread_id)
            ->where('connected_account_id', $email->connected_account_id)
            ->first();

        if ($thread === null) {
            return view('filament.actions.ai-summary', ['summary' => null]);
        }

        $summary = resolve(EmailThreadSummaryService::class)
            ->getSummary($thread, $this->authUser());

        return view('filament.actions.ai-summary', ['summary' => $summary]);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
