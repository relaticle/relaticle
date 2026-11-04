@php
    $providerIcons = [
        'anthropic' => ['ri-claude-fill', 'text-anthropic'],
        'openai' => ['ri-openai-fill', 'text-gray-900 dark:text-gray-200'],
    ];
    $fallbackIcon = ['ri-server-line', 'text-gray-500 dark:text-gray-400'];
@endphp

<div class="bg-dots flex h-full min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
    <div class="flex w-full max-w-lg flex-col items-end gap-2" data-ai-models>
        <div class="w-60 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
            <ul aria-label="{{ __('AI model options') }}">
                <li class="flex items-center gap-2 bg-gray-50 px-3 py-1.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    <x-ri-sparkling-2-line class="size-4 shrink-0 text-primary-600 dark:text-primary-400" aria-hidden="true"/>
                    <span class="flex-1 truncate">{{ __('Auto') }}</span>
                    <x-heroicon-s-check-circle class="h-3.5 w-3.5 shrink-0 text-primary-600 dark:text-primary-400" aria-hidden="true"/>
                </li>
                @foreach($models as $model)
                    @php([$modelIcon, $modelIconColor] = $providerIcons[$model['provider']] ?? $fallbackIcon)
                    <li class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200">
                        <x-dynamic-component :component="$modelIcon" class="size-4 shrink-0 {{ $modelIconColor }}" aria-hidden="true"/>
                        <span class="flex-1 truncate">{{ $model['label'] }}</span>
                        @if($billingActive && $model['min_plan'] !== 'free')
                            <span class="ms-auto inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-micro font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                <x-heroicon-m-lock-closed class="h-2.5 w-2.5" aria-hidden="true"/>
                                {{ __('Pro') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="w-full rounded-2xl border border-[var(--surface-input-border)] bg-white dark:bg-gray-900">
            <p class="px-4 pb-3 pt-3.5 text-sm text-gray-500 dark:text-gray-400">{{ __('Ask anything...') }}</p>
            <div class="flex items-center gap-2 px-3 pb-2">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 dark:text-gray-400" aria-hidden="true">
                    <x-heroicon-o-paper-clip class="h-4 w-4"/>
                </span>
                <div class="ms-auto flex items-center gap-1.5">
                    <span class="inline-flex h-7 items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-2 text-xs font-medium text-gray-900 dark:border-gray-700 dark:bg-gray-700 dark:text-white">
                        {{ __('Auto') }}
                        <x-heroicon-o-chevron-up-down class="h-3 w-3" aria-hidden="true"/>
                    </span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500" aria-hidden="true">
                        <x-heroicon-s-arrow-up class="h-4 w-4"/>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
