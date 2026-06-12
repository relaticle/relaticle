<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Relaticle\Flowforge\BoardResourcePage;

/**
 * Appends the list/board view switcher directly after the page heading,
 * so it keeps a stable position when toggling between the two layouts.
 */
trait HasBoardViewSwitcher
{
    public function getHeading(): string|Htmlable|null
    {
        $heading = parent::getHeading();

        $resource = static::getResource();
        $pages = $resource::getPages();

        if (! isset($pages['board'])) {
            return $heading;
        }

        /** @var class-string<BoardResourcePage> $boardPage */
        $boardPage = $pages['board']->getPage();

        if (! $boardPage::canAccess()) {
            return $heading;
        }

        $switcher = view('filament.app.view-switcher', [
            'active' => $this instanceof BoardResourcePage ? 'board' : 'list',
            'listUrl' => $resource::getUrl('index'),
            'boardUrl' => $resource::getUrl('board'),
        ])->render();

        return new HtmlString('<span>'.e($heading).'</span>'.$switcher);
    }
}
