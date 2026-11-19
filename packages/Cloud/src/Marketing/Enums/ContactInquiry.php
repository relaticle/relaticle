<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Enums;

use Illuminate\Support\Number;

enum ContactInquiry: string
{
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Enterprise => __('billing.plans.enterprise'),
        };
    }

    public function price(): string
    {
        return match ($this) {
            self::Enterprise => __('cloud::billing.enterprise.starting_price', ['price' => Number::format(config()->integer('relaticle.enterprise.starting_price_yearly'))]),
        };
    }

    public function terms(): string
    {
        return match ($this) {
            self::Enterprise => __('cloud::billing.enterprise.terms'),
        };
    }
}
