<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Models\Workspace;

final readonly class SetupOpener
{
    public function compose(Workspace $workspace): string
    {
        $paragraphs = [
            $this->useCaseLine($workspace),
            __('onboarding/setup.data'),
        ];

        if ($workspace->onboarding_referral_source === OnboardingReferralSource::AI) {
            $paragraphs[] = __('onboarding/setup.ai_attribution', [
                'link' => $this->link(__('onboarding/setup.ai_attribution_link'), $this->connectAssistantUrl()),
            ]);
        }

        $paragraphs[] = __('onboarding/setup.closing', [
            'link' => $this->link(__('onboarding/setup.closing_link'), $this->selfHostingUrl()),
        ]);

        return implode("\n\n", $paragraphs);
    }

    public function useCaseLine(Workspace $workspace): string
    {
        $useCase = $workspace->onboarding_use_case;

        if (! $useCase instanceof OnboardingUseCase) {
            return __('onboarding/setup.use_case.default');
        }

        if ($useCase !== OnboardingUseCase::Other) {
            return __("onboarding/setup.use_case.{$useCase->value}");
        }

        $text = self::plainText($workspace->onboarding_other_use_case);

        return $text === ''
            ? __('onboarding/setup.use_case.default')
            : __('onboarding/setup.use_case.other', ['text' => $text]);
    }

    /**
     * The Other text is user input rendered inside markdown, so link and
     * emphasis syntax is stripped rather than escaped.
     */
    public static function plainText(?string $text): string
    {
        $clean = PromptText::sanitize((string) $text, 120);

        return trim((string) preg_replace('/[\[\]()*_`#<>\\\\]/u', '', $clean));
    }

    public function selfHostingUrl(): string
    {
        return url()->getPublicUrl(route('documentation.show', ['type' => 'self-hosting'], absolute: false));
    }

    public function connectAssistantUrl(): string
    {
        return url()->getPublicUrl(route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt'], absolute: false));
    }

    private function link(string $label, string $url): string
    {
        return "[{$label}]({$url})";
    }
}
