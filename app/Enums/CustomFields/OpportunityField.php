<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;
use App\Enums\OptionColor;

enum OpportunityField: string
{
    use CustomFieldTrait;

    case AMOUNT = 'amount';
    case CLOSE_DATE = 'close_date';
    case STAGE = 'stage';

    /**
     * @return array<string, string>|null
     */
    public function optionKeyColors(): ?array
    {
        return match ($this) {
            self::STAGE => [
                'enums/custom-fields.opportunity.stage.options.prospecting' => OptionColor::Purple->value,
                'enums/custom-fields.opportunity.stage.options.qualification' => OptionColor::Blue->value,
                'enums/custom-fields.opportunity.stage.options.needs_analysis' => OptionColor::Cyan->value,
                'enums/custom-fields.opportunity.stage.options.value_proposition' => OptionColor::Olive->value,
                'enums/custom-fields.opportunity.stage.options.id_decision_makers' => OptionColor::Magenta->value,
                'enums/custom-fields.opportunity.stage.options.perception_analysis' => OptionColor::Amber->value,
                'enums/custom-fields.opportunity.stage.options.proposal_price_quote' => OptionColor::Pink->value,
                'enums/custom-fields.opportunity.stage.options.negotiation_review' => OptionColor::Orange->value,
                'enums/custom-fields.opportunity.stage.options.closed_won' => OptionColor::Green->value,
                'enums/custom-fields.opportunity.stage.options.closed_lost' => OptionColor::Gray->value,
            ],
            default => null,
        };
    }

    public function getFieldType(): string
    {
        return match ($this) {
            self::AMOUNT => CustomFieldType::CURRENCY->value,
            self::CLOSE_DATE => CustomFieldType::DATE->value,
            self::STAGE => CustomFieldType::SELECT->value,
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::AMOUNT => __('enums/custom-fields.opportunity.amount.name'),
            self::CLOSE_DATE => __('enums/custom-fields.opportunity.close_date.name'),
            self::STAGE => __('enums/custom-fields.opportunity.stage.name'),
        };
    }

    public function isListToggleableHidden(): bool
    {
        return false;
    }
}
