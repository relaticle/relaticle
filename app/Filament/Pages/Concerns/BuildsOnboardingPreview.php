<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Features\EmailIntegration;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;

trait BuildsOnboardingPreview
{
    /**
     * @param  array<string, string>  $stages
     * @return array{
     *     companyPlaceholder: string,
     *     workspaceName: string,
     *     workspaceAvatarUrl: string,
     *     userAvatarUrl: string,
     *     greeting: string,
     *     navigationIcons: array<string, string|BackedEnum|Htmlable|null>,
     *     stages: list<array{name: string, color: string}>
     * }
     */
    protected function onboardingPreview(string $workspaceName, ?string $logoUrl, string $userName, array $stages): array
    {
        /** @var User $user */
        $user = auth('web')->user();

        $previewUser = clone $user;
        $previewUser->name = $userName;

        return [
            'companyPlaceholder' => (string) __('filament/pages/workspaces.create_workspace.preview.company_placeholder'),
            'workspaceName' => $workspaceName,
            'workspaceAvatarUrl' => $logoUrl ?? new Workspace(['name' => $workspaceName])->getFilamentAvatarUrl(),
            'userAvatarUrl' => $previewUser->getFilamentAvatarUrl(),
            'greeting' => Dashboard::greetingFor($user, explode(' ', $userName)[0]),
            'navigationIcons' => $this->previewNavigationIcons(),
            'stages' => array_map(
                fn (string $name, string $color): array => ['name' => $name, 'color' => $color],
                array_keys($stages),
                $stages,
            ),
        ];
    }

    /**
     * @return array<string, string|BackedEnum|Htmlable|null>
     */
    private function previewNavigationIcons(): array
    {
        $icons = [
            'dashboard' => Dashboard::getNavigationIcon(),
            'people' => PeopleResource::getNavigationIcon(),
            'companies' => CompanyResource::getNavigationIcon(),
            'opportunities' => OpportunityResource::getNavigationIcon(),
            'tasks' => TaskResource::getNavigationIcon(),
            'notes' => NoteResource::getNavigationIcon(),
        ];

        if (Feature::active(EmailIntegration::class)) {
            $icons['emails'] = EmailInboxPage::getNavigationIcon();
        }

        return $icons;
    }
}
