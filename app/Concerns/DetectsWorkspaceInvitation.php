<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

trait DetectsWorkspaceInvitation
{
    protected function getWorkspaceInvitationFromSession(): ?WorkspaceInvitation
    {
        $token = $this->getIntendedUrlSegmentAfter('invitations');

        if ($token === null) {
            return null;
        }

        return WorkspaceInvitation::findByRawToken($token);
    }

    protected function getWorkspaceFromInviteLinkInSession(): ?Workspace
    {
        $token = $this->getIntendedUrlSegmentAfter('join');

        if ($token === null) {
            return null;
        }

        return Workspace::query()
            ->where('invite_link_token', $token)
            ->first();
    }

    protected function getWorkspaceInvitationSubheading(): ?Htmlable
    {
        $invitation = $this->getWorkspaceInvitationFromSession();

        if ($invitation && ! $invitation->isExpired()) {
            return $this->renderInvitationBanner($invitation->workspace->name);
        }

        $workspace = $this->getWorkspaceFromInviteLinkInSession();

        if ($workspace && ! $workspace->isInviteLinkTokenExpired()) {
            return $this->renderInvitationBanner($workspace->name);
        }

        return null;
    }

    protected function getInvitationContentHtml(): string
    {
        $subheading = $this->getWorkspaceInvitationSubheading();

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

    private function renderInvitationBanner(string $workspaceName): HtmlString
    {
        return new HtmlString(
            __('You\'ve been invited to join <strong>:workspace</strong>', [
                'workspace' => e($workspaceName),
            ])
        );
    }
}
