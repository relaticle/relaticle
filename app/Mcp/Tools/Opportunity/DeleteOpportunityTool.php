<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\DeleteOpportunity;
use App\Mcp\Tools\BaseDeleteTool;
use App\Models\Opportunity;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Opportunity')]
#[Description('Delete an opportunity (deal) from the CRM (soft delete).')]
final class DeleteOpportunityTool extends BaseDeleteTool
{
    protected function modelClass(): string
    {
        return Opportunity::class;
    }

    protected function actionClass(): string
    {
        return DeleteOpportunity::class;
    }

    protected function entityLabel(): string
    {
        return 'Opportunity';
    }
}
