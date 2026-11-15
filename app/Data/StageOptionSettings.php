<?php

declare(strict_types=1);

namespace App\Data;

use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;

final class StageOptionSettings extends CustomFieldOptionSettingsData
{
    public function __construct(
        ?string $color = null,
        public ?bool $celebrate = null,
    ) {
        parent::__construct($color);
    }
}
