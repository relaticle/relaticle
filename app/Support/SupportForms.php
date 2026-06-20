<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SupportFormType;

final readonly class SupportForms
{
    /**
     * Configured Maxforms URL for a support form, with context carried as
     * prefilled query params. Returns null when the form is not configured, so
     * the caller can hide the menu item.
     *
     * @param  array<string, string>  $prefill
     */
    public function publicUrl(SupportFormType $type, array $prefill = []): ?string
    {
        $url = config("support.forms.{$type->value}");

        if (blank($url)) {
            return null;
        }

        $url = (string) $url;
        $params = array_filter($prefill, static fn (string $value): bool => $value !== '');

        if ($params === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }
}
