<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;

final readonly class VisibleEmailsQuery
{
    /** @var array<string, array{relation: string, model: class-string<Model>}> */
    private const array RECORDS = [
        'people' => ['relation' => 'people', 'model' => People::class],
        'company' => ['relation' => 'companies', 'model' => Company::class],
        'opportunity' => ['relation' => 'opportunities', 'model' => Opportunity::class],
    ];

    public function __construct(
        private EmailSearchService $search,
        private PreferredEmailCopyService $preferredCopies,
        private EmailVisibilityService $visibility,
    ) {}

    /** @return list<string> */
    public static function recordTypes(): array
    {
        return array_keys(self::RECORDS);
    }

    /** @return array<string, list<mixed>> */
    public static function filterRules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'min:2', 'max:200'],
            'record_type' => ['required_with:record_id', 'string', Rule::in(self::recordTypes())],
            'record_id' => ['required_with:record_type', 'string', 'max:64'],
            'direction' => ['sometimes', 'string', Rule::enum(EmailDirection::class)],
            'thread_id' => ['sometimes', 'string', 'max:255'],
            'sent_after' => ['sometimes', 'date'],
            'sent_before' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', Rule::enum(EmailStatus::class)],
        ];
    }

    /**
     * @param  array{search?: string, record_type?: string, record_id?: string, direction?: string, thread_id?: string, sent_after?: string, sent_before?: string, status?: string}  $filters
     * @return Paginator<int, Email>
     */
    public function paginate(User $viewer, array $filters, int $perPage, int $page): Paginator
    {
        $query = $this->visibleTo($viewer);

        if (isset($filters['search'])) {
            $this->search->applyToQuery($query, $viewer, $filters['search']);
        }

        if (isset($filters['record_type'], $filters['record_id'])) {
            $this->restrictToRecord($query, $viewer, $filters['record_type'], $filters['record_id']);
        }

        $query
            ->when(isset($filters['direction']), fn (Builder $q): Builder => $q->where('direction', $filters['direction'] ?? null))
            ->when(isset($filters['thread_id']), fn (Builder $q): Builder => $q->where('thread_id', $filters['thread_id'] ?? null))
            ->when(isset($filters['status']), fn (Builder $q): Builder => $q->where('status', $filters['status'] ?? null))
            ->when(isset($filters['sent_after']), fn (Builder $q): Builder => $q->where('sent_at', '>', Date::parse($filters['sent_after'] ?? '')->utc()->toDateTimeString()))
            ->when(isset($filters['sent_before']), fn (Builder $q): Builder => $q->where('sent_at', '<', Date::parse($filters['sent_before'] ?? '')->utc()->toDateTimeString()));

        return $this->preferredCopies
            ->restrictToVisiblePreferredCopies($query, $viewer)
            ->with(['participants', 'shares'])
            ->orderByRaw('coalesce(emails.sent_at, emails.scheduled_for, emails.created_at) desc')
            ->orderByDesc('id')
            ->simplePaginate($perPage, ['*'], 'page', $page);
    }

    public function find(User $viewer, string $id): ?Email
    {
        return $this->withDetail($this->visibleTo($viewer), $viewer, $id);
    }

    /** @throws ValidationException */
    public function replyTarget(User $viewer, ?string $id): ?Email
    {
        if ($id === null) {
            return null;
        }

        $email = $this->withDetail($this->deliveredTo($viewer), $viewer, $id);

        if (! $email instanceof Email) {
            throw ValidationException::withMessages([
                'in_reply_to_email_id' => "Email with ID [{$id}] not found.",
            ]);
        }

        return $email;
    }

    /** @param  Builder<Email>  $query */
    private function withDetail(Builder $query, User $viewer, string $id): ?Email
    {
        return $query
            ->withGlobalScope('visible', new VisibleEmailScope($viewer))
            ->with(['participants', 'shares', 'body', 'attachments'])
            ->whereKey($id)
            ->first();
    }

    /**
     * Delivered mail, plus the viewer's own drafts and outbox. A teammate's unsent mail stays out.
     *
     * @return Builder<Email>
     */
    private function visibleTo(User $viewer): Builder
    {
        return Email::query()->where(fn (Builder $visible): Builder => $visible
            ->where(fn (Builder $delivered): Builder => $this->whereDelivered($delivered, $viewer))
            ->orWhere(fn (Builder $own): Builder => $own->composedBy($viewer)));
    }

    /** @return Builder<Email> */
    private function deliveredTo(User $viewer): Builder
    {
        return $this->whereDelivered(Email::query(), $viewer);
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function whereDelivered(Builder $query, User $viewer): Builder
    {
        return $query
            ->whereIn($query->qualifyColumn('status'), [EmailStatus::SYNCED, EmailStatus::SENT])
            ->where(fn (Builder $visible): Builder => $visible
                ->where($query->qualifyColumn('user_id'), $viewer->getKey())
                ->orWhere($query->qualifyColumn('is_internal'), false));
    }

    /** @param  Builder<Email>  $query */
    private function restrictToRecord(Builder $query, User $viewer, string $type, string $id): void
    {
        $record = self::RECORDS[$type]['model']::query()
            ->where('workspace_id', $viewer->current_workspace_id)
            ->find($id);

        if (! $record instanceof Model || $this->visibility->hidesRecordMailbox($record)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas(
            self::RECORDS[$type]['relation'],
            fn (Builder $linked): Builder => $linked->whereKey($id),
        );
    }
}
