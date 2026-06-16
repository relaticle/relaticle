<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\DeleteCompany;
use App\Models\Company;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteCompanyTool extends BaseWriteDeleteTool
{
    public function description(): string
    {
        return 'Propose deleting a company. Returns a proposal for user approval.';
    }

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

    protected function entityType(): string
    {
        return 'company';
    }
}
