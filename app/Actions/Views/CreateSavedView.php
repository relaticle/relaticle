<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateSavedView
{
    public function __construct(private EnsureSavedViewNameIsFree $ensureNameIsFree) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $name, TableStateData|BoardStateData $state): SavedView
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        $view = new SavedView([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'entity' => $entity,
            'type' => $state instanceof BoardStateData ? SavedViewType::Board : SavedViewType::Table,
            'state' => $state instanceof TableStateData ? $state->withoutWidths() : $state,
        ]);
        $view->name = $this->ensureNameIsFree->execute($user, $view, $name);

        try {
            DB::transaction(fn (): bool => $view->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('filament/panel.tables.views.name_taken')]);
        }

        return $view;
    }
}
