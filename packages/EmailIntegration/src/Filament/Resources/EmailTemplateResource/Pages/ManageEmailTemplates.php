<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Override;
use Relaticle\EmailIntegration\Filament\Concerns\HasClusterBreadcrumbs;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;

/**
 * @property-read CreateAction $createEmailTemplateAction
 */
final class ManageEmailTemplates extends ManageRecords
{
    use HasClusterBreadcrumbs;

    protected static string $resource = EmailTemplateResource::class;

    /**
     * Blank so the stock full-width header is not rendered: the page view carries its
     * own `<x-email-integration::cluster-header />` inside the content column.
     */
    protected ?string $heading = '';

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

    /**
     * @return array<int, Action>
     */
    public function clusterHeaderActions(): array
    {
        return [$this->createEmailTemplateAction];
    }

    public function createEmailTemplateAction(): CreateAction
    {
        return CreateAction::make('createEmailTemplate')
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->mutateFormDataUsing(function (array $data): array {
                $data['team_id'] = filament()->getTenant()?->getKey();
                $data['created_by'] = auth()->id();

                return $data;
            });
    }
}
