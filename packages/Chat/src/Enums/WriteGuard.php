<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum WriteGuard: string
{
    case Api = 'api';       // provider enforces one-write-per-turn via provider options
    case Prompt = 'prompt'; // prompt-only; the PendingAction approval gate is the safety net
    case None = 'none';
}
