<?php

declare(strict_types=1);

namespace App\Console\Concerns;

trait ReadsStringOptions
{
    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
