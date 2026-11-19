<?php

declare(strict_types=1);

namespace App\Filament\Pages\Workspace;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use Filament\Panel;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Relaticle\CustomFields\Data\EntityConfigurationData;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Filament\Management\Pages\CustomFieldsManagementPage;

/**
 * The packaged custom fields screen, rendered as a workspace settings tab.
 *
 * Registered through `CustomFieldsPlugin::managementPage()` so it replaces the
 * packaged page rather than sitting beside it. There is one route to this
 * screen, not two.
 */
final class CustomFields extends CustomFieldsManagementPage
{
    use HasWorkspaceSettingsNavigation;

    public const string NAVIGATION_ICON = 'lucide-box';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'workspace/custom-fields';
    }

    /** @return Collection<string, string> */
    #[Computed]
    public function entityTypes(): Collection
    {
        return Entities::globallyManaged()
            ->sortedByPriority()
            ->mapWithKeys(fn (EntityConfigurationData $entity): array => [
                $entity->getAlias() => $this->pluralLabel($entity),
            ]);
    }

    #[Computed]
    public function currentEntityLabel(): string
    {
        $entity = Entities::getEntity((string) $this->currentEntityType);

        return $entity instanceof EntityConfigurationData ? $this->pluralLabel($entity) : (string) $this->currentEntityType;
    }

    public function getTitle(): string
    {
        return __('custom-fields::custom-fields.heading.title');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    private function pluralLabel(EntityConfigurationData $entity): string
    {
        $resource = $entity->getResourceClass();

        return $resource !== null ? $resource::getPluralModelLabel() : $entity->getLabelPlural();
    }
}
