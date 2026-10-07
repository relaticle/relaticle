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
        ];
    }

    /**
     * @param  array{search?: string, record_type?: string, record_id?: string, direction?: string, thread_id?: string, sent_after?: string, sent_before?: string}  $filters
     * @return Paginator<int, Email>
     */
    public function paginate(User $viewer, array $filters, int $perPage, int $page): Paginator
    {
        $query = $this->deliveredTo($viewer);

        if (isset($filters['search'])) {
            $this->search->applyToQuery($query, $viewer, $filters['search']);
        }

        if (isset($filters['record_type'], $filters['record_id'])) {
            $this->restrictToRecord($query, $viewer, $filters['record_type'], $filters['record_id']);
        }

        $query
            ->when(isset($filters['direction']), fn (Builder $q): Builder => $q->where('direction', $filters['direction'] ?? null))
            ->when(isset($filters['thread_id']), fn (Builder $q): Builder => $q->where('thread_id', $filters['thread_id'] ?? null))
            ->when(isset($filters['sent_after']), fn (Builder $q): Builder => $q->where('sent_at', '>', Date::parse($filters['sent_after'] ?? '')->utc()->toDateTimeString()))
            ->when(isset($filters['sent_before']), fn (Builder $q): Builder => $q->where('sent_at', '<', Date::parse($filters['sent_before'] ?? '')->utc()->toDateTimeString()));

        return $this->preferredCopies
            ->restrictToVisiblePreferredCopies($query, $viewer)
            ->with(['participants', 'shares'])
            ->latest('sent_at')
            ->orderByDesc('id')
            ->simplePaginate($perPage, ['*'], 'page', $page);
    }

    public function find(User $viewer, string $id): ?Email
    {
        return $this->deliveredTo($viewer)
            ->withGlobalScope('visible', new VisibleEmailScope($viewer))
            ->with(['participants', 'shares', 'body', 'attachments'])
            ->whereKey($id)
            ->first();
    }

    /** @throws ValidationException */
    public function replyTarget(User $viewer, ?string $id): ?Email
    {
        if ($id === null) {
            return null;
        }

        $email = $this->find($viewer, $id);

        if (! $email instanceof Email) {
            throw ValidationException::withMessages([
                'in_reply_to_email_id' => "Email with ID [{$id}] not found.",
            ]);
        }

        return $email;
    }

    /** @return Builder<Email> */
    private function deliveredTo(User $viewer): Builder
    {
        $query = Email::query()->whereIn('status', [EmailStatus::SYNCED, EmailStatus::SENT]);

        return $query->where(fn (Builder $visible): Builder => $visible
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
