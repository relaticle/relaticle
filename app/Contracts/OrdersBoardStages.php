<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Contracts\Support\Htmlable;

interface OrdersBoardStages
{
    /**
     * @param  list<mixed>  $optionIds
     */
    public function reorderBoardStages(array $optionIds): void;

    public function boardViewSettings(): Htmlable;

    public function boardLayoutToggles(): Htmlable;
}
