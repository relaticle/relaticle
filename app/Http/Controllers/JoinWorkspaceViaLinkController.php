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
use Illuminate\Http\Response;
use Illuminate\View\View;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

final readonly class JoinWorkspaceViaLinkController
{
    public function show(Request $request, string $token): RedirectResponse|Response|View
    {
        $workspace = $this->resolveWorkspace($token);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->belongsToWorkspace($workspace)) {
            $user->switchWorkspace($workspace);

            return $this->redirectToWorkspace($workspace, __('workspaces.accept.already_member', ['workspace' => $workspace->name]));
        }

        if ($workspace->isScheduledForDeletion()) {
            return $this->workspaceDeleting($workspace);
        }

        return view('workspaces.join-via-link', [
            'workspace' => $workspace,
            'token' => $token,
            'user' => $user,
            'roleName' => WorkspaceRole::labelFor($workspace->invite_link_default_role),
            'roleDescription' => WorkspaceRole::tryFrom($workspace->invite_link_default_role)?->description(),
            'memberCount' => $workspace->users()->count() + 1,
        ]);
    }

    public function store(Request $request, string $token, AddsTeamMembers $adder): RedirectResponse|Response|View
    {
        $workspace = $this->resolveWorkspace($token);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->belongsToWorkspace($workspace)) {
            $user->switchWorkspace($workspace);

            return $this->redirectToWorkspace($workspace, __('workspaces.accept.already_member', ['workspace' => $workspace->name]));
        }

        if ($workspace->isScheduledForDeletion()) {
            return $this->workspaceDeleting($workspace);
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

    private function resolveWorkspace(string $token): Workspace|View|Response
    {
        $workspace = Workspace::query()
            ->where('invite_link_token', $token)
            ->first();

        if (! $workspace instanceof Workspace) {
            return response()->view('workspaces.invite-link-expired', status: 404);
        }

        if ($workspace->isInviteLinkTokenExpired()) {
            return view('workspaces.invite-link-expired');
        }

        $user = request()->user();

        abort_if($user instanceof User && $user->isScheduledForDeletion(), 403, __('You cannot join workspaces while your account is scheduled for deletion.'));

        return $workspace;
    }

    private function workspaceDeleting(Workspace $workspace): Response
    {
        return response()->view('workspaces.workspace-deleting', ['workspaceName' => $workspace->name], 410);
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
