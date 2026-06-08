<?php

declare(strict_types=1);

it('surfaces currentPlan, currentPlanLabel, and allowedModels in chat-interface Alpine state', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($contents)
        ->toContain('currentPlan:')
        ->and($contents)->toContain('currentPlanLabel:')
        ->and($contents)->toContain('allowedModels:')
        ->and($contents)->toContain('->allowedModels()')
        ->and($contents)->toContain('\App\Enums\Plan::default()');
});

it('gates selectModel in chat-interface to dispatch chat:model-locked for locked models', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($contents)
        ->toContain('if (! this.allowedModels.includes(value))')
        ->and($contents)->toContain("new CustomEvent('chat:model-locked'")
        ->and($contents)->toContain('detail: { model: value, plan: this.currentPlan, planLabel: this.currentPlanLabel }');
});

it('filters stored model to allowedModels in chat-interface init recovery', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($contents)->toContain('.filter((v) => this.allowedModels.includes(v))');
});

it('surfaces currentPlan, currentPlanLabel, and allowedModels in dashboard Alpine state', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/filament/pages/dashboard.blade.php');

    expect($contents)
        ->toContain('currentPlan:')
        ->and($contents)->toContain('currentPlanLabel:')
        ->and($contents)->toContain('allowedModels:')
        ->and($contents)->toContain('->allowedModels()')
        ->and($contents)->toContain('\App\Enums\Plan::default()');
});

it('gates selectModel in dashboard to dispatch chat:model-locked for locked models', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/filament/pages/dashboard.blade.php');

    expect($contents)
        ->toContain('if (! this.allowedModels.includes(value))')
        ->and($contents)->toContain("new CustomEvent('chat:model-locked'");
});

it('filters default model against allowedModels in dashboard init', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/filament/pages/dashboard.blade.php');

    expect($contents)->toContain('this.allowedModels.includes(candidate) ? candidate : \'auto\'');
});

it('renders Pro pill for locked models in the model picker partial', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/partials/_model-picker.blade.php');

    expect($contents)
        ->toContain('x-show="! allowedModels.includes(opt.value)"')
        ->and($contents)->toContain('Pro')
        ->and($contents)->toContain('aria-disabled');
});

it('does not use HTML disabled on picker buttons so click events still fire', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/partials/_model-picker.blade.php');

    // The button must not carry a hard disabled attribute that blocks clicks.
    // We gate via selectModel() so chat:model-locked fires on locked items.
    expect($contents)
        ->toContain(':disabled="false"')
        ->and($contents)->toContain('aria-disabled');
});

it('dims locked model options with gray text class', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/partials/_model-picker.blade.php');

    expect($contents)->toContain("'text-gray-400 dark:text-gray-500': ! allowedModels.includes(opt.value)");
});
