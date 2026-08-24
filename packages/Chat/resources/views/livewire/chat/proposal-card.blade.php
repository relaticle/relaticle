@php
    $operation = $proposal?->operation->value;
    $stepCount = count($steps);
    $primaryLabel = $isPlan
        ? __('Approve all :count', ['count' => $stepCount])
        : match ($operation) {
            'update' => __('Save changes'),
            'delete' => __('Delete'),
            default => __('Create'),
        };
    $primaryAction = $isPlan ? 'approveAll' : 'createCurrent';
    $discardLabel = $isPlan ? __('Discard all') : __('Discard');
    $discardAction = $isPlan ? 'discardAll' : 'discardCurrent';
@endphp

<div class="flex min-h-0 flex-col">
    @if ($proposal)
        {{-- Surface: the solid data-block tier (crisp hairline card, no shadow)
             shared with the read-result blocks, so a proposal reads as the same
             kind of object as the data around it. --}}
        <div class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            @if ($isPlan)
                {{-- Plan header: the card is one decision over several writes, so it
                     says how many and in what order they run. --}}
                <div class="flex shrink-0 items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400" aria-hidden="true">
                        <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">
                            {{ trans_choice(':count step|:count steps', $stepCount, ['count' => $stepCount]) }}
                        </p>
                        <p class="text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500">
                            {{ __('Approved together, created in order') }}
                        </p>
                    </div>
                </div>
            @endif

            {{-- A plan can be taller than the dock: the steps scroll, so the
                 decision buttons never leave the viewport. --}}
            <div @class([
                'min-h-0 flex-1 overflow-y-auto overscroll-contain',
                'divide-y divide-gray-100 dark:divide-white/5' => $isPlan,
            ])>
                @foreach ($steps as $step)
                    @include('chat::livewire.chat.partials._dock-step', ['step' => $step, 'isPlan' => $isPlan])
                @endforeach
            </div>

            @error('resolve')
                <p class="mx-4 mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-400/10 dark:text-red-400" role="alert">
                    {{ $message }}
                </p>
            @enderror

            {{-- Footer: the decision. Separated by a hairline so it reads as one deliberate step. --}}
            <div class="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 px-4 py-2.5 dark:border-white/5">
                <button
                    type="button"
                    wire:click="{{ $discardAction }}"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @if ($editingFieldCode !== null) title="{{ __('Finish editing the field first') }}" @endif
                    @class([
                        'inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white',
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    {{ $discardLabel }}
                </button>

                <button
                    type="button"
                    wire:click="{{ $primaryAction }}"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @if ($editingFieldCode !== null) title="{{ __('Finish editing the field first') }}" @endif
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 motion-safe:active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60',
                        'bg-red-600 hover:bg-red-500 focus-visible:outline-red-500' => $operation === 'delete' && ! $isPlan,
                        'bg-primary-600 hover:bg-primary-500 focus-visible:outline-primary-500' => $operation !== 'delete' || $isPlan,
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 motion-safe:animate-spin" wire:loading wire:target="{{ $primaryAction }}" aria-hidden="true" />
                    <x-heroicon-o-check class="h-3.5 w-3.5" wire:loading.remove wire:target="{{ $primaryAction }}" aria-hidden="true" />
                    <span>{{ $primaryLabel }}</span>
                    <kbd
                        x-data
                        x-text="/Mac|iP/.test(navigator.platform) ? '⌘⏎' : 'Ctrl+⏎'"
                        class="hidden rounded bg-white/20 px-1.5 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline"
                    ></kbd>
                </button>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
