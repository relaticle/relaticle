<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DuplicateSavedView
{
    public function execute(User $user, SavedView $view): SavedView
    {
        abort_unless($user->can('view', $view), 403);

        $copy = new SavedView([
            'workspace_id' => $view->workspace_id,
            'user_id' => $user->getKey(),
            'entity' => $view->entity,
            'type' => $view->type,
            'name' => $this->freeName($user, $view),
            'state' => $view->state,
        ]);

        try {
            DB::transaction(fn (): bool => $copy->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => $copy->nameTakenMessage($user)]);
        }

        return $copy;
    }

    private function freeName(User $user, SavedView $view): string
    {
        for ($number = 1; ; $number++) {
            $candidate = $this->candidate($view->name, $number);

            $taken = SavedView::query()
                ->named($user, $view->workspace_id, $view->entity, $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }

    private function candidate(string $sourceName, int $number): string
    {
        $numberSuffix = $number === 1 ? '' : " {$number}";
        $room = SavedView::NAME_MAX_LENGTH - mb_strlen(__('filament/panel.tables.views.copy_name', ['name' => ''])) - mb_strlen($numberSuffix);

        return __('filament/panel.tables.views.copy_name', ['name' => mb_substr($sourceName, 0, $room)]).$numberSuffix;
    }
}
