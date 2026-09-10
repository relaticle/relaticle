<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\DeleteOpportunity;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\BaseDeleteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Opportunity')]
#[Description('Delete an opportunity (deal) from the CRM (soft delete).')]
final class DeleteOpportunityTool extends BaseDeleteTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    protected function actionClass(): string
    {
        return DeleteOpportunity::class;
    }
}
