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
    $current = mergeDialogCompany($this->workspace, $this->user, 'Harborline', ['harborline.com']);
    mergeDialogCompany($this->workspace, $this->user, 'Harborline Logistics', ['harborline-logistics.com']);

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
    $current = mergeDialogCompany($this->workspace, $this->user, 'Initech', ['initech.com']);
    mergeDialogCompany($this->workspace, $this->user, 'Initech Ltd', ['initech.co.uk']);

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
    $current = mergeDialogCompany($this->workspace, $this->user, 'Harborline');

    foreach (range(1, 30) as $index) {
        mergeDialogCompany($this->workspace, $this->user, sprintf('Branch %02d', $index));
    }

    $page = openMergeDialog($this->user, $this->workspace, $current, $width, $height);

    $page->script(MERGE_RECORD_FRAMES);

    $page->click('[data-merge-picker]')
        ->assertVisible('.fi-merge-pop')
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
            const popover = document.querySelector('.fi-merge-pop');
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

it('keeps the open picker anchored under its button while the member types', function (int $width, int $height): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Harborline');

    foreach (range(1, 30) as $index) {
        mergeDialogCompany($this->workspace, $this->user, sprintf('Branch %02d', $index));
    }

    foreach (['Beehive Foods', 'Beeline Freight', 'Beechwood Labs'] as $name) {
        mergeDialogCompany($this->workspace, $this->user, $name);
    }

    $page = openMergeDialog($this->user, $this->workspace, $current, $width, $height);

    $page->click('[data-merge-picker]')
        ->assertVisible('.fi-merge-pop')
        ->wait(1.2);

    $opened = $page->script(MERGE_POPOVER_RECT);

    foreach (['bee', '', 'bee', ''] as $term) {
        $typed = searchMergePicker($page, $term);

        expect($typed['rows'])->toBe($term === '' ? $opened['rows'] : 3)
            ->and($typed['above'])->toBe($opened['above'])
            ->and(abs($typed['left'] - $opened['left']))->toBeLessThan(1)
            ->and(abs($typed[$opened['above'] ? 'bottom' : 'top'] - $opened[$opened['above'] ? 'bottom' : 'top']))->toBeLessThan(1)
            ->and($typed['left'])->toBeGreaterThanOrEqual($typed['modalLeft'])
            ->and($typed['right'])->toBeLessThanOrEqual($typed['modalRight'])
            ->and($typed['top'])->toBeGreaterThanOrEqual(0)
            ->and($typed['bottom'])->toBeLessThanOrEqual($typed['windowHeight']);
    }

    $page->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1440, 900],
    'short window' => [1440, 560],
    'phone' => [390, 844],
]);

it('anchors the picker to its button when the button is pressed while the phone sheet is still rising', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Harborline');

    foreach (range(1, 30) as $index) {
        mergeDialogCompany($this->workspace, $this->user, sprintf('Branch %02d', $index));
    }

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies/{$current->getKey()}", 390, 844)
        ->click('.fi-record-rail-actions .fi-dropdown-trigger button')
        ->click('.fi-dropdown-list-item:has-text("Merge record")');

    $page->script(<<<'JS'
        (() => {
            if (window.mergePressStarted) {
                return true;
            }

            window.mergePressStarted = true;

            const press = () => {
                const sheet = document.querySelector('.fi-modal-window.fi-transition-enter');
                const button = document.querySelector('[data-merge-picker]');

                if (! sheet || ! button || button.getBoundingClientRect().width === 0) {
                    return requestAnimationFrame(press);
                }

                window.paintedFrames = [];
                const popover = document.querySelector('.fi-merge-pop');
                const record = () => {
                    const style = getComputedStyle(popover);
                    const box = popover.getBoundingClientRect();

                    if (style.visibility !== 'hidden') {
                        window.paintedFrames.push({ top: box.top, left: box.left });
                    }

                    requestAnimationFrame(record);
                };
                record();
                button.click();
            };
            press();

            return true;
        })()
        JS);

    $page->assertVisible('.fi-merge-pop')->wait(1.2);

    $frames = $page->script('window.paintedFrames');
    $settled = $page->script(MERGE_POPOVER_RECT);
    $gap = $settled['above']
        ? $settled['anchorTop'] - $settled['bottom']
        : $settled['top'] - $settled['anchorBottom'];

    expect($frames)->not->toBeEmpty()
        ->and($gap)->toBeGreaterThanOrEqual(0)
        ->and($gap)->toBeLessThan(16);

    foreach ($frames as $frame) {
        expect(abs($frame['top'] - $settled['top']))->toBeLessThan(1)
            ->and(abs($frame['left'] - $settled['left']))->toBeLessThan(1);
    }

    $page->assertNoJavaScriptErrors();
});
