<?php

declare(strict_types=1);

namespace App\Data;

final readonly class LogoImage
{
    public function __construct(public string $bytes) {}
}
