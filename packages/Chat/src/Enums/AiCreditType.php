<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum AiCreditType: string
{
    case Chat = 'chat';
    case Summary = 'summary';
    case Embedding = 'embedding';
    case Adjustment = 'adjustment';
    case Refund = 'refund';
}
