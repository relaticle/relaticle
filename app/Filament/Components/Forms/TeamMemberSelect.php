<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single way a workspace member is picked via a Filament relationship select.
 *
 * Not every member picker in the app panel: the chat proposal card
 * (Relaticle\Chat\Livewire\Chat\ProposalCard) builds an options-array select from
 * TeamMembersContext and deliberately does not use this component, because the pin
 * lives in the relationship() override and there is no relationship there.
 *
 * Pins the acting user to the top of the list, then falls back to alphabetical.
 * Filament only applies its own alphabetical order when the query carries none
 * (Select.php:1151), so the injected order wins without fighting it.
 */
final class TeamMemberSelect extends Select
{
    /**
     * Namespaced defensively so it cannot collide with a real `users` column
     * (RelationshipJoiner selects `users.*`, and our alias is added alongside it).
     */
    private const string IS_CURRENT_USER_ALIAS = 'team_member_select_is_current_user';

    /**
     * Orders the acting user first, then by name.
     *
     * auth()->id() is resolved when the query runs, not when the schema is built.
     * Capturing it at build time would pin one user for every subsequent request.
     *
     * @return Closure(Builder<User>): Builder<User>
     */
    public static function currentUserFirst(): Closure
    {
        return self::orderByCurrentUserFirst(...);
    }

    /**
     * Every BelongsToMany relationship query built by Filament's RelationshipJoiner
     * carries `->distinct()->select('users.*')` before this runs (assignees is
     * BelongsToMany). Postgres requires every ORDER BY expression in a SELECT
     * DISTINCT query to appear in the select list, so a bare `orderByRaw` on
     * `users.id = ?` fails with SQLSTATE[42P10]. Selecting the comparison as an
     * aliased column and ordering by that alias satisfies the rule. The alias is
     * a pure function of `users.id`, which is already part of the distinct row
     * (it's the primary key), so it cannot introduce new distinct combinations —
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
     * currentUserFirst() is applied BEFORE the caller's modifier, so a caller
     * adding its own orderBy becomes a secondary sort under the pin. A caller
     * that must override the pin should use a plain Select instead.
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
                $query = self::orderByCurrentUserFirst($query);

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->searchable();
        $this->preload();
        $this->getOptionLabelFromRecordUsing(
            fn (User $record): string => $record->getKey() === auth()->id()
                ? __('filament/panel.selects.member_self', ['name' => $record->name])
                : (string) $record->name,
        );
    }
}
