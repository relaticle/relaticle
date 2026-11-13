<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Features\EmailConversations;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final readonly class OpenEmailController
{
    public function __construct(private EmailVisibilityService $visibility) {}

    /** @var array<string, class-string<CompanyResource|OpportunityResource|PeopleResource>> */
    private const array RECORD_RESOURCES = [
        'company' => CompanyResource::class,
        'opportunity' => OpportunityResource::class,
        'people' => PeopleResource::class,
    ];

    public function __invoke(Request $request, string $email, ConversationsQuery $conversations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $target = $this->emailFor($user, $email);
        $workspace = $this->bindWorkspace($user, $target);

        if (! Feature::for($workspace)->active(EmailConversations::class)) {
            $this->assertVisible($user, $target);

            return redirect()->to(Dashboard::getUrl(
                $user->can('viewBody', $target) ? ['email' => $target->getKey()] : [],
                panel: 'app',
                tenant: $workspace,
            ));
        }

        $copy = $conversations->viewerCopy($user, $target);

        abort_unless($copy instanceof Email, 404);

        return redirect()->to($this->recordUrl($copy, $workspace)
            ?? EmailInboxPage::getUrl(['view' => 'all', 'email' => $copy->getKey()], panel: 'app', tenant: $workspace));
    }

    private function emailFor(User $user, string $id): Email
    {
        $email = Email::query()->withoutGlobalScope(ActiveAccountScope::class)->withTrashed()->whereKey($id)->first();

        abort_unless($email instanceof Email && $user->belongsToWorkspaceId($email->workspace_id), 404);

        return $email;
    }

    private function assertVisible(User $user, Email $email): void
    {
        abort_unless(Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->whereKey($email->getKey())
            ->exists(), 404);
    }

    private function bindWorkspace(User $user, Email $email): Workspace
    {
        $workspace = Workspace::query()->findOrFail($email->workspace_id);

        $user->forceFill(['current_workspace_id' => $workspace->getKey()]);
        $user->setRelation('currentWorkspace', $workspace);

        return $workspace;
    }

    private function recordUrl(Email $copy, Workspace $workspace): ?string
    {
        foreach (self::RECORD_RESOURCES as $type => $resource) {
            $recordIds = DB::table('emailables')
                ->where('email_id', $copy->getKey())
                ->where('emailable_type', $type)
                ->pluck('emailable_id');

            $record = $resource::getModel()::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($recordIds->all())
                ->oldest('id')
                ->get()
                ->first(fn (Model $linked): bool => ! $this->visibility->hidesRecordMailbox($linked));

            if ($record instanceof Model) {
                return $resource::getUrl('view', [
                    'record' => $record,
                    'relation' => BaseEmailsRelationManager::relationKeyOn($resource),
                    'email' => $copy->getKey(),
                ], panel: 'app', tenant: $workspace);
            }
        }

        return null;
    }
}
