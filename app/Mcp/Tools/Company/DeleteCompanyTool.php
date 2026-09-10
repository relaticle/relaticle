<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\DeleteCompany;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\BaseDeleteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Company')]
#[Description('Delete a company from the CRM (soft delete).')]
final class DeleteCompanyTool extends BaseDeleteTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    protected function actionClass(): string
    {
        return DeleteCompany::class;
    }
}
