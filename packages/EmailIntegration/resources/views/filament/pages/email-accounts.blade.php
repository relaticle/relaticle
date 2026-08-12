<x-filament-panels::page>
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />

    <div class="-mt-2">
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
        <div class="space-y-3">
            @foreach ($this->connectedAccounts as $account)
                @php
                    $capabilities = collect([
                        $account->hasEmail() ? __('filament/pages/email-accounts.capabilities.email') : null,
                        $account->hasCalendar() ? __('filament/pages/email-accounts.capabilities.calendar') : null,
                    ])->filter()->join(', ');
                @endphp

                <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
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

                    <div class="flex shrink-0 items-center gap-2">
                        <x-filament::badge
                            :color="$account->status->getColor()"
                            :icon="$account->isActive() ? 'heroicon-m-bolt' : 'heroicon-m-exclamation-triangle'"
                            :title="$account->last_synced_at ? __('filament/pages/email-accounts.synced_at', ['time' => $account->last_synced_at->diffForHumans()]) : null"
                        >
                            {{ $account->isActive() ? __('filament/pages/email-accounts.in_sync') : $account->status->getLabel() }}
                        </x-filament::badge>

                        {{ $this->accountActions($account, [$this->editSettingsAction()]) }}
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
