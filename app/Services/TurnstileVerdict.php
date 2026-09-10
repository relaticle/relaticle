<?php

declare(strict_types=1);

namespace App\Services;

final readonly class TurnstileVerdict
{
    /**
     * @param  list<string>  $errorCodes
     */
    public function __construct(
        public bool $success,
        public array $errorCodes = [],
    ) {}
}
