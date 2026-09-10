<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Actions\People\DeletePeople;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeletePersonTool extends BaseWriteDeleteTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose deleting a person/contact. Returns a proposal for user approval.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    protected function actionClass(): string
    {
        return DeletePeople::class;
    }
}
