<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailSettingsHeader;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;

/**
 * @property-read CreateAction $createEmailTemplateAction
 */
final class ManageEmailTemplates extends ManageRecords
{
    use HasEmailSettingsHeader;
    use HasWorkspaceSettingsNavigation;

    protected static string $resource = EmailTemplateResource::class;

    /**
     * Blank so the stock full-width header is not rendered: the page view carries its
     * own `<x-email-integration::settings-header />` inside the content column.
     */
    protected ?string $heading = '';

    public function shouldRenderSettingsBreadcrumbs(): bool
    {
        return false;
    }

    /**
     * @return array<int, Action>
     */
    public function settingsHeaderActions(): array
    {
        return [$this->createEmailTemplateAction];
    }

    public function createEmailTemplateAction(): CreateAction
    {
        return CreateAction::make('createEmailTemplate')
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->mutateFormDataUsing(function (array $data): array {
                $data['workspace_id'] = filament()->getTenant()?->getKey();
                $data['created_by'] = auth()->id();

                return $data;
            });
    }
}
