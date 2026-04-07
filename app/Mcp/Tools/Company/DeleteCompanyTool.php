<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\DeleteCompany;
use App\Mcp\Tools\BaseDeleteTool;
use App\Models\Company;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a company from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteCompanyTool extends BaseDeleteTool
{
    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function actionClass(): string
    {
        return DeleteCompany::class;
    }

    protected function entityLabel(): string
    {
        return 'Company';
    }
}
