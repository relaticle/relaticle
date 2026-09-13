<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

final readonly class JoinWorkspaceViaLinkController
{
    public function show(Request $request, string $token): RedirectResponse|View
    {
        $workspace = $this->resolveWorkspace($token);

        if ($workspace instanceof View) {
            return $workspace;
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->belongsToWorkspace($workspace)) {
            $user->switchWorkspace($workspace);

            return $this->redirectToWorkspace($workspace, __('workspaces.accept.already_member', ['workspace' => $workspace->name]));
        }

        return view('workspaces.join-via-link', [
            'workspace' => $workspace,
            'token' => $token,
            'user' => $user,
            'roleName' => WorkspaceRole::label($workspace->invite_link_default_role),
            'roleDescription' => WorkspaceRole::description($workspace->invite_link_default_role),
            'memberCount' => $workspace->users()->count() + 1,
        ]);
    }

    public function store(Request $request, string $token, AddsTeamMembers $adder): RedirectResponse|View
    {
        $workspace = $this->resolveWorkspace($token);

        if ($workspace instanceof View) {
            return $workspace;
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->belongsToWorkspace($workspace)) {
            $user->switchWorkspace($workspace);

            return $this->redirectToWorkspace($workspace, __('workspaces.accept.already_member', ['workspace' => $workspace->name]));
        }

        /** @var User $owner */
        $owner = $workspace->owner;

        $adder->add(
            $owner,
            $workspace,
            $user->email,
            $workspace->invite_link_default_role,
        );

        $user->unsetRelation('workspaces');
        $user->switchWorkspace($workspace);

        return $this->redirectToWorkspace($workspace, __('workspaces.accept.joined', ['workspace' => $workspace->name]));
    }

    private function resolveWorkspace(string $token): Workspace|View
    {
        $workspace = Workspace::query()
            ->where('invite_link_token', $token)
            ->firstOrFail();

        if ($workspace->isInviteLinkTokenExpired()) {
            return view('workspaces.invite-link-expired');
        }

        abort_if($workspace->isScheduledForDeletion(), 410, __('This workspace is scheduled for deletion and is not accepting new members.'));

        $user = request()->user();

        abort_if($user instanceof User && $user->isScheduledForDeletion(), 403, __('You cannot join workspaces while your account is scheduled for deletion.'));

        return $workspace;
    }

    // getHomeUrl() resolves through the ambient tenant, not the request user, so
    // it must be primed when called from outside panel middleware.
    private function redirectToWorkspace(Workspace $workspace, string $message): RedirectResponse
    {
        Filament::setTenant($workspace, isQuiet: true);

        Notification::make()
            ->title($message)
            ->success()
            ->send();

        return redirect(Filament::getHomeUrl() ?? url()->getAppUrl());
    }
}
