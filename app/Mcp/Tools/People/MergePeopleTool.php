<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\Concerns\MergesRecords;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Merge People')]
#[Description('Merge two people (contacts) into one. The merge creates a new person from the two: the primary wins wherever both hold a value and the secondary fills the gaps. Links, notes, tasks and history move to the new record. Both originals become merged and cannot be read or restored, and a merge cannot be undone. Needs the create, update and delete abilities.')]
final class MergePeopleTool extends Tool
{
    use MergesRecords;
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }
}
