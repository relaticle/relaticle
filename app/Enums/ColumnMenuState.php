<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of the `tableColumnMenu` Alpine component (resources/js/filament/resizable-columns.js).
 */
enum ColumnMenuState: string
{
    case Column = 'column';
    case Neighbour = 'neighbour';
    case LeftTarget = 'before.left';
    case RightTarget = 'before.right';
    case CanMoveLeft = 'canMoveLeft';
    case CanMoveRight = 'canMoveRight';
    case Hideable = 'hideable';
}
