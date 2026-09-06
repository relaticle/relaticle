<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;
use Relaticle\CustomFields\Enums\OptionCategory;

enum OpportunityField: string
{
    use CustomFieldTrait;

    case AMOUNT = 'amount';
    case CLOSE_DATE = 'close_date';
    case STAGE = 'stage';

    /**
     * @return string[]|null
     */
    public function getOptions(): ?array
    {
        return match ($this) {
            self::STAGE => [
                'Prospecting',
                'Qualification',
                'Needs Analysis',
                'Value Proposition',
                'Id. Decision Makers',
                'Perception Analysis',
                'Proposal/Price Quote',
                'Negotiation/Review',
                'Closed Won',
                'Closed Lost',
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
            self::AMOUNT => 'Amount',
            self::CLOSE_DATE => 'Close Date',
            self::STAGE => 'Stage',
        };
    }

    public function isListToggleableHidden(): bool
    {
        return false;
    }

    /**
     * Get color mapping for select field options
     *
     * 2024 Sophisticated Sales Journey - A unique emotional progression through the
     * pipeline using earth-inspired, professional colors that tell the story of
     * building energy, trust, and momentum from first contact to final outcome.
     * Based on latest color psychology and 2024 design sophistication trends.
     *
     * @return array<int|string, string>|null Array of option => color mappings or null if not applicable
     */
    public function getOptionColors(): ?array
    {
        return match ($this) {
            self::STAGE => [
                'Prospecting' => '#a5b4fc',           // Misty Dawn - Soft exploration of possibilities
                'Qualification' => '#1e40af',         // Ocean Depth - Deep analytical thinking
                'Needs Analysis' => '#0d9488',        // Teal Understanding - Empathy & insight
                'Value Proposition' => '#eab308',     // Golden Clarity - Bright ideas & value
                'Id. Decision Makers' => '#7c3aed',   // Royal Authority - Power & influence
                'Perception Analysis' => '#f59e0b',   // Warm Amber - Comfortable evaluation
                'Proposal/Price Quote' => '#1e293b',  // Professional Navy - Serious business
                'Negotiation/Review' => '#f97316',    // Electric Coral - Dynamic energy
                'Closed Won' => '#059669',            // Victory Emerald - Success celebration
                'Closed Lost' => '#6b7280',           // Silver Acceptance - Respectful closure
            ],
            default => null,
        };
    }

    /**
     * Get category mapping for single-choice field options
     *
     * @return array<string, OptionCategory>|null Array of option => category mappings or null if not applicable
     */
    public function getOptionCategories(): ?array
    {
        return match ($this) {
            self::STAGE => [
                'Prospecting' => OptionCategory::Unstarted,
                'Qualification' => OptionCategory::Started,
                'Needs Analysis' => OptionCategory::Started,
                'Value Proposition' => OptionCategory::Started,
                'Id. Decision Makers' => OptionCategory::Started,
                'Perception Analysis' => OptionCategory::Started,
                'Proposal/Price Quote' => OptionCategory::Started,
                'Negotiation/Review' => OptionCategory::Started,
                'Closed Won' => OptionCategory::Completed,
                'Closed Lost' => OptionCategory::Cancelled,
            ],
            default => null,
        };
    }
}
