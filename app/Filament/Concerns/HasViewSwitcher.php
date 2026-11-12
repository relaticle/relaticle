<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\ReorderViews;
use App\Contracts\OrdersBoardStages;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Filament\Resources\NoteResource\Pages\NotesCards;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\ViewOrderQuery;
use App\Queries\Views\VisibleSavedViewsQuery;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use LogicException;
use Relaticle\Flowforge\Contracts\HasBoard;

/**
 * Puts the view switcher at the start of the row under the topbar, so it keeps
 * a stable position when toggling between a resource's layouts.
 */
trait HasViewSwitcher
{
    private const array VIEW_TONES = ['list' => 'success', 'board' => 'orange', 'cards' => 'info'];

    /** @var array<string, list<string>> */
    private array $viewOrderCache = [];

    public function mountHasViewSwitcher(): void
    {
        $this->redirectToFirstView();
    }

    public function getHeader(): ?View
    {
        $header = parent::getHeader();

        if (! $this instanceof HasBoard || ! $header instanceof View) {
            return $header;
        }

        return view('filament.app.board-header', [
            'heading' => $this->getHeading(),
            'viewSwitcher' => $this->getViewSwitcher(),
            'viewSettings' => $this->getViewSettings(),
        ]);
    }

    /**
     * @param  list<mixed>  $keys
     */
    public function reorderViews(array $keys): void
    {
        $known = array_values(array_intersect(array_filter($keys, is_string(...)), $this->viewOrder()));

        if ($known === []) {
            return;
        }

        resolve(ReorderViews::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $known);
        $this->viewOrderCache = [];
    }

    public function getViewSwitcher(): ?Htmlable
    {
        $pages = $this->viewSwitcherPages();

        if (! isset($pages['list']) || ! $this->canSwitchViews()) {
            return null;
        }

        $entries = $this->viewSwitcherEntries($pages);
        $current = collect($entries)->firstWhere('active', true) ?? $entries[0];

        return new HtmlString(view('filament.app.view-switcher', [
            'canApply' => in_array(ManagesSavedViews::class, class_uses_recursive($this), true),
            'activeName' => $current['label'],
            'activeTone' => $current['tone'],
            'activeIcon' => $current['icon'],
            'entries' => $entries,
        ])->render());
    }

    /**
     * @return list<string>
     */
    protected function viewOrder(): array
    {
        $kinds = array_keys($this->viewSwitcherPages());
        $views = $this->viewSwitcherSavedViews();

        return $this->viewOrderCache[implode(',', [...$kinds, ...$views->modelKeys()])] ??= resolve(ViewOrderQuery::class)->get(
            $this->viewSwitcherUser(),
            $this->viewSwitcherWorkspace(),
            $this->viewSwitcherEntity(),
            $kinds,
            $views,
        );
    }

    protected function isBareArrival(): bool
    {
        return ! resolve(HandleRequests::class)->isLivewireRequest() && request()->query->count() === 0;
    }

    private function redirectToFirstView(): void
    {
        $pages = $this->viewSwitcherPages();
        $current = array_key_first(array_filter($pages, static fn (array $page): bool => $page['active']));

        if ($current === null || $pages[$current]['name'] !== 'index' || ! $this->isBareArrival() || ! $this->canSwitchViews()) {
            return;
        }

        $first = $this->viewOrder()[0] ?? $current;
        $firstView = $this->viewSwitcherSavedViews()->find($first);
        $target = isset($pages[$first]) ? $first : 'list';

        $url = $firstView?->type === SavedViewType::Board
            ? $this->boardViewUrl($firstView)
            : ($target !== $current ? $pages[$target]['url'] : null);

        if ($url !== null) {
            $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
        }
    }

    protected function savedViewPageUrl(SavedView $view): string
    {
        $layout = $view->type === SavedViewType::Board ? 'board' : 'list';

        return static::getResource()::getUrl($this->viewSwitcherPages()[$layout]['name'], ['view' => $view->getKey()]);
    }

    protected function boardViewUrl(SavedView $view): ?string
    {
        $board = $this->viewSwitcherPages()['board'] ?? null;

        return $board === null ? null : static::getResource()::getUrl($board['name'], ['view' => $view->getKey()]);
    }

    private function canSwitchViews(): bool
    {
        $model = static::getResource()::getModel();

        return auth()->user() instanceof User
            && Filament::getTenant() instanceof Workspace
            && CrmEntity::tryFromModel($model::query()->getModel()) instanceof CrmEntity;
    }

    private function viewSwitcherUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function viewSwitcherWorkspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }

    private function viewSwitcherEntity(): CrmEntity
    {
        $model = static::getResource()::getModel();

        return CrmEntity::tryFromModel($model::query()->getModel()) ?? throw new LogicException("{$model} is not a CRM entity.");
    }

    /**
     * @return Collection<int, SavedView>
     */
    private function viewSwitcherSavedViews(): Collection
    {
        $views = method_exists($this, 'getVisibleSavedViews')
            ? $this->getVisibleSavedViews()
            : once(fn (): Collection => resolve(VisibleSavedViewsQuery::class)->get($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity()));

        if (isset($this->viewSwitcherPages()['board'])) {
            return $views;
        }

        return $views->reject(static fn (SavedView $view): bool => $view->type === SavedViewType::Board)->values();
    }

    public function getViewSettings(): ?Htmlable
    {
        if ($this instanceof OrdersBoardStages && $this->canSwitchViews()) {
            return $this->boardViewSettings();
        }

        $isList = $this->viewSwitcherPages()['list']['active'] ?? false;

        if (! $isList || ! method_exists($this, 'viewSettingsColumns') || ! auth()->user() instanceof User || ! Filament::getTenant() instanceof Workspace) {
            return null;
        }

        $columns = $this->viewSettingsColumns();

        return new HtmlString(view('filament.app.tables.view-settings', [
            'shown' => array_values(array_filter($columns, fn (array $column): bool => $column['isShown'])),
            'hidden' => array_values(array_filter($columns, fn (array $column): bool => ! $column['isShown'] && ! $column['isLocked'])),
            'entityLabel' => Str::lower(static::getResource()::getModelLabel()),
            'canCreateAttribute' => is_callable([$this, 'canCreateTableAttribute']) && call_user_func([$this, 'canCreateTableAttribute']),
        ])->render());
    }

    /**
     * @return array<string, array{name: string, url: string, active: bool, label: string}>
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

            $found[$kind] = [
                'name' => (string) $name,
                'url' => $resource::getUrl((string) $name),
                'active' => $this instanceof $page,
                'label' => $this->viewSwitcherLabel($kind),
            ];
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
     * @param  array{name: string, url: string, active: bool, label: string}  $page
     */
    private function viewSwitcherEntryUrl(string $kind, array $page): string
    {
        return match (true) {
            $kind === 'list' => static::getResource()::getUrl($page['name'], ['view' => SavedView::BUILT_IN_LIST_LINK]),
            $page['name'] === 'index' => static::getResource()::getUrl($page['name'], ['view' => $kind]),
            default => $page['url'],
        };
    }

    private function viewSwitcherIcon(string $kind): string
    {
        return match ($kind) {
            'list' => SavedViewType::Table->getIcon(),
            'board' => SavedViewType::Board->getIcon(),
            default => 'lucide-layout-grid',
        };
    }

    private function builtInEntryAction(bool $canApply, string $kind): ?string
    {
        return match (true) {
            ! $canApply => null,
            $kind === 'list' && method_exists($this, 'applyOwnTable') => 'applyOwnTable',
            $kind === 'board' && method_exists($this, 'applyOwnBoard') => 'applyOwnBoard',
            default => null,
        };
    }

    private function viewSwitcherLabel(string $kind): string
    {
        $records = str(static::getResource()::getPluralModelLabel())->lower()->toString();
        $entityKey = "filament/pages/boards.view_switcher.{$this->viewSwitcherEntity()->value}_board";

        return match (true) {
            $kind === 'list' => __('filament/panel.tables.views.standard', ['records' => $records]),
            $kind === 'board' && Lang::has($entityKey) => __($entityKey),
            default => __("filament/pages/boards.view_switcher.{$kind}"),
        };
    }

    /**
     * @param  array<string, array{name: string, url: string, active: bool, label: string}>  $pages
     * @return list<array{key: string, kind: string, layout: string, action: ?string, label: string, icon: string, tone: string, url: string, active: bool, canUpdate: bool, createdLabel: array<array-key, mixed>|string|null}>
     */
    private function viewSwitcherEntries(array $pages): array
    {
        $views = $this->viewSwitcherSavedViews();
        $activeId = property_exists($this, 'activeSavedViewId') ? $this->activeSavedViewId : null;
        $canApply = in_array(ManagesSavedViews::class, class_uses_recursive($this), true);
        $entries = [];

        foreach ($pages as $kind => $page) {
            $entries[$kind] = [
                'key' => $kind,
                'kind' => $kind,
                'layout' => $kind,
                'action' => $this->builtInEntryAction($canApply, $kind),
                'label' => $page['label'],
                'icon' => $this->viewSwitcherIcon($kind),
                'tone' => self::VIEW_TONES[$kind],
                'url' => $this->viewSwitcherEntryUrl($kind, $page),
                'active' => $page['active'] && (! in_array($kind, ['list', 'board'], true) || $activeId === null || ! $views->contains('id', $activeId)),
                'canUpdate' => false,
                'createdLabel' => null,
            ];
        }

        $zone = FilamentTimezone::get();
        $user = $this->viewSwitcherUser();

        foreach ($views as $view) {
            $date = $view->created_at?->setTimezone($zone)->translatedFormat('M j, Y');

            $isBoard = $view->type === SavedViewType::Board;
            $layout = $isBoard ? 'board' : 'list';

            $entries[$view->getKey()] = [
                'key' => (string) $view->getKey(),
                'kind' => 'view',
                'layout' => $layout,
                'action' => $canApply && $pages[$layout]['active'] ? "applySavedView('{$view->getKey()}')" : null,
                'label' => $view->name,
                'icon' => $this->viewSwitcherIcon($layout),
                'tone' => self::VIEW_TONES[$layout],
                'url' => static::getResource()::getUrl($pages[$layout]['name'], ['view' => $view->getKey()]),
                'active' => $view->getKey() === $activeId,
                'canUpdate' => $user->can('update', $view),
                'createdLabel' => __('filament/panel.tables.views.created_on', ['date' => $date]),
            ];
        }

        $ordered = [];

        foreach ($this->viewOrder() as $key) {
            if (isset($entries[$key])) {
                $ordered[] = $entries[$key];
            }
        }

        return $ordered;
    }
}
