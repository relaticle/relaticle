<x-filament-panels::page>
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            {{ __('filament/pages/email-accounts.title') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ __('filament/pages/email-accounts.subheading') }}
        </p>
    </div>

    <x-filament::section
        :heading="__('filament/pages/email-accounts.sections.connected.heading')"
        :description="new \Illuminate\Support\HtmlString(__('filament/pages/email-accounts.sections.connected.description', ['url' => route('policy.show')]))"
    >
        <div
            class="space-y-3"
            @if ($this->connectedAccounts->contains(fn ($account): bool => $account->isImportingHistory()))
                wire:poll.5s="refreshAccounts"
            @endif
        >
            @foreach ($this->connectedAccounts as $account)
                @php
                    $capabilities = collect([
                        $account->hasEmail() ? __('filament/pages/email-accounts.capabilities.email') : null,
                        $account->hasCalendar() ? __('filament/pages/email-accounts.capabilities.calendar') : null,
                    ])->filter()->join(', ');
                @endphp

                <div wire:key="email-account-{{ $account->getKey() }}" class="flex flex-col gap-3 rounded-lg border border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <x-filament::icon :icon="$account->provider->getIcon()" class="h-5 w-5 shrink-0 text-gray-400" />

                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $account->email_address }}</p>
                                @if ($account->is_default)
                                    <x-filament::badge color="info" class="shrink-0">
                                        {{ __('filament/pages/email-accounts.default_badge') }}
                                    </x-filament::badge>
                                @endif
                            </div>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $capabilities ?: $account->provider->getLabel() }}
                            </p>
                        </div>
                    </div>

                    <div @class([
                        'flex items-center gap-3',
                        'w-full sm:w-auto' => $account->isImportingHistory(),
                        'shrink-0' => ! $account->isImportingHistory(),
                    ])>
                        @if ($account->isImportingHistory())
                            @php
                                $imported = $account->initial_sync_imported;
                                $estimated = $account->initial_sync_estimated;
                                $percent = $account->initialSyncProgressPercent();
                            @endphp

                            <div class="min-w-0 flex-1 sm:w-52 sm:flex-none">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                                        {{ __('filament/pages/email-accounts.importing') }}
                                    </p>

                                    @if ($percent !== null)
                                        <p class="text-xs font-semibold tabular-nums text-gray-950 dark:text-white">
                                            {{ __('filament/pages/email-accounts.importing_percent', ['percent' => $percent]) }}
                                        </p>
                                    @endif
                                </div>

                                @if ($percent !== null)
                                    <div
                                        class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/15"
                                        role="progressbar"
                                        aria-busy="true"
                                        aria-valuemin="0"
                                        aria-valuemax="{{ $estimated }}"
                                        aria-valuenow="{{ min($imported, $estimated) }}"
                                        aria-valuetext="{{ __('filament/pages/email-accounts.importing_percent', ['percent' => $percent]) }}"
                                        aria-label="{{ __('filament/pages/email-accounts.importing') }}"
                                    >
                                        <div
                                            class="h-full w-full origin-left rounded-full bg-primary-600 motion-reduce:transition-none motion-safe:transition-transform motion-safe:duration-200 motion-safe:ease-out dark:bg-primary-400"
                                            style="transform: scaleX({{ $percent / 100 }})"
                                        ></div>
                                    </div>
                                @else
                                    <div
                                        class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/15"
                                        role="progressbar"
                                        aria-busy="true"
                                        aria-label="{{ __('filament/pages/email-accounts.importing') }}"
                                    >
                                        <div class="ei-import-indeterminate h-full rounded-full bg-primary-600 dark:bg-primary-400"></div>
                                    </div>
                                @endif
                            </div>
                        @else
                            <x-filament::badge
                                :color="$account->status->getColor()"
                                :icon="$account->isActive() ? 'heroicon-m-bolt' : 'heroicon-m-exclamation-triangle'"
                                :title="$account->last_synced_at ? __('filament/pages/email-accounts.synced_at', ['time' => $account->last_synced_at->diffForHumans()]) : null"
                            >
                                {{ $account->isActive() ? __('filament/pages/email-accounts.in_sync') : $account->status->getLabel() }}
                            </x-filament::badge>
                        @endif

                        {{ $this->accountActions($account->getKey(), $account->status, [$this->editSettingsAction()]) }}
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 p-4 dark:border-white/20">
                {{ $this->connectGmailAction }}

                @if ($this->connectAzureAction->isVisible())
                    {{ $this->connectAzureAction }}
                @endif
            </div>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
