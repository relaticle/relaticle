<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

final readonly class SetupOpener
{
    /**
     * CommonMark punctuation escaped so user-authored text renders as literal
     * words instead of markdown syntax. Backslash comes first so escaping a
     * later character never re-escapes the backslash it just introduced.
     *
     * @var list<string>
     */
    private const array ESCAPED_CHARS = ['\\', '`', '*', '_', '[', ']', '(', ')', '#', '<', '>', '!', '|'];

    public function compose(Workspace $workspace): string
    {
        $paragraphs = [
            $this->useCaseLine($workspace),
            __('onboarding/setup.data'),
        ];

        if ($workspace->onboarding_referral_source === OnboardingReferralSource::AI) {
            $connectAssistantUrl = $this->connectAssistantUrl();

            if ($connectAssistantUrl !== null) {
                $paragraphs[] = __('onboarding/setup.ai_attribution', [
                    'link' => $this->link(__('onboarding/setup.ai_attribution_link'), $connectAssistantUrl),
                ]);
            }
        }

        $selfHostingUrl = $this->selfHostingUrl();

        if ($selfHostingUrl !== null) {
            $paragraphs[] = __('onboarding/setup.closing', [
                'link' => $this->link(__('onboarding/setup.closing_link'), $selfHostingUrl),
            ]);
        }

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
     * The Other text is user input rendered inside markdown, so its
     * punctuation is escaped rather than deleted: "Series A (2026)" still
     * reads as "Series A (2026)", it just cannot open a link or a code span.
     */
    public static function plainText(?string $text): string
    {
        $clean = PromptText::sanitize((string) $text, 120);

        if ($clean === '') {
            return '';
        }

        $escaped = str_replace(
            self::ESCAPED_CHARS,
            array_map(static fn (string $char): string => '\\'.$char, self::ESCAPED_CHARS),
            $clean,
        );

        $escaped = (string) preg_replace('/^([-+])/u', '\\\\$1', $escaped);

        return (string) preg_replace('/^(\d+)\./u', '$1\\\\.', $escaped);
    }

    public function selfHostingUrl(): ?string
    {
        if (! Route::has('documentation.show')) {
            return null;
        }

        return url()->getPublicUrl(route('documentation.show', ['type' => 'self-hosting'], absolute: false));
    }

    public function connectAssistantUrl(): ?string
    {
        if (! Route::has('help.show')) {
            return null;
        }

        return url()->getPublicUrl(route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt'], absolute: false));
    }

    private function link(string $label, string $url): string
    {
        return "[{$label}]({$url})";
    }
}
