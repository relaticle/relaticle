<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single way a workspace member is picked anywhere in the app panel.
 *
 * Pins the acting user to the top of the list, then falls back to alphabetical.
 * Filament only applies its own alphabetical order when the query carries none
 * (Select.php:1151), so the injected order wins without fighting it.
 */
final class TeamMemberSelect extends Select
{
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
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function orderByCurrentUserFirst(Builder $query): Builder
    {
        return $query->orderByRaw(
            'users.id = ? desc, users.name',
            [auth()->id()],
        );
    }

    /**
     * currentUserFirst() is applied BEFORE the caller's modifier, so a caller
     * adding its own orderBy becomes a secondary sort under the pin. A caller
     * that must override the pin should use a plain Select instead.
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
            function (Builder $query) use ($modifyQueryUsing): Builder {
                $query = (self::currentUserFirst())($query);

                if ($modifyQueryUsing instanceof Closure) {
                    return $modifyQueryUsing($query) ?? $query;
                }

                return $query;
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
