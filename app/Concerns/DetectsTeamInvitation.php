<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\TeamInvitation;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

trait DetectsTeamInvitation
{
    protected function getTeamInvitationFromSession(): ?TeamInvitation
    {
        $intendedUrl = session('url.intended', '');

        if (! str_contains((string) $intendedUrl, '/team-invitations/')) {
            return null;
        }

        $path = parse_url((string) $intendedUrl, PHP_URL_PATH);

        if (! $path) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        $invitationIndex = array_search('team-invitations', $segments, true);

        if ($invitationIndex === false || ! isset($segments[$invitationIndex + 1])) {
            return null;
        }

        return TeamInvitation::query()
            ->whereKey($segments[$invitationIndex + 1])
            ->first();
    }

    protected function getTeamInvitationSubheading(): ?Htmlable
    {
        $invitation = $this->getTeamInvitationFromSession();

        if (! $invitation || $invitation->isExpired()) {
            return null;
        }

        return new HtmlString(
            __('You\'ve been invited to join <strong>:team</strong>', [
                'team' => e($invitation->team->name),
            ])
        );
    }

    protected function getInvitationContentHtml(): string
    {
        $subheading = $this->getTeamInvitationSubheading();

        if ($subheading === null) {
            return '';
        }

        return '<p class="text-center text-sm text-gray-500 dark:text-gray-400">'.$subheading->toHtml().'</p>';
    }
}
