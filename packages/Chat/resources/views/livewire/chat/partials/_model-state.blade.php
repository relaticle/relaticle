{{-- Shared model-picker state, spread inside an Alpine x-data object literal.
     Used by chat-interface.blade.php and the dashboard composer so the plan
     gates, picker options, and provider icons cannot drift between surfaces.
     $persistSelection: whether picking a model writes chat:model to
     localStorage (the full chat persists, the dashboard does not). --}}
@php
    $modelStateWorkspace = auth()->user()?->currentWorkspace;
    $modelStateIsMetered = $modelStateWorkspace === null || resolve(\App\Contracts\Entitlements::class)->metersCredits($modelStateWorkspace);
@endphp
currentPlan: @js($modelStateWorkspace?->plan?->value ?? \App\Enums\Plan::default()->value),
currentPlanLabel: @js($modelStateIsMetered ? ($modelStateWorkspace?->plan?->getLabel() ?? \App\Enums\Plan::default()->getLabel()) : null),
{{-- Null when billing is off, no tenant is bound, or the viewer cannot reach
     Billing: the locked-model hint then renders without a link. --}}
upgradeUrl: @js(
    (auth()->user()?->currentWorkspace !== null
        && auth()->user()->hasWorkspaceCapability(auth()->user()->currentWorkspace->getKey(), \App\Enums\WorkspaceCapability::BillingManage))
        ? resolve(\App\Contracts\Billing::class)->url(auth()->user()->currentWorkspace)
        : null
),
allowedModels: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->allowedIdsFor($modelStateWorkspace)),
trialLocked: @js(app(\Relaticle\Chat\Services\ModelAccess::class)->isTrialLocked($modelStateWorkspace)),
modelOptions: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->pickerOptions()),
...window.ChatModules.modelPickerModule({
    persistSelection: @js($persistSelection ?? false),
    {{-- Each icon carries its own size: WebKit collapses an SVG that has only a viewBox inside the picker's flex slot. --}}
    providerIcons: @js([
        'auto' => svg('ri-sparkling-2-line', 'size-full')->toHtml(),
        'anthropic' => svg('ri-claude-fill', 'size-full')->toHtml(),
        'openai' => svg('ri-openai-fill', 'size-full')->toHtml(),
        'ollama' => svg('ri-server-line', 'size-full')->toHtml(),
        'selfhosted' => svg('ri-server-line', 'size-full')->toHtml(),
    ]),
}),
