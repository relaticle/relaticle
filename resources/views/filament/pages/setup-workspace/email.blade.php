@php
    $emphasized = $this->emphasizedProvider();
    $providers = $this->offeredProviders();
    $labels = [
        'gmail' => __('filament/pages/workspaces.setup_workspace.email.google'),
        'azure' => __('filament/pages/workspaces.setup_workspace.email.microsoft'),
    ];
@endphp

<div class="flex flex-1 flex-col">
    <h3 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
        {{ __('filament/pages/workspaces.setup_workspace.email.heading') }}
    </h3>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('filament/pages/workspaces.setup_workspace.email.description') }}
    </p>

    <div class="mt-6 flex flex-col gap-2.5">
        @foreach ($providers as $provider)
            <button
                type="button"
                wire:click="connectMailbox('{{ $provider }}')"
                wire:loading.attr="disabled"
                wire:target="connectMailbox"
                data-provider="{{ $provider }}"
                @if ($emphasized === $provider) data-emphasized="true" @endif
                @class([
                    'flex h-11 w-full items-center justify-center gap-2.5 rounded-lg text-sm font-medium transition',
                    'bg-gray-900 text-white shadow-sm hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-100' => $emphasized === $provider,
                    'bg-white text-gray-950 shadow-xs ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-gray-950 dark:text-gray-100 dark:ring-white/15 dark:hover:bg-white/5' => $emphasized !== $provider,
                ])
            >
                @include('filament.pages.setup-workspace.provider-logo', ['provider' => $provider])
                <span>{{ $labels[$provider] }}</span>
            </button>
        @endforeach
    </div>

    <p class="mt-3.5 flex items-start gap-2 text-xs/5 text-gray-500 dark:text-gray-400">
        <x-filament::icon icon="ri-lock-line" class="mt-0.5 size-4 shrink-0" />
        <span>{{ __('filament/pages/workspaces.setup_workspace.email.privacy') }}</span>
    </p>

    <div class="mt-7">
        @include('filament.pages.setup-workspace.benefits')
    </div>

    <div class="mt-auto pt-6 text-center">
        {{ $this->skipMailboxAction }}
    </div>
</div>
