<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Closure;
use Filament\Forms\Components\TextInput;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class SharingTierChangeConfirmation
{
    public static function phrase(): string
    {
        return __('email/privacy-settings.sharing_confirmation.phrase');
    }

    public static function requiresTypedConfirmation(EmailPrivacyTier $tier): bool
    {
        return $tier === EmailPrivacyTier::FULL;
    }

    /**
     * @return array<int, TextInput>
     */
    public static function schema(EmailPrivacyTier $newTier): array
    {
        if (! self::requiresTypedConfirmation($newTier)) {
            return [];
        }

        $phrase = self::phrase();

        return [
            TextInput::make('full_access_confirmation')
                ->label(__('email/privacy-settings.sharing_confirmation.full_access_label', ['phrase' => $phrase]))
                ->placeholder($phrase)
                ->required()
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($phrase): void {
                    if (trim((string) $value) !== $phrase) {
                        $fail(__('email/privacy-settings.sharing_confirmation.phrase_mismatch', ['phrase' => $phrase]));
                    }
                }),
        ];
    }

    public static function modalHeading(): string
    {
        return __('email/privacy-settings.sharing_confirmation.heading');
    }

    public static function modalDescription(EmailPrivacyTier $newTier): string
    {
        return match ($newTier) {
            EmailPrivacyTier::FULL => __('email/privacy-settings.sharing_confirmation.full_access_description'),
            default => __('email/privacy-settings.sharing_confirmation.description', [
                'tier' => $newTier->getLabel(),
            ]),
        };
    }
}
