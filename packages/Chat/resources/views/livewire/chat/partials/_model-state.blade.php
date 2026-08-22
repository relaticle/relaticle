{{-- Shared model-picker state, spread inside an Alpine x-data object literal.
     Used by chat-interface.blade.php and the dashboard composer so the plan
     gates, picker options, and provider icons cannot drift between surfaces.
     $persistSelection: whether picking a model writes chat:model to
     localStorage (the full chat persists, the dashboard does not). --}}
currentPlan: @js(auth()->user()?->currentTeam?->plan?->value ?? \App\Enums\Plan::default()->value),
currentPlanLabel: @js(auth()->user()?->currentTeam?->plan?->label() ?? \App\Enums\Plan::default()->label()),
{{-- Null when billing is off or no tenant is bound (tenant-less pages/tests):
     the locked-model hint then renders without a link. --}}
upgradeUrl: @js(
    (\Laravel\Pennant\Feature::active(\App\Features\Billing::class) && auth()->user()?->currentTeam !== null)
        ? \App\Filament\Pages\Billing::getUrl(panel: 'app', tenant: auth()->user()->currentTeam)
        : null
),
allowedModels: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->allowedIdsFor(auth()->user()?->currentTeam?->plan ?? \App\Enums\Plan::default())),
modelOptions: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->pickerOptions()),
...window.ChatModules.modelPickerModule({
    persistSelection: @js($persistSelection ?? false),
    providerIcons: @js([
        'anthropic' => svg('ri-claude-fill')->toHtml(),
        'openai' => svg('ri-openai-fill')->toHtml(),
        'ollama' => svg('ri-server-line')->toHtml(),
        'selfhosted' => svg('ri-server-line')->toHtml(),
    ]),
}),
