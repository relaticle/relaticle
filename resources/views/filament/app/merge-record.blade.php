<div
    class="grid gap-4"
    data-merge-dialog
    x-on:keydown.enter.window="
        if ($el.offsetParent === null || $event.target.closest('input, textarea, select, button, a, [contenteditable]')) {
            return
        }

        const submit = $el.closest('.fi-modal-window')?.querySelector('button[type=submit]')

        if (submit && ! submit.disabled && submit.getAttribute('aria-disabled') !== 'true') {
            submit.click()
        }
    "
>
    <p role="note" class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2.5 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
        <x-filament::icon icon="lucide-info" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
        {{ __('filament/record-page.merge.notice') }}
    </p>

    <div class="grid items-stretch gap-3 sm:grid-cols-[minmax(0,2fr)_auto_minmax(0,1fr)] sm:items-center">
        <div class="relative grid gap-9 rounded-2xl sm:gap-5 bg-gray-50 p-3 ring-1 ring-gray-950/5 sm:grid-cols-2 dark:bg-white/5 dark:ring-white/10">
            <div class="min-w-0" data-merge-left>
                @if ($left)
                    @include('filament.app.merge-record.card', ['card' => $left, 'isResult' => false, 'isClearable' => ! $keepsOther])
                @else
                    @include('filament.app.merge-record.picker')
                @endif
            </div>

            <button
                type="button"
                @disabled(! $left)
                x-on:click="$wire.set(@js($statePath.'.keep'), @js($keepsOther ? 'current' : 'other'))"
                aria-label="{{ __('filament/record-page.merge.swap') }}"
                title="{{ __('filament/record-page.merge.swap') }}"
                class="absolute start-1/2 top-1/2 z-10 flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-gray-600 shadow-xs ring-1 ring-gray-950/10 outline-none transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-700"
                data-merge-swap
            >
                <x-filament::icon icon="lucide-arrow-left-right" class="size-menu-icon max-sm:rotate-90" />
            </button>

            <div class="min-w-0" data-merge-right>
                @include('filament.app.merge-record.card', ['card' => $right, 'isResult' => false, 'isClearable' => $keepsOther])
            </div>
        </div>

        <div class="flex items-center justify-center" aria-hidden="true">
            <span class="flex size-7 items-center justify-center rounded-full bg-white text-gray-500 ring-1 ring-gray-950/10 dark:bg-gray-800 dark:text-gray-400 dark:ring-white/15">
                <x-filament::icon icon="lucide-equal" class="size-4" />
            </span>
        </div>

        <div class="min-w-0">
            @include('filament.app.merge-record.card', ['card' => $result, 'isResult' => true, 'isClearable' => false])
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
