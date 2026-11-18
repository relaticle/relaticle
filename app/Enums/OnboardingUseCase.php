<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\CustomFields\OpportunityField;
use App\Onboarding\OptionLabels;
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
        $keyColors = $this->stagePresetKeyColors();

        if ($keyColors === null) {
            return null;
        }

        $preset = [];

        foreach ($keyColors as $key => $color) {
            $preset[__($key)] = $color;
        }

        return $preset;
    }

    /**
     * @return array<string, string>|null
     */
    public function stagePresetKeyColors(): ?array
    {
        return match ($this) {
            self::CustomerSuccess => [
                'enums/onboarding-use-case.stages.customer_success.onboarding' => OptionColor::Purple->value,
                'enums/onboarding-use-case.stages.customer_success.active' => OptionColor::Green->value,
                'enums/onboarding-use-case.stages.customer_success.renewal_due' => OptionColor::Amber->value,
                'enums/onboarding-use-case.stages.customer_success.at_risk' => OptionColor::Orange->value,
                'enums/onboarding-use-case.stages.customer_success.renewed' => OptionColor::Cyan->value,
                'enums/onboarding-use-case.stages.customer_success.churned' => OptionColor::Gray->value,
            ],
            self::Recruiting => [
                'enums/onboarding-use-case.stages.recruiting.sourced' => OptionColor::Purple->value,
                'enums/onboarding-use-case.stages.recruiting.applied' => OptionColor::Blue->value,
                'enums/onboarding-use-case.stages.recruiting.screen' => OptionColor::Cyan->value,
                'enums/onboarding-use-case.stages.recruiting.interview' => OptionColor::Amber->value,
                'enums/onboarding-use-case.stages.recruiting.offer' => OptionColor::Magenta->value,
                'enums/onboarding-use-case.stages.recruiting.hired' => OptionColor::Green->value,
                'enums/onboarding-use-case.stages.recruiting.declined' => OptionColor::Gray->value,
            ],
            self::Fundraising, self::Investing => [
                'enums/onboarding-use-case.stages.fundraising.target' => OptionColor::Purple->value,
                'enums/onboarding-use-case.stages.fundraising.intro' => OptionColor::Blue->value,
                'enums/onboarding-use-case.stages.fundraising.first_meeting' => OptionColor::Cyan->value,
                'enums/onboarding-use-case.stages.fundraising.partner_meeting' => OptionColor::Amber->value,
                'enums/onboarding-use-case.stages.fundraising.term_sheet' => OptionColor::Magenta->value,
                'enums/onboarding-use-case.stages.fundraising.closed' => OptionColor::Green->value,
                'enums/onboarding-use-case.stages.fundraising.passed' => OptionColor::Gray->value,
            ],
            self::Sales, self::Marketing, self::Other => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function stageLabelsFor(string $english): array
    {
        $keys = array_merge(...array_map(
            fn (self $useCase): array => array_keys($useCase->stagePresetKeyColors() ?? []),
            self::cases(),
        ));

        return OptionLabels::inEveryLocale($keys, $english);
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
