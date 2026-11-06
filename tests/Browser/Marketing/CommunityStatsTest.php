<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withVite();

    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 4242]),
        '*' => Http::response(status: 500),
    ]);
});

it('stretches the stats that loaded across the whole row', function (string $path): void {
    $page = $this->visit($path)->resize(1440, 900);

    $widths = $page->script(<<<'JS'
        (() => {
            const label = [...document.querySelectorAll('main *')].find((element) => element.children.length === 0 && element.textContent.trim() === 'GitHub stars');
            const row = label.closest('.grid');
            const cells = [...row.children];

            return { row: Math.round(row.getBoundingClientRect().width), cells: cells.map((cell) => Math.round(cell.getBoundingClientRect().width)) };
        })()
    JS);

    expect($widths['cells'])->toHaveCount(1)
        ->and($widths['cells'][0])->toBe($widths['row']);
})->with([
    'homepage' => ['/'],
    'self-hosted' => ['/self-hosted'],
]);
