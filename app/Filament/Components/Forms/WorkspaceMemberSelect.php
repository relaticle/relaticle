<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Filament\Concerns\HasRecordChips;
use App\Filament\Concerns\RendersRecordPicker;
use App\Models\User;
use App\Models\Workspace;
use App\Support\LikePattern;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Renderless;
use Override;

/**
 * The single way a workspace member is picked via a Filament relationship select.
 *
 * Not every member picker in the app panel: the chat proposal card
 * (Relaticle\Chat\Livewire\Chat\ProposalCard) builds an options-array select from
 * WorkspaceMembersContext and deliberately does not use this component, because the pin
 * lives in the relationship() override and there is no relationship there.
 *
 * Restricts the option set to the current workspace, then pins the acting user to
 * the top of the list and falls back to alphabetical. Filament only applies its own
 * alphabetical order when the query carries none (Select.php:1151), so the injected
 * order wins without fighting it.
 *
 * @phpstan-import-type RecordOption from LinkedRecordsSelect
 * @phpstan-import-type RecordGroup from LinkedRecordsSelect
 */
final class WorkspaceMemberSelect extends Select
{
    use HasRecordChips;
    use RendersRecordPicker;

    /**
     * Namespaced defensively so it cannot collide with a real `users` column
     * (RelationshipJoiner selects `users.*`, and our alias is added alongside it).
     */
    private const string IS_CURRENT_USER_ALIAS = 'workspace_member_select_is_current_user';

    /**
     * Narrows a user query to the current workspace, then orders the acting user first.
     *
     * auth()->id() and the tenant are resolved when the query runs, not when the schema
     * is built. Capturing either at build time would pin one user, or one workspace,
     * for every subsequent request.
     *
     * @return Closure(Builder<User>): Builder<User>
     */
    public static function currentWorkspaceMembers(): Closure
    {
        return self::scopeToCurrentWorkspaceMembers(...);
    }

    /**
     * Both member relationships (Task::assignees() and the account-owner belongsTo)
     * resolve to an unconstrained User query, so without this every registered user on
     * the installation is a valid option. That is two defects, not one: the preloaded
     * picker lists every user's name, and Filament builds its `in` validation rule from
     * this same query (Select::getInValidationRuleValues()), so a hand-crafted Livewire
     * payload naming any of them passes validation and is written to the record.
     *
     * Fails closed when no tenant is bound: an unscoped fallback here is the bug.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function scopeToCurrentWorkspaceMembers(Builder $query): Builder
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return self::orderByCurrentUserFirst($query->whereIn('users.id', []));
        }

        return self::orderByCurrentUserFirst($query->memberOf($workspace));
    }

    /**
     * Every BelongsToMany relationship query built by Filament's RelationshipJoiner
     * carries `->distinct()->select('users.*')` before this runs (assignees is
     * BelongsToMany). Postgres requires every ORDER BY expression in a SELECT
     * DISTINCT query to appear in the select list, so a bare `orderByRaw` on
     * `users.id = ?` fails with SQLSTATE[42P10]. Selecting the comparison as an
     * aliased column and ordering by that alias satisfies the rule. The alias is
     * a pure function of `users.id`, which is already part of the distinct row
     * (it's the primary key), so it cannot introduce new distinct combinations;
     * row counts under DISTINCT are unchanged.
     *
     * Defensively re-selects the model's own columns first if nothing has been
     * selected yet (true for BelongsTo/HasOne/HasMany, where RelationshipJoiner
     * does not pre-select). Without it, `selectRaw()` on a query with no prior
     * `select()` would replace the result set with only the alias column, since
     * `addSelect()` does not default-populate `*` for a raw expression.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function orderByCurrentUserFirst(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('users.*');
        }

        return $query
            ->selectRaw(
                '(users.id = ?) as '.self::IS_CURRENT_USER_ALIAS,
                [auth()->id()],
            )
            ->orderByDesc(self::IS_CURRENT_USER_ALIAS)
            ->orderBy('users.name');
    }

    /**
     * The workspace scope and the pin are applied BEFORE the caller's modifier, so a
     * caller adding its own orderBy becomes a secondary sort under the pin, and a
     * caller cannot widen the option set past the current workspace. A caller that
     * must override the pin should use a plain Select instead.
     *
     * The caller's closure is routed through the component's own evaluate(),
     * with the same named `query`/`search` injections Filament itself passes
     * (Select.php:1004-1008 and siblings). Invoking it positionally would
     * silently narrow the contract: a modifier declaring `?string $search`,
     * `Get $get`, or `Model $record` would throw an ArgumentCountError instead
     * of resolving those parameters the way stock Filament does.
     */
    public function relationship(
        string|Closure|null $name = null,
        string|Closure|null $titleAttribute = null,
        ?Closure $modifyQueryUsing = null,
        bool $ignoreRecord = false,
    ): static {
        return parent::relationship(
            $name,
            $titleAttribute,
            function (Builder $query, ?string $search) use ($modifyQueryUsing): Builder {
                $query = self::scopeToCurrentWorkspaceMembers($query);

                if (! $modifyQueryUsing instanceof Closure) {
                    return $query;
                }

                return $this->evaluate($modifyQueryUsing, [
                    'query' => $query,
                    'search' => $search,
                ]) ?? $query;
            },
            $ignoreRecord,
        );
    }

    /**
     * @return list<RecordGroup>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getRecordGroupsForJs(?string $search = null): array
    {
        $search = trim((string) $search);

        $members = User::query()
            ->tap(self::currentWorkspaceMembers())
            ->when($search !== '', fn (Builder $query): Builder => $query->whereLike('users.name', '%'.LikePattern::escape($search).'%'))
            ->limit($this->getOptionsLimit())
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        return [[
            'label' => __('filament/components/member-picker.group'),
            'options' => $this->memberOptions($members),
        ]];
    }

    /**
     * @return list<RecordOption>
     */
    public function getSelectedRecordsForJs(): array
    {
        $state = array_values(array_filter((array) $this->getRawState(), is_string(...)));

        if ($state === []) {
            return [];
        }

        $members = User::query()
            ->tap(self::currentWorkspaceMembers())
            ->whereKey($state)
            ->get()
            ->sortBy(fn (User $member): int|false => array_search((string) $member->getKey(), $state, true));

        return $this->memberOptions($members);
    }

    #[Override]
    public function toEmbeddedHtml(): string
    {
        if (! $this->isMultiple()) {
            return parent::toEmbeddedHtml();
        }

        return $this->recordPickerHtml();
    }

    protected function pickerMessagesFile(): string
    {
        return 'filament/components/member-picker';
    }

    /**
     * @param  Collection<int, User>  $members
     * @return list<RecordOption>
     */
    private function memberOptions(Collection $members): array
    {
        return array_values($members
            ->map(fn (User $member): array => [
                'value' => (string) $member->getKey(),
                'name' => (string) $member->name,
                'html' => $this->memberLabel($member),
                'hint' => null,
                'url' => null,
            ])
            ->all());
    }

    private function memberLabel(User $member): string
    {
        return $this->recordChipLabel(
            $member,
            $member->getKey() === auth()->id()
                ? __('filament/panel.selects.member_self', ['name' => $member->name])
                : (string) $member->name,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->searchable();
        $this->preload();
        $this->allowHtml();
        $this->getOptionLabelFromRecordUsing(fn (User $record): string => $this->memberLabel($record));
    }
}
