<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum ResponseFormat: string
{
    case Concise = 'concise';
    case Detailed = 'detailed';
}
