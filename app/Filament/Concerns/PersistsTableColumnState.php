<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Users\UpdateTableColumnPreferences;
use App\Models\User;
use Filament\Tables\Concerns\InteractsWithTable;

/**
 * Persist the Filament column manager state (visibility toggles + column order)
 * per user in the database instead of the session.
 *
 * Filament's HasColumnManager stores column state under
 * `tables.{md5(class)}_columns` in the session by default. Session state dies
 * with the session (120 min TTL here) and never crosses devices, so a user who
 * hides "Created By" finds it back after the session expires. This trait
 * swaps the storage backend for the same state array, keyed by user + table.
 *
 * Add to any list page that should remember column choices:
 *
 *     use PersistsTableColumnState;
 *
 * Requires the `table_column_preferences` jsonb column on `users` and the
 * `UpdateTableColumnPreferences` action (both shipped alongside this trait).
 */
trait PersistsTableColumnState
{
    protected function loadTableColumnsFromSession(): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $key = $this->getTableColumnsSessionKey();
            $stored = $user->table_column_preferences[$key] ?? null;

            if (is_array($stored) && array_key_exists('columns', $stored)) {
                // Re-seed the session reorder flag so applyTableColumnManager()
                // still routes through the reorderable sync path.
                session()->put(
                    $this->getHasReorderedTableColumnsSessionKey(),
                    (bool) ($stored['has_reordered'] ?? false),
                );

                return $stored['columns'];
            }
        }

        return $this->getDefaultTableColumnState();
    }

    protected function persistTableColumns(): void
    {
        if (! $this->getTable()->persistsColumnsInSession()) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $preferences = $user->table_column_preferences ?? [];
        $preferences[$this->getTableColumnsSessionKey()] = [
            'columns' => $this->tableColumns,
            'has_reordered' => (bool) session()->get($this->getHasReorderedTableColumnsSessionKey(), false),
        ];

        app(UpdateTableColumnPreferences::class)->execute($user, $preferences);
    }

    protected function persistHasReorderedTableColumns(bool $wasReordered = false): void
    {
        parent::persistHasReorderedTableColumns($wasReordered);

        $this->persistTableColumns();
    }
}
