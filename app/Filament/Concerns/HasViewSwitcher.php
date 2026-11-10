<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Resources\NoteResource\Pages\NotesCards;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\StoredTablePreferenceQuery;
use App\Queries\Views\VisibleSavedViewsQuery;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Relaticle\Flowforge\Contracts\HasBoard;

/**
 * Puts the view switcher at the start of the row under the topbar, so it keeps
 * a stable position when toggling between a resource's layouts.
 */
trait HasViewSwitcher
{
    public function mountHasViewSwitcher(): void
    {
        static::getResource()::rememberViewMode(static::getResourcePageName());
    }

    public function getHeader(): ?View
    {
        $header = parent::getHeader();

        if (! $this instanceof HasBoard || ! $header instanceof View) {
            return $header;
        }

        return view('filament.app.board-header', [
            'boardToolbar' => $header,
            'heading' => $this->getHeading(),
            'viewSwitcher' => $this->getViewSwitcher(),
        ]);
    }

    public function getViewSwitcher(): ?Htmlable
    {
        $pages = $this->viewSwitcherPages();
        $user = auth()->user();
        $workspace = Filament::getTenant();
        $model = static::getResource()::getModel();
        $entity = CrmEntity::tryFromModel($model::query()->getModel());

        if (! isset($pages['list']) || ! $user instanceof User || ! $workspace instanceof Workspace || ! $entity instanceof CrmEntity) {
            return null;
        }

        $canApply = in_array(HasSavedViews::class, class_uses_recursive($this), true);
        $views = method_exists($this, 'getVisibleSavedViews')
            ? $this->getVisibleSavedViews()
            : once(fn (): Collection => resolve(VisibleSavedViewsQuery::class)->get($user, $workspace, $entity));
        $activeId = property_exists($this, 'activeSavedViewId') ? $this->activeSavedViewId : null;
        $defaultId = property_exists($this, 'defaultSavedViewId')
            ? $this->defaultSavedViewId
            : once(fn (): ?string => resolve(StoredTablePreferenceQuery::class)->get($user, $workspace, $entity)?->default_view_id);
        $active = $views->first(fn (SavedView $view): bool => $view->getKey() === $activeId);
        $fixed = $this->viewSwitcherFixedEntries($pages);
        $current = collect($fixed)->firstWhere('current', true);

        return new HtmlString(view('filament.app.view-switcher', [
            'canApply' => $canApply,
            'activeName' => $active->name ?? $current['label'],
            'activeTone' => $active instanceof SavedView ? 'primary' : $current['tone'],
            'activeIcon' => $current['icon'],
            'fixed' => $fixed,
            'groups' => $this->viewSwitcherGroups($views, $user, $active, $defaultId, $pages['list']['name']),
            'searchable' => $views->count() >= 7,
            'hasActiveView' => $active instanceof SavedView,
            'hasViews' => $views->isNotEmpty(),
        ])->render());
    }

    public function getViewSettings(): ?Htmlable
    {
        $isList = $this->viewSwitcherPages()['list']['active'] ?? false;

        if (! $isList || ! method_exists($this, 'viewSettingsColumns') || ! auth()->user() instanceof User || ! Filament::getTenant() instanceof Workspace) {
            return null;
        }

        $columns = $this->viewSettingsColumns();

        return new HtmlString(view('filament.app.tables.view-settings', [
            'shown' => array_values(array_filter($columns, fn (array $column): bool => $column['isShown'])),
            'hidden' => array_values(array_filter($columns, fn (array $column): bool => ! $column['isShown'] && ! $column['isLocked'])),
            'canReset' => is_callable([$this, 'canResetTable']) && call_user_func([$this, 'canResetTable']),
        ])->render());
    }

    /**
     * @return array<string, array{name: string, url: string, active: bool}>
     */
    private function viewSwitcherPages(): array
    {
        $resource = static::getResource();
        $found = [];

        foreach ($resource::getPages() as $name => $registration) {
            $page = $registration->getPage();

            $isCollectionPage = is_a($page, ListRecords::class, true) || is_a($page, HasBoard::class, true);

            if (! $isCollectionPage || ! is_subclass_of($page, Page::class) || ! $page::canAccess()) {
                continue;
            }

            $kind = match (true) {
                is_a($page, HasBoard::class, true) => 'board',
                $page === NotesCards::class => 'cards',
                default => 'list',
            };

            $found[$kind] = ['name' => (string) $name, 'url' => $resource::getUrl((string) $name), 'active' => $this instanceof $page];
        }

        $pages = [];

        foreach (['list', 'board', 'cards'] as $kind) {
            if (isset($found[$kind])) {
                $pages[$kind] = $found[$kind];
            }
        }

        return $pages;
    }

    /**
     * @param  array<string, array{name: string, url: string, active: bool}>  $pages
     * @return list<array{kind: string, label: string, icon: string, tone: string, url: string, current: bool}>
     */
    private function viewSwitcherFixedEntries(array $pages): array
    {
        $records = str(static::getResource()::getPluralModelLabel())->lower()->toString();
        $icons = ['list' => 'lucide-table-2', 'board' => 'lucide-square-kanban', 'cards' => 'lucide-layout-grid'];
        $entries = [];

        foreach ($pages as $kind => $page) {
            $entries[] = [
                'kind' => $kind,
                'label' => $kind === 'list'
                    ? __('filament/panel.tables.views.standard', ['records' => $records])
                    : __("filament/pages/boards.view_switcher.{$kind}"),
                'icon' => $icons[$kind],
                'tone' => $kind === 'list' ? 'gray' : 'info',
                'url' => $kind === 'list' ? static::getResource()::getUrl($page['name'], ['view' => SavedView::BUILT_IN_LIST_LINK]) : $page['url'],
                'current' => $page['active'],
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<int, SavedView>  $views
     * @return list<array{heading: string, rows: list<array<string, mixed>>}>
     */
    private function viewSwitcherGroups(Collection $views, User $user, ?SavedView $active, ?string $defaultId, string $listPageName): array
    {
        $zone = FilamentTimezone::get();
        $groups = ['mine' => [], 'shared' => []];

        foreach ($views as $view) {
            $owner = $view->user_id === $user->getKey() ? null : $view->user?->name;
            $date = $view->created_at?->setTimezone($zone)->translatedFormat('M j, Y');

            $groups[$owner === null ? 'mine' : 'shared'][] = [
                'id' => $view->getKey(),
                'name' => $view->name,
                'owner' => $owner,
                'isShared' => $view->is_shared,
                'isDefault' => $view->getKey() === $defaultId,
                'isActive' => $view->is($active),
                'canUpdate' => $user->can('update', $view),
                'url' => static::getResource()::getUrl($listPageName, ['view' => $view->getKey()]),
                'createdLabel' => $owner === null
                    ? __('filament/panel.tables.views.created_on', ['date' => $date])
                    : __('filament/panel.tables.views.created_on_by', ['date' => $date, 'owner' => $owner]),
            ];
        }

        $headings = ['mine' => __('filament/panel.tables.views.mine'), 'shared' => __('filament/panel.tables.views.shared_with_workspace')];
        $result = [];

        foreach ($groups as $key => $rows) {
            if ($rows !== []) {
                $result[] = ['heading' => $headings[$key], 'rows' => $rows];
            }
        }

        return $result;
    }
}
