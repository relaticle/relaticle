<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\Chat\Support\ModelDescriptor;

enum WebAccess
{
    case Unavailable;
    case Ask;
    case Allowed;

    private const array WEB_PROVIDERS = ['anthropic', 'openai'];

    public static function for(User $user, Workspace $workspace, ?ModelDescriptor $model, ?string $resumesTurnId): self
    {
        return match (true) {
            ! $model instanceof ModelDescriptor || $model->selfHosted => self::Unavailable,
            ! in_array($model->provider, self::WEB_PROVIDERS, true) => self::Unavailable,
            ! $workspace->ai_web_search_enabled => self::Unavailable,
            WebSearchPreference::of($user) === WebSearchPreference::Deny => self::Unavailable,
            WebSearchPreference::of($user) === WebSearchPreference::Allow => self::Allowed,
            default => self::Ask,
        };
    }

    public function promptLine(): string
    {
        return match ($this) {
            self::Unavailable => 'Web: you cannot open web pages or search the web on this turn. Ask the user to paste the page text or attach it as a .txt file.',
            self::Ask => 'Web: before any web lookup and before any write, call RequestWebAccessTool once with the search you plan, then stop.',
            self::Allowed => 'Web: you can search the web and read pages on this turn, up to 5 searches.',
        };
    }
}
