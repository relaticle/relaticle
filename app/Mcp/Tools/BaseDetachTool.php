<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

abstract class BaseDetachTool extends BaseRelationshipTool
{
    protected function destructiveHint(): bool
    {
        return true;
    }
}
