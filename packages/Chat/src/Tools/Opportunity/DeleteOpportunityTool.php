<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Actions\Opportunity\DeleteOpportunity;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteOpportunityTool extends BaseWriteDeleteTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose deleting an opportunity/deal. Returns a proposal for user approval.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    protected function actionClass(): string
    {
        return DeleteOpportunity::class;
    }
}
