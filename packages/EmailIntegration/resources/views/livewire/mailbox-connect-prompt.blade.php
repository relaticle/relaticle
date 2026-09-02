<div>
    @if ($this->isVisible())
        <div class="mt-14" data-mailbox-connect="home">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('filament/pages/dashboard.mailbox.heading') }}
                </h2>
                <a
                    href="{{ $this->inboxUrl() }}"
                    class="rounded-sm text-xs text-gray-500 transition hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:text-white"
                >
                    {{ __('filament/pages/dashboard.mailbox.view_all') }}
                </a>
            </div>

            <div class="rounded-xl border border-dashed border-[var(--surface-block-border)] px-6 py-10 text-center">
                <p class="text-sm font-medium text-gray-900 dark:text-white">
                    {{ __('filament/pages/dashboard.mailbox.empty.title') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament/pages/dashboard.mailbox.empty.description') }}
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-3">
                    {{ $this->connectGmailAction }}

                    @if ($this->connectAzureAction->isVisible())
                        {{ $this->connectAzureAction }}
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
