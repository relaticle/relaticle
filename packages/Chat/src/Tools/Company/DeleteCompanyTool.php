<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\DeleteCompany;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteCompanyTool extends BaseWriteDeleteTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose deleting a company. Returns a proposal for user approval.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    protected function actionClass(): string
    {
        return DeleteCompany::class;
    }
}
