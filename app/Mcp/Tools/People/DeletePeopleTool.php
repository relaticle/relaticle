<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\DeletePeople;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\BaseDeleteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Person')]
#[Description('Delete a person (contact) from the CRM (soft delete).')]
final class DeletePeopleTool extends BaseDeleteTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    protected function actionClass(): string
    {
        return DeletePeople::class;
    }
}
