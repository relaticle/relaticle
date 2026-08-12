<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Illuminate\Contracts\View\View;
use Override;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;

final class ManageEmailTemplates extends ManageRecords
{
    protected static string $resource = EmailTemplateResource::class;

    /**
     * Header (breadcrumbs, heading, actions) is rendered into the content column via the
     * PAGE_HEADER_WIDGETS_BEFORE hook in EmailIntegrationServiceProvider, matching the
     * other Email settings cluster pages — see HasClusterBreadcrumbs.
     */
    #[Override]
    public function getHeader(): View
    {
        return view('email-integration::filament.pages.partials.no-header');
    }

    /**
     * The resource's own crumb is empty on the index page — name it after the cluster item.
     *
     * @return array<string, string>
     */
    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            ...array_filter(parent::getBreadcrumbs()),
            self::getResource()::getNavigationLabel(),
        ];
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus')
                ->size(Size::Small)
                ->mutateFormDataUsing(function (array $data): array {
                    $data['team_id'] = filament()->getTenant()?->getKey();
                    $data['created_by'] = auth()->id();

                    return $data;
                }),
        ];
    }
}
