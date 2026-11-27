<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\Concerns\MergesRecords;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Merge Companies')]
#[Description('Merge two companies into one new company. The primary\'s value wins for single-value attributes. List attributes (emails, domains, phones, tags, multi-select, record links) keep both records\' values, primary\'s first. When a combined list passes its field\'s limit, the workspace limit for that attribute rises to fit, up to 20, and above 20 the merge is refused. Links, notes, tasks and history move to the new record. Both originals become merged, cannot be read or restored, and a merge cannot be undone. Needs the create, update and delete abilities.')]
final class MergeCompaniesTool extends Tool
{
    use MergesRecords;
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }
}
