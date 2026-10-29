<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Data\TableStateData;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;
use App\Notifications\TableLayoutChangedNotification;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTableLayout
{
    public function __construct(private EnsureTableLayoutNameIsFree $ensureNameIsFree) {}

    /**
     * @param  array{name?: string, is_shared?: bool, state?: TableStateData}  $attributes
     */
    public function execute(User $user, TableLayout $layout, array $attributes): TableLayout
    {
        abort_unless($user->can('update', $layout), 403);

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = $this->ensureNameIsFree->execute($layout->user, $layout->workspace_id, $layout->entity, $attributes['name'], $layout);
        }

        if (array_key_exists('state', $attributes)) {
            $attributes['state'] = $attributes['state']->withoutWidths();
        }

        return DB::transaction(function () use ($user, $layout, $attributes): TableLayout {
            $layout->update($attributes);

            if ($layout->wasChanged('is_shared') && ! $layout->is_shared) {
                $this->forgetForOtherMembers($layout);
                $layout->user->notify(new TableLayoutChangedNotification($layout->workspace, $user, $layout->name, $layout->entity, TableLayoutChangedNotification::UNSHARED));
            }

            return $layout;
        });
    }

    private function forgetForOtherMembers(TableLayout $layout): void
    {
        $others = TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $layout->workspace_id)
            ->where('user_id', '!=', $layout->user_id);

        (clone $others)->where('active_layout_id', $layout->getKey())->update(['active_layout_id' => null, 'applied_layout_hash' => null]);
        (clone $others)->where('default_layout_id', $layout->getKey())->update(['default_layout_id' => null]);
    }
}
