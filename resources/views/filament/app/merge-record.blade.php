@php
    $left = $getLeftCard();
    $keepsPicked = $keepsPicked();
@endphp

<div
    class="@container grid gap-4"
    data-merge-dialog
    x-on:keydown.enter.window="
        const openModals = document.querySelectorAll('.fi-modal-open')

        if ($el.offsetParent === null || openModals[openModals.length - 1] !== $el.closest('.fi-modal') || $event.target.closest('input, textarea, select, button, a, [contenteditable]')) {
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

    <div class="grid items-stretch @3xl:grid-cols-[minmax(0,calc((100%-8rem)*2/3+5rem))_3rem_minmax(0,1fr)]">
        <div class="relative grid gap-12 rounded-2xl bg-gray-50 p-3 ring-1 ring-gray-950/5 @3xl:grid-cols-2 @3xl:gap-14 dark:bg-white/5 dark:ring-white/10">
            <div class="min-w-0" data-merge-left>
                @if ($left)
                    @include('filament.app.merge-record.card', ['role' => 'left', 'card' => $left, 'isClearable' => ! $keepsPicked])
                @else
                    @include('filament.app.merge-record.picker')
                @endif
            </div>

            <div class="absolute start-1/2 top-1/2 flex h-12 -translate-x-1/2 -translate-y-1/2 flex-col items-center @3xl:h-auto @3xl:w-14 @3xl:flex-row">
                <span aria-hidden="true" class="w-px flex-1 bg-gray-950/10 @3xl:h-px @3xl:w-auto dark:bg-white/15"></span>

                <button
                    type="button"
                    @disabled(! $left)
                    x-on:click="$set('keep_picked', @js(! $keepsPicked), false, true)"
                    aria-label="{{ __('filament/record-page.merge.swap') }}"
                    title="{{ __('filament/record-page.merge.swap') }}"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-gray-600 shadow-xs ring-1 ring-gray-950/10 outline-none transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-700"
                    data-merge-swap
                >
                    <x-filament::icon icon="lucide-arrow-left-right" class="size-menu-icon rotate-90 @3xl:rotate-0" />
                </button>

                <span aria-hidden="true" class="w-px flex-1 bg-gray-950/10 @3xl:h-px @3xl:w-auto dark:bg-white/15"></span>
            </div>

            <div class="min-w-0" data-merge-right>
                @include('filament.app.merge-record.card', ['role' => 'right', 'card' => $getRightCard(), 'isClearable' => $keepsPicked])
            </div>
        </div>

        <div class="flex h-12 flex-col items-center @3xl:h-auto @3xl:flex-row" aria-hidden="true">
            <span class="w-px flex-1 bg-gray-950/10 @3xl:h-px @3xl:w-auto dark:bg-white/15"></span>

            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-white text-gray-500 ring-1 ring-gray-950/10 dark:bg-gray-800 dark:text-gray-400 dark:ring-white/15">
                <x-filament::icon icon="lucide-equal" class="size-4" />
            </span>

            <span class="w-px flex-1 bg-gray-950/10 @3xl:h-px @3xl:w-auto dark:bg-white/15"></span>
        </div>

        <div class="min-w-0 px-3 @3xl:px-0 @3xl:py-3">
            @include('filament.app.merge-record.card', ['role' => 'result', 'card' => $getResultCard(), 'isClearable' => false, 'isResult' => true])
        </div>
    </div>

    @if ($isUnavailable())
        @include('filament.app.merge-record.alert', ['marker' => 'data-merge-error', 'message' => __('filament/record-page.merge.unavailable')])
    @endif

    @error($getStatePath())
        @include('filament.app.merge-record.alert', ['marker' => 'data-merge-error', 'message' => $message])
    @enderror

    @foreach ($getOverLimitMessages() as $overLimitMessage)
        @include('filament.app.merge-record.alert', ['marker' => 'data-merge-over-limit', 'message' => $overLimitMessage])
    @endforeach
</div>
