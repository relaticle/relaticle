<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final readonly class CreateSavedView
{
    public function __construct(private EnsureSavedViewNameIsFree $ensureNameIsFree) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $name, TableStateData $state): SavedView
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        $view = new SavedView([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'entity' => $entity,
            'state' => $state->withoutWidths(),
        ]);
        $view->name = $this->ensureNameIsFree->execute($user, $view, $name);

        try {
            $view->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => $view->nameTakenMessage($user)]);
        }

        return $view;
    }
}
