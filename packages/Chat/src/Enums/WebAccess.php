<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\Chat\Models\PendingAction;
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
            self::resumesApprovedRequest($user, $workspace, $resumesTurnId) => self::Allowed,
            default => self::Ask,
        };
    }

    private static function resumesApprovedRequest(User $user, Workspace $workspace, ?string $resumesTurnId): bool
    {
        return $resumesTurnId !== null && PendingAction::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->where('turn_id', $resumesTurnId)
            ->where('entity_type', ProposalEntity::WebAccess)
            ->where('status', PendingActionStatus::Approved)
            ->exists();
    }

    public function promptLine(): string
    {
        return match ($this) {
            self::Unavailable => 'Web: you cannot open web pages or search the web on this turn. Ask the user to paste the page text or attach it as a .txt file.',
            self::Ask => 'Web: only when answering needs the web (a URL, a website, or something online), call RequestWebAccessTool once with the search you plan, before any web lookup and before any write for that request, then stop. A request the workspace data can answer, and every write that needs nothing from the web, never calls it.',
            self::Allowed => 'Web: you can search the web and read pages on this turn, up to 5 searches.',
        };
    }
}
