<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\CustomFields\OpportunityField;
use Filament\Support\Contracts\HasLabel;

enum OnboardingUseCase: string implements HasLabel
{
    case Sales = 'sales';
    case CustomerSuccess = 'customer_success';
    case Recruiting = 'recruiting';
    case Marketing = 'marketing';
    case Fundraising = 'fundraising';
    case Investing = 'investing';
    case Other = 'other';

    private const array SUB_OPTION_KEYS = [
        'sales' => ['outbound', 'inbound', 'product_led', 'partner_led'],
        'customer_success' => ['high_touch', 'low_touch'],
        'recruiting' => ['applications', 'sourcing'],
        'marketing' => ['content', 'demand_gen', 'events', 'partnerships'],
        'fundraising' => ['early_stage', 'growth_stage', 'late_stage'],
        'investing' => ['early_stage', 'growth_stage', 'late_stage'],
    ];

    public function getLabel(?string $locale = null): string
    {
        return match ($this) {
            self::Sales => __('enums/onboarding-use-case.sales', [], $locale),
            self::CustomerSuccess => __('enums/onboarding-use-case.customer_success', [], $locale),
            self::Recruiting => __('enums/onboarding-use-case.recruiting', [], $locale),
            self::Marketing => __('enums/onboarding-use-case.marketing', [], $locale),
            self::Fundraising => __('enums/onboarding-use-case.fundraising', [], $locale),
            self::Investing => __('enums/onboarding-use-case.investing', [], $locale),
            self::Other => __('enums/onboarding-use-case.other', [], $locale),
        };
    }

    public function getFixtureSet(): string
    {
        return match ($this) {
            self::Sales => 'sales',
            self::CustomerSuccess => 'customer_success',
            self::Recruiting => 'recruiting',
            self::Marketing => 'marketing',
            self::Fundraising, self::Investing => 'fundraising',
            self::Other => 'general',
        };
    }

    /**
     * Pipeline stages this use case wants, mapped to their colour. Null keeps
     * the opportunity field's own defaults.
     *
     * @return array<string, string>|null
     */
    public function stagePreset(): ?array
    {
        return match ($this) {
            self::CustomerSuccess => [
                'Onboarding' => OptionColor::Purple->value,
                'Active' => OptionColor::Green->value,
                'Renewal due' => OptionColor::Amber->value,
                'At risk' => OptionColor::Orange->value,
                'Renewed' => OptionColor::Cyan->value,
                'Churned' => OptionColor::Gray->value,
            ],
            self::Recruiting => [
                'Sourced' => OptionColor::Purple->value,
                'Applied' => OptionColor::Blue->value,
                'Screen' => OptionColor::Cyan->value,
                'Interview' => OptionColor::Amber->value,
                'Offer' => OptionColor::Magenta->value,
                'Hired' => OptionColor::Green->value,
                'Declined' => OptionColor::Gray->value,
            ],
            self::Fundraising, self::Investing => [
                'Target' => OptionColor::Purple->value,
                'Intro' => OptionColor::Blue->value,
                'First meeting' => OptionColor::Cyan->value,
                'Partner meeting' => OptionColor::Amber->value,
                'Term sheet' => OptionColor::Magenta->value,
                'Closed' => OptionColor::Green->value,
                'Passed' => OptionColor::Gray->value,
            ],
            self::Sales, self::Marketing, self::Other => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function pipelineStages(): array
    {
        return $this->stagePreset() ?? OpportunityField::STAGE->getOptionColors() ?? [];
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Sales => __('enums/onboarding-use-case.descriptions.sales'),
            self::CustomerSuccess => __('enums/onboarding-use-case.descriptions.customer_success'),
            self::Recruiting => __('enums/onboarding-use-case.descriptions.recruiting'),
            self::Marketing => __('enums/onboarding-use-case.descriptions.marketing'),
            self::Fundraising => __('enums/onboarding-use-case.descriptions.fundraising'),
            self::Investing => __('enums/onboarding-use-case.descriptions.investing'),
            self::Other => __('enums/onboarding-use-case.descriptions.other'),
        };
    }

    public function toSubscriberTag(): string
    {
        return "use-case:{$this->value}";
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sales => 'lucide-trending-up',
            self::CustomerSuccess => 'lucide-hand-heart',
            self::Recruiting => 'lucide-users',
            self::Marketing => 'lucide-chart-column',
            self::Fundraising => 'lucide-circle-dollar-sign',
            self::Investing => 'lucide-chart-candlestick',
            self::Other => 'lucide-ellipsis',
        };
    }

    /**
     * @return array<string, string>
     */
    public function getSubOptions(?string $locale = null): array
    {
        return match ($this) {
            self::Other => [],
            default => collect(self::SUB_OPTION_KEYS[$this->value])
                ->mapWithKeys(fn (string $key): array => [$key => __("enums/onboarding-use-case.sub_options.{$this->value}.{$key}", [], $locale)])
                ->all(),
        };
    }
}
