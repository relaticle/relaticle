<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseMergeTool;

final class MergeCompaniesTool extends BaseMergeTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose merging two companies (duplicates, the same account) into one. Returns a proposal for user approval. '
            .'The merge creates a NEW company from the two: the primary wins wherever both hold a value and the secondary fills the gaps. '
            .'Links, notes, tasks and history move to the new company, both originals stop being readable, and a merge cannot be undone. '
            .'Pass the company to keep as primary_id. A company may appear in only one pair.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }
}
