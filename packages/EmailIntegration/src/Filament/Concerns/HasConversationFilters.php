<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Components\Forms\LinkedRecordsSelect;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\FilterErrors;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailFilter;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\EmailSearchService;

/**
 * @property-read ConversationFilters $conversationFilters
 * @property-read string|null $filterError
 * @property-read array<string, string> $filterChips
 * @property-read ConversationView $activeView
 */
trait HasConversationFilters
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'filter')]
    public mixed $filter = [];

    #[Computed]
    public function conversationFilters(): ConversationFilters
    {
        return $this->filterTree()->withTyped(EmailSearchService::clean($this->search), $this->viewerZone());
    }

    #[Computed]
    public function filterError(): ?string
    {
        try {
            $this->parseFilterTree();
        } catch (ValidationException $exception) {
            return __('filament/pages/email-inbox.filters.ignored', ['reason' => FilterErrors::located($exception)]);
        }

        return null;
    }

    /** @return array<string, string> */
    #[Computed]
    public function filterChips(): array
    {
        $filters = $this->filterTree();
        $chips = [];

        foreach (array_keys($filters->toTree()) as $field) {
            $chips[$field] = $this->describeFilter(EmailFilter::from($field), $filters);
        }

        return $chips;
    }

    /** @return array<string, true> */
    public function editableFilters(): array
    {
        $filters = $this->filterTree();
        $editable = [];

        foreach (array_keys($this->filterChips) as $field) {
            if ($filters->typedFor(EmailFilter::from($field), $this->viewerZone()) !== null) {
                $editable[$field] = true;
            }
        }

        return $editable;
    }

    public function isFiltering(): bool
    {
        return $this->conversationFilters->toTree() !== [] || $this->conversationFilters->search !== null;
    }

    public function updatedSearch(): void
    {
        $this->refreshConversationList();
    }

    public function updatedFilter(): void
    {
        $this->refreshConversationList();
    }

    public function submitSearch(): void
    {
        $filters = $this->conversationFilters;

        $this->filter = $filters->toTree();
        $this->search = $filters->search ?? '';
        $this->refreshConversationList();
    }

    public function editFilter(string $field): void
    {
        $filter = EmailFilter::tryFrom($field);
        $typed = $filter instanceof EmailFilter ? $this->filterTree()->typedFor($filter, $this->viewerZone()) : null;

        if ($typed === null) {
            return;
        }

        $this->search = trim(EmailSearchService::clean($this->search).' '.$typed);
        $this->setFilter($filter, null);
    }

    public function removeFilter(string $field): void
    {
        $filter = EmailFilter::tryFrom($field);

        if ($filter instanceof EmailFilter) {
            $this->setFilter($filter, null);
        }
    }

    public function clearFilters(): void
    {
        $this->filter = [];
        $this->search = '';
        $this->refreshConversationList();
    }

    public function toggleAttachmentFilter(): void
    {
        $this->setFilter(EmailFilter::HasAttachment, $this->filterTree()->hasAttachment === true ? null : ['$eq' => true]);
    }

    public function mailboxFilterAction(): Action
    {
        return $this->filterChipAction(EmailFilter::Mailbox)
            ->schema([
                CheckboxList::make('mailboxes')
                    ->label(__('filament/pages/email-inbox.filters.fields.mailboxes'))
                    ->options(fn (): array => $this->ownMailboxes()->pluck('email_address', 'id')->all())
                    ->required(),
            ])
            ->fillForm(fn (): array => ['mailboxes' => $this->filterTree()->mailboxIds])
            ->action(fn (array $data): bool => $this->setFilter(EmailFilter::Mailbox, ['$in' => array_values($data['mailboxes'])]));
    }

    public function sentAtFilterAction(): Action
    {
        return $this->filterChipAction(EmailFilter::SentAt)
            ->schema([
                DatePicker::make('after')->label(__('filament/pages/email-inbox.filters.fields.after')),
                DatePicker::make('before')->label(__('filament/pages/email-inbox.filters.fields.before'))->afterOrEqual('after'),
            ])
            ->fillForm(fn (): array => [
                'after' => $this->filterTree()->sentAfter?->setTimezone($this->viewerZone())->format('Y-m-d'),
                'before' => $this->filterTree()->sentBefore?->setTimezone($this->viewerZone())->subDay()->format('Y-m-d'),
            ])
            ->action(fn (array $data): bool => $this->setFilter(EmailFilter::SentAt, array_filter([
                '$gte' => filled($data['after']) ? Date::parse($data['after'], $this->viewerZone())->toIso8601String() : null,
                '$lt' => filled($data['before']) ? Date::parse($data['before'], $this->viewerZone())->addDay()->toIso8601String() : null,
            ]) ?: null));
    }

    public function recordFilterAction(): Action
    {
        return $this->filterChipAction(EmailFilter::Record)
            ->schema([
                LinkedRecordsSelect::make('records')
                    ->label(__('filament/pages/email-inbox.filters.fields.records'))
                    ->dehydrated()
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (count($this->recordKeysByType($value)) > 1) {
                            $fail(__('filament/pages/email-inbox.filters.one_record_type'));
                        }
                    }),
            ])
            ->action(function (array $data): bool {
                $type = array_key_first($this->recordKeysByType($data['records']));
                $word = array_search(CrmEntity::tryFrom((string) $type), EmailFilter::RECORD_WORDS, true);

                return is_string($word) && $this->setFilter(EmailFilter::Record, [$word => ['$in' => $this->recordKeysByType($data['records'])[$type]]]);
            });
    }

    public function categoryFilterAction(): Action
    {
        return $this->filterChipAction(EmailFilter::Category)
            ->schema([
                CheckboxList::make('categories')
                    ->label(__('filament/pages/email-inbox.filters.fields.categories'))
                    ->options(EmailCategory::class)
                    ->required(),
            ])
            ->fillForm(fn (): array => ['categories' => $this->filterTree()->categories])
            ->action(fn (array $data): bool => $this->setFilter(EmailFilter::Category, ['$in' => array_values(array_map(
                fn (EmailCategory|string $category): string => $category instanceof EmailCategory ? $category->value : $category,
                $data['categories'],
            ))]));
    }

    private function filterChipAction(EmailFilter $filter): Action
    {
        return Action::make(Str::camel($filter->field()).'Filter')
            ->label(fn (): string => $this->chipLabel($filter))
            ->button()
            ->outlined()
            ->size(Size::Small)
            ->color(fn (): string => isset($this->filterChips[$filter->field()]) ? 'primary' : 'gray')
            ->modalHeading($filter->label())
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('filament/pages/email-inbox.filters.apply'));
    }

    private function chipLabel(EmailFilter $filter): string
    {
        $value = $this->filterChips[$filter->field()] ?? null;

        return $value === null ? $filter->label() : __('filament/pages/email-inbox.filters.chip', ['filter' => $filter->label(), 'value' => $value]);
    }

    /** @param  array<string, mixed>|null  $condition */
    private function setFilter(EmailFilter $filter, ?array $condition): bool
    {
        $tree = $this->filterTree()->toTree();
        $tree[$filter->field()] = $condition;

        $this->filter = array_filter($tree, fn (?array $kept): bool => $kept !== null);
        $this->refreshConversationList();

        return true;
    }

    private function refreshConversationList(): void
    {
        $this->conversationView ??= $this->activeView;
        $this->conversationsCursor = null;
        unset($this->conversations, $this->conversationFilters, $this->filterError, $this->filterChips, $this->viewCounts);
    }

    private function filterTree(): ConversationFilters
    {
        try {
            return $this->parseFilterTree();
        } catch (ValidationException) {
            return new ConversationFilters;
        }
    }

    /** @throws ValidationException */
    private function parseFilterTree(): ConversationFilters
    {
        if (! is_array($this->filter)) {
            throw FilterErrors::at('filter', __('validation.filter.not_object'));
        }

        return ConversationFilters::fromTree($this->filter, $this->viewerZone());
    }

    private function describeFilter(EmailFilter $filter, ConversationFilters $filters): string
    {
        $list = fn (array $values): string => implode(', ', $values);

        return match ($filter) {
            EmailFilter::Mailbox => $list($this->ownMailboxes()->whereIn('id', $filters->mailboxIds)->pluck('email_address')->all())
                ?: trans_choice('filament/pages/email-inbox.filters.count', count($filters->mailboxIds)),
            EmailFilter::From => (string) $filters->from,
            EmailFilter::To => (string) $filters->to,
            EmailFilter::Direction => (string) $filters->direction?->getLabel(),
            EmailFilter::HasAttachment => __($filters->hasAttachment === true ? 'filament/pages/email-inbox.filters.yes' : 'filament/pages/email-inbox.filters.no'),
            EmailFilter::SentAt => $list(array_filter([
                $this->describeDay('after', $filters->sentAfter),
                $this->describeDay('before', $filters->sentBefore),
            ])),
            EmailFilter::Record => $this->describeRecords($filters),
            EmailFilter::Category => $list(array_map(fn (EmailCategory $category): string => $category->getLabel(), $filters->categories)),
        };
    }

    private function describeDay(string $bound, ?CarbonImmutable $instant): ?string
    {
        if (! $instant instanceof CarbonImmutable) {
            return null;
        }

        $date = ['date' => $instant->setTimezone($this->viewerZone())->translatedFormat('M j, Y')];

        return $bound === 'after'
            ? __('filament/pages/email-inbox.filters.after', $date)
            : __('filament/pages/email-inbox.filters.before', $date);
    }

    private function describeRecords(ConversationFilters $filters): string
    {
        if ($filters->recordName !== null && $filters->recordIds === []) {
            return __('filament/pages/email-inbox.filters.starts_with', ['name' => $filters->recordName]);
        }

        $type = $filters->recordType;

        if (! $type instanceof CrmEntity) {
            return '';
        }

        $names = $type->model()::query()
            ->withoutGlobalScopes([SoftDeletingScope::class, WorkspaceScope::class])
            ->where('workspace_id', $this->authUser()->current_workspace_id)
            ->whereKey($filters->recordIds)
            ->pluck($type->titleColumn())
            ->all();

        return implode(', ', $names) ?: trans_choice('filament/pages/email-inbox.filters.count', count($filters->recordIds));
    }

    /** @return array<string, list<string>> */
    private function recordKeysByType(mixed $keys): array
    {
        $grouped = [];

        foreach (is_array($keys) ? $keys : [] as $key) {
            [$type, $id] = [...explode(':', (string) $key, 2), ''];

            if ($id !== '') {
                $grouped[$type][] = $id;
            }
        }

        return $grouped;
    }

    /** @return Collection<int, ConnectedAccount> */
    private function ownMailboxes(): Collection
    {
        $workspace = filament()->getTenant();

        return $workspace instanceof Workspace
            ? ConnectedAccount::query()->ownedBy($this->authUser(), $workspace)->orderBy('email_address')->get()
            : new Collection;
    }

    private function viewerZone(): string
    {
        return $this->authUser()->effectiveTimezone();
    }

    abstract private function authUser(): User;
}
