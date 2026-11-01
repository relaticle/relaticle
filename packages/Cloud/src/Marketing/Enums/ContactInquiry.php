<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Enums;

enum ContactInquiry: string
{
    case Enterprise = 'enterprise';
    case Setup = 'setup';

    public function label(): string
    {
        return match ($this) {
            self::Enterprise => __('billing.plans.enterprise'),
            self::Setup => __('cloud::billing.services.setup.title'),
        };
    }

    public function price(): string
    {
        return match ($this) {
            self::Enterprise => __('cloud::billing.enterprise.starting_price', ['price' => number_format(config()->integer('relaticle.enterprise.starting_price_yearly'))]),
            self::Setup => __('cloud::billing.services.setup.price', ['price' => number_format(config()->integer('relaticle.guided_setup.price_yearly'))]),
        };
    }

    public function terms(): string
    {
        return match ($this) {
            self::Enterprise => __('cloud::billing.enterprise.terms'),
            self::Setup => __('cloud::billing.services.setup.terms'),
        };
    }
}
