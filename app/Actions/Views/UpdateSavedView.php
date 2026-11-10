<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\TableStateData;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Notifications\SavedViewChangedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateSavedView
{
    public function __construct(private EnsureSavedViewNameIsFree $ensureNameIsFree) {}

    /**
     * @param  array{name?: string, is_shared?: bool, state?: TableStateData}  $attributes
     */
    public function execute(User $user, SavedView $view, array $attributes): SavedView
    {
        abort_unless($user->can('update', $view), 403);

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = $this->ensureNameIsFree->execute($user, $view, $attributes['name']);
        }

        if (array_key_exists('state', $attributes)) {
            $attributes['state'] = $attributes['state']->withoutWidths();
        }

        return DB::transaction(function () use ($user, $view, $attributes): SavedView {
            SavedView::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($view->getKey())->lockForUpdate()->firstOrFail();

            $previousName = $view->name;
            $previousStateHash = $view->state->withoutWidths()->hash();
            $this->write($user, $view, $attributes);

            if ($view->wasChanged('is_shared') && ! $view->is_shared) {
                $this->forgetForOtherMembers($view);
            }

            $notice = $this->noticeFor($view, $user, $previousName, $previousStateHash);

            if ($notice instanceof SavedViewChangedNotification) {
                $view->user->notify($notice);
            }

            return $view;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(User $user, SavedView $view, array $attributes): void
    {
        try {
            $view->update($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => $view->nameTakenMessage($user)]);
        }
    }

    private function noticeFor(SavedView $view, User $actor, string $previousName, string $previousStateHash): ?SavedViewChangedNotification
    {
        return match (true) {
            $view->wasChanged('is_shared') && ! $view->is_shared => SavedViewChangedNotification::unshared($view, $actor),
            $view->wasChanged('name') => SavedViewChangedNotification::renamed($view, $actor, $previousName),
            $view->state->withoutWidths()->hash() !== $previousStateHash => SavedViewChangedNotification::updated($view, $actor),
            default => null,
        };
    }

    private function forgetForOtherMembers(SavedView $view): void
    {
        $others = TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $view->workspace_id)
            ->where('user_id', '!=', $view->user_id);

        (clone $others)->where('active_view_id', $view->getKey())->update(['active_view_id' => null, 'applied_view_hash' => null]);
        (clone $others)->where('default_view_id', $view->getKey())->update(['default_view_id' => null]);
    }
}
