@props([
    'account',
])

@php
    use Relaticle\EmailIntegration\Data\MailboxHistoryImportSummary;

    $summary = $account->mailboxHistoryImportSummary();
    $visible = $account->showsMailboxHistoryImportFailureSummary() && $summary instanceof MailboxHistoryImportSummary;
    $dismissToken = $account->mailboxHistoryImportFailureDismissToken() ?? '';
@endphp

@if ($visible)
    <div
        x-data="{
            storageKey: 'relaticle.history-import-failure-dismiss',
            accountId: @js((string) $account->getKey()),
            dismissToken: @js($dismissToken),
            hidden: false,
            init() {
                try {
                    const stored = JSON.parse(sessionStorage.getItem(this.storageKey) || '{}')
                    this.hidden = stored[this.accountId] === this.dismissToken
                } catch (e) {
                    this.hidden = false
                }
            },
            dismiss() {
                this.hidden = true
                try {
                    const stored = JSON.parse(sessionStorage.getItem(this.storageKey) || '{}')
                    stored[this.accountId] = this.dismissToken
                    sessionStorage.setItem(this.storageKey, JSON.stringify(stored))
                } catch (e) {}
            },
        }"
        x-show="! hidden"
        x-cloak
        role="alert"
        {{ $attributes->class('flex items-center justify-between gap-2 rounded-lg bg-warning-50 px-2.5 py-1.5 dark:bg-warning-400/10') }}
    >
        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <x-filament::badge
                color="warning"
                size="sm"
                icon="heroicon-m-exclamation-triangle"
                class="shrink-0 whitespace-nowrap"
                :tooltip="__('filament/pages/email-accounts.history_import.failed_jobs', ['count' => number_format($summary->failedJobs)])"
            >
                {{ __('filament/pages/email-accounts.history_import_failure.badge') }}
            </x-filament::badge>

            {{ $slot }}
        </div>

        <button
            type="button"
            class="shrink-0 rounded p-0.5 text-warning-600/70 hover:text-warning-800 dark:text-warning-400/80 dark:hover:text-warning-200"
            x-on:click="dismiss()"
        >
            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
            <span class="sr-only">{{ __('filament/pages/email-accounts.history_import_failure.dismiss') }}</span>
        </button>
    </div>
@endif
