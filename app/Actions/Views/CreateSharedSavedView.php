<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class CreateSharedSavedView
{
    public function __construct(
        private CreateSavedView $create,
        private UpdateSavedView $update,
    ) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $name, TableStateData $state): SavedView
    {
        return DB::transaction(fn (): SavedView => $this->update->execute(
            $user,
            $this->create->execute($user, $workspace, $entity, $name, $state),
            ['is_shared' => true],
        ));
    }
}
