<?php

declare(strict_types=1);

use App\Filament\Actions\MergeRecordAction;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(MergeRecordAction::class);

beforeEach(function (): void {
    $this->withVite();
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('lays the sources and the result in one row on a laptop and a desktop', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Harborline', ['harborline.com']);
    dialogCompany($this->workspace, $this->user, 'Harborline Logistics', ['harborline-logistics.com']);

    foreach ([[1440, 900], [1280, 800]] as [$width, $height]) {
        $page = openMergeDialog($this->user, $this->workspace, $current, $width, $height);

        expect($page->script(MERGE_LAYOUT))->toMatchArray([
            'sameRow' => true,
            'sameHeight' => true,
            'sameWidth' => true,
            'resultAfterSources' => true,
            'swapBetweenSlots' => true,
            'swapClear' => true,
            'sourcesBordered' => true,
            'overflows' => false,
        ]);

        pickInMergeDialog($page, 'Logistics', 'Harborline Logistics');

        expect($page->script(MERGE_LAYOUT))->toMatchArray([
            'sameRow' => true,
            'sameHeight' => true,
            'sameWidth' => true,
            'resultAfterSources' => true,
            'swapBetweenSlots' => true,
            'swapClear' => true,
            'sourcesBordered' => true,
            'overflows' => false,
        ]);
    }
});

it('stacks the cards on a phone with the swap button between the two sources', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Initech', ['initech.com']);
    dialogCompany($this->workspace, $this->user, 'Initech Ltd', ['initech.co.uk']);

    $page = openMergeDialog($this->user, $this->workspace, $current, 390, 844);

    pickInMergeDialog($page, 'Ltd', 'Initech Ltd');

    expect($page->script(MERGE_LAYOUT))->toMatchArray([
        'sameWidth' => true,
        'swapStacked' => true,
        'swapClear' => true,
        'sourcesBordered' => true,
        'resultBelow' => true,
        'overflows' => false,
    ]);
});

it('caps the open search list inside the window and scrolls it to the last row', function (int $width, int $height): void {
    $current = dialogCompany($this->workspace, $this->user, 'Harborline');

    foreach (range(1, 30) as $index) {
        dialogCompany($this->workspace, $this->user, sprintf('Branch %02d', $index));
    }

    $page = openMergeDialog($this->user, $this->workspace, $current, $width, $height);

    $page->script(<<<'JS'
        (() => {
            const popover = document.querySelector('[data-merge-popover]');
            window.paintedFrames = [];
            const record = () => {
                const style = getComputedStyle(popover);
                const box = popover.getBoundingClientRect();

                if (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') {
                    window.paintedFrames.push({ top: box.top, left: box.left, width: box.width, height: box.height });
                }

                requestAnimationFrame(record);
            };
            record();
        })()
        JS);

    $page->click('[data-merge-picker]')
        ->assertVisible('[data-merge-popover]')
        ->wait(1.2);

    $frames = $page->script('window.paintedFrames');
    $settled = end($frames);

    expect($frames)->not->toBeEmpty();
    foreach ($frames as $frame) {
        expect($frame['width'])->toBeGreaterThan(0)
            ->and($frame['height'])->toBeGreaterThan(0)
            ->and(abs($frame['top'] - $settled['top']))->toBeLessThan(1)
            ->and(abs($frame['left'] - $settled['left']))->toBeLessThan(1)
            ->and(abs($frame['height'] - $settled['height']))->toBeLessThan(1);
    }

    $measure = <<<'JS'
        (() => {
            const popover = document.querySelector('[data-merge-popover]');
            const list = popover.querySelector('ul');
            const search = popover.querySelector('input').getBoundingClientRect();
            const box = popover.getBoundingClientRect();
            list.scrollTop = list.scrollHeight;
            const last = list.querySelector('li:last-child').getBoundingClientRect();
            const bounds = list.getBoundingClientRect();

            return {
                height: box.height <= 448,
                inWindow: box.top >= 0 && box.bottom <= window.innerHeight - 16,
                listScrolls: list.scrollHeight > list.clientHeight,
                searchFixed: search.top >= box.top && search.bottom <= bounds.top + 1,
                lastReachable: last.bottom <= bounds.bottom + 1 && last.top >= bounds.top - 1,
                pageOverflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        })()
        JS;

    expect($page->script($measure))->toBe([
        'height' => true,
        'inWindow' => true,
        'listScrolls' => true,
        'searchFixed' => true,
        'lastReachable' => true,
        'pageOverflows' => false,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1440, 700],
    'short window' => [1440, 560],
    'phone' => [390, 844],
]);
