<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Resources\NoteResource\Pages\NotesCards;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\Flowforge\BoardResourcePage;

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

        if (! $this instanceof BoardResourcePage || ! $header instanceof View) {
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
        $resource = static::getResource();
        $views = [];

        foreach ($resource::getPages() as $name => $registration) {
            $page = $registration->getPage();

            $isCollectionPage = is_a($page, ListRecords::class, true) || is_a($page, BoardResourcePage::class, true);

            if (! $isCollectionPage || ! $page::canAccess()) {
                continue;
            }

            $view = match (true) {
                is_a($page, BoardResourcePage::class, true) => 'board',
                $page === NotesCards::class => 'cards',
                default => 'list',
            };

            $views[$view] = [
                'url' => $resource::getUrl($name),
                'active' => $this instanceof $page,
            ];
        }

        if (count($views) < 2) {
            return null;
        }

        return new HtmlString(view('filament.app.view-switcher', ['views' => $views])->render());
    }
}
