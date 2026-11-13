<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Models\Opportunity;
use App\Models\Task;

dataset('stage order boards', [
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity],
]);
