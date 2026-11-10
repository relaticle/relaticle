<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Enums\CrmEntity;
use App\Enums\FilterFieldKind;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Models\User;
use App\Queries\Crm\LinkOptionsQuery;
use Filament\Facades\Filament;
use Livewire\Attributes\Renderless;

trait HasFilterBar
{
    /** @var array<string, string> */
    private array $nativeColumnLabels = [];

    /**
     * @return array<string, FilterField>
     */
    public function filterFieldCatalog(): array
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? FilterFieldCatalog::for($this->tableEntity(), $user, $this->nativeColumnLabels)
            : [];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    #[Renderless]
    public function searchFilterOptions(string $field, string $search = ''): array
    {
        $user = Filament::auth()->user();
        $definition = $this->filterFieldCatalog()[$field] ?? null;

        if (! $user instanceof User || ! $definition instanceof FilterField) {
            return [];
        }

        return match ($definition->kind) {
            FilterFieldKind::Member => resolve(LinkOptionsQuery::class)->search($user, null, $search),
            FilterFieldKind::Relation, FilterFieldKind::Record => $definition->related instanceof CrmEntity
                ? resolve(LinkOptionsQuery::class)->search($user, $definition->related, $search)
                : [],
            FilterFieldKind::Text, FilterFieldKind::Number, FilterFieldKind::Date, FilterFieldKind::Choice,
            FilterFieldKind::MultiChoice, FilterFieldKind::Checkbox, FilterFieldKind::MultiText, FilterFieldKind::Inactivity => [],
        };
    }
}
