<div class="grid gap-4" data-merge-dialog>
    <p role="note" class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
        <x-filament::icon icon="lucide-info" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
        {{ __('filament/record-page.merge.notice') }}
    </p>

    <div class="grid items-stretch gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)]">
        <div class="min-w-0" data-merge-left>
            @if ($left)
                @include('filament.app.merge-record.card', ['card' => $left, 'isKept' => false, 'isResult' => false, 'isClearable' => ! $keepsOther])
            @else
                @include('filament.app.merge-record.picker')
            @endif
        </div>

        <div class="flex items-center justify-center">
            <button
                type="button"
                @disabled(! $left)
                x-on:click="$wire.set(@js($statePath.'.keep'), @js($keepsOther ? 'current' : 'other'))"
                aria-label="{{ __('filament/record-page.merge.swap') }}"
                title="{{ __('filament/record-page.merge.swap') }}"
                class="flex size-9 items-center justify-center rounded-full bg-white text-gray-600 shadow-xs ring-1 ring-gray-950/10 outline-none transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-700"
                data-merge-swap
            >
                <x-filament::icon icon="lucide-arrow-left-right" class="size-menu-icon max-sm:rotate-90" />
            </button>
        </div>

        <div class="min-w-0" data-merge-right>
            @include('filament.app.merge-record.card', ['card' => $right, 'isKept' => true, 'isResult' => false, 'isClearable' => $keepsOther])
        </div>

        <div class="flex items-center justify-center text-gray-400 dark:text-gray-500" aria-hidden="true">
            <x-filament::icon icon="lucide-equal" class="size-5" />
        </div>

        <div class="min-w-0">
            @if ($result)
                @include('filament.app.merge-record.card', ['card' => $result, 'isKept' => false, 'isResult' => true, 'isClearable' => false])
            @else
                <div class="flex h-full min-h-40 flex-col items-center justify-center gap-2 rounded-xl bg-primary-50/50 p-4 text-sm text-primary-700/70 ring-1 ring-primary-600/10 dark:bg-primary-400/5 dark:text-primary-300/70 dark:ring-primary-400/15" data-merge-result-empty>
                    {{ __('filament/record-page.merge.result') }}
                </div>
            @endif
        </div>
    </div>

    @error($statePath.'.duplicate_id')
        <p role="alert" class="flex items-start gap-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30" data-merge-error>
            <x-filament::icon icon="lucide-circle-alert" class="mt-0.5 size-4 shrink-0" />
            {{ $message }}
        </p>
    @enderror

    @foreach ($overLimit as $message)
        <p role="alert" class="flex items-start gap-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30" data-merge-over-limit>
            <x-filament::icon icon="lucide-circle-alert" class="mt-0.5 size-4 shrink-0" />
            {{ $message }}
        </p>
    @endforeach
</div>
