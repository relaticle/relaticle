<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\DeletePeople;
use App\Mcp\Tools\BaseDeleteTool;
use App\Models\People;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Person')]
#[Description('Delete a person (contact) from the CRM (soft delete).')]
final class DeletePeopleTool extends BaseDeleteTool
{
    protected function modelClass(): string
    {
        return People::class;
    }

    protected function actionClass(): string
    {
        return DeletePeople::class;
    }

    protected function entityLabel(): string
    {
        return 'Person';
    }
}
