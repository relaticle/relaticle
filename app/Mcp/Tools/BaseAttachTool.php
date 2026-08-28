<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

abstract class BaseAttachTool extends BaseRelationshipTool
{
    protected function destructiveHint(): bool
    {
        return false;
    }
}
