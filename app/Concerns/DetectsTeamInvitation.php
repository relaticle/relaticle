<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

trait DetectsTeamInvitation
{
    protected function getTeamInvitationFromSession(): ?TeamInvitation
    {
        $segment = $this->getIntendedUrlSegmentAfter('team-invitations');

        if ($segment === null) {
            return null;
        }

        return TeamInvitation::query()
            ->whereKey($segment)
            ->first();
    }

    protected function getTeamFromInviteLinkInSession(): ?Team
    {
        $token = $this->getIntendedUrlSegmentAfter('join');

        if ($token === null) {
            return null;
        }

        return Team::query()
            ->where('invite_link_token', $token)
            ->first();
    }

    protected function getTeamInvitationSubheading(): ?Htmlable
    {
        $invitation = $this->getTeamInvitationFromSession();

        if ($invitation && ! $invitation->isExpired()) {
            return $this->renderInvitationBanner($invitation->team->name);
        }

        $team = $this->getTeamFromInviteLinkInSession();

        if ($team && ! $team->isInviteLinkTokenExpired()) {
            return $this->renderInvitationBanner($team->name);
        }

        return null;
    }

    protected function getInvitationContentHtml(): string
    {
        $subheading = $this->getTeamInvitationSubheading();

        if ($subheading === null) {
            return '';
        }

        return '<p class="text-center text-sm text-gray-500 dark:text-gray-400">'.$subheading->toHtml().'</p>';
    }

    private function getIntendedUrlSegmentAfter(string $needle): ?string
    {
        $intendedUrl = session('url.intended', '');

        if (! str_contains((string) $intendedUrl, "/{$needle}/")) {
            return null;
        }

        $path = parse_url((string) $intendedUrl, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        $index = array_search($needle, $segments, true);

        if ($index === false || ! isset($segments[$index + 1])) {
            return null;
        }

        $value = $segments[$index + 1];

        return $value === '' ? null : $value;
    }

    private function renderInvitationBanner(string $teamName): HtmlString
    {
        return new HtmlString(
            __('You\'ve been invited to join <strong>:team</strong>', [
                'team' => e($teamName),
            ])
        );
    }
}
