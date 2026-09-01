<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\EmailAddress;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

final readonly class AsCanonicalEmail implements CastsInboundAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return EmailAddress::canonicalize((string) $value);
    }
}
