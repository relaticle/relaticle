@php
    use Relaticle\EmailIntegration\Enums\ContactCreationMode;

    $statePath = $getStatePath();

    $options = array_map(fn (ContactCreationMode $mode): array => [
        'value' => $mode->value,
        'icon' => $mode->getIcon(),
        'label' => $mode->getLabel(),
        'description' => $mode->getDescription(),
        'recommended' => $mode->isRecommended(),
    ], ContactCreationMode::cases());
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="space-y-2" role="radiogroup" aria-label="{{ $ariaLabel }}">
        @foreach ($options as $option)
            <label class="ei-sharing-card">
                <span class="ei-sharing-card-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition dark:bg-white/10 dark:text-gray-400">
                    <x-filament::icon :icon="$option['icon']" class="h-5 w-5" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="ei-sharing-card-title flex flex-wrap items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $option['label'] }}
                        @if ($option['recommended'])
                            <x-filament::badge color="success" size="sm">
                                {{ __('filament/pages/email-privacy-settings.record_creation.recommended') }}
                            </x-filament::badge>
                        @endif
                    </span>
                    <span class="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">
                        {{ $option['description'] }}
                    </span>
                </span>

                <input
                    type="radio"
                    value="{{ $option['value'] }}"
                    wire:model.live="{{ $statePath }}"
                    class="fi-radio-input mt-1 shrink-0"
                />
            </label>
        @endforeach
    </div>
</x-dynamic-component>
