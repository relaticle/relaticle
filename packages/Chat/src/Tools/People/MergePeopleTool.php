<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseMergeTool;

final class MergePeopleTool extends BaseMergeTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose merging two people (duplicate contacts, the same person) into one. Returns a proposal for user approval. '
            .'The merge creates a NEW person from the two: the primary wins wherever both hold a value and the secondary fills the gaps. '
            .'Links, notes, tasks and history move to the new person, both originals stop being readable, and a merge cannot be undone. '
            .'Pass the person whose values win as primary_id. A person may appear in only one pair.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }
}
