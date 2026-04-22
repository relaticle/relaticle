<x-filament-panels::page>

    {{-- ── Tab switcher ─────────────────────────────────────────────── --}}
    <x-filament::tabs label="Access request tabs" class="ei-tabs-segmented">
        <x-filament::tabs.item
            :active="$tab === 'incoming'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedInboxArrowDown"
            :badge="$this->pendingIncomingCount > 0 ? ($this->pendingIncomingCount > 99 ? '99+' : (string) $this->pendingIncomingCount) : null"
            badge-color="primary"
            wire:click="setTab('incoming')"
        >
            Incoming
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$tab === 'outgoing'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperAirplane"
            wire:click="setTab('outgoing')"
        >
            Sent
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Status filter pills --}}
    @php
        $counts = $this->statusCounts;
        $filters = [
            ['key' => null,        'label' => 'All',      'count' => $counts['total']],
            ['key' => 'pending',   'label' => 'Pending',  'count' => $counts['pending']],
            ['key' => 'approved',  'label' => 'Approved', 'count' => $counts['approved']],
            ['key' => 'denied',    'label' => 'Denied',   'count' => $counts['denied']],
        ];
    @endphp

    @if ($counts['total'] > 0)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex w-fit items-center rounded-lg bg-gray-100 dark:bg-gray-800 p-1">
                @foreach ($filters as $filter)
                    @php $isActive = $statusFilter === $filter['key']; @endphp
                    <button
                        wire:click="setStatusFilter({{ $filter['key'] === null ? 'null' : "'".$filter['key']."'" }})"
                        type="button"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition',
                            'bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 shadow-sm' => $isActive,
                            'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => ! $isActive,
                        ])
                    >
                        {{ $filter['label'] }}
                        <span @class([
                            'rounded-full px-1.5 text-[10px] font-semibold leading-4',
                            'bg-primary-500 text-white' => $isActive,
                            'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => ! $isActive,
                        ])>
                            {{ $filter['count'] }}
                        </span>
                    </button>
                @endforeach
            </div>

            <div class="relative w-full sm:w-64">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                    <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400 dark:text-gray-500" wire:loading.remove wire:target="search" />
                    <svg wire:loading wire:target="search" class="h-4 w-4 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>
                <input
                    wire:model.live.debounce.300ms="search"
                    type="text"
                    placeholder="Search by name or subject…"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 py-1.5 pl-8 pr-8 text-xs text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 dark:focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                />
                @if (filled($search))
                    <button
                        wire:click="$set('search', '')"
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                    </button>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Request cards ─────────────────────────────────────────────── --}}
    <div
        class="space-y-3 transition-opacity"
        wire:loading.class="opacity-50 pointer-events-none"
        wire:target="setTab,setStatusFilter,search"
    >
    @forelse ($this->requests as $request)
        @php
            $isSelected = $selectedRequestId === $request->id;
            $isPending  = $request->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::PENDING;
            $isApproved = $request->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::APPROVED;
            $isDenied   = $request->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::DENIED;
            $person     = $tab === 'incoming' ? $request->requester : $request->owner;
            $isOwner    = $request->owner_id === auth()->id();
            $email      = $request->email;
            $tier       = \Relaticle\EmailIntegration\Enums\EmailPrivacyTier::from($request->tier_requested);
            $initial    = mb_strtoupper(mb_substr($person?->name ?? '?', 0, 1));
        @endphp

        <div @class([
            'rounded-xl border bg-white dark:bg-gray-900 p-3 shadow-sm transition space-y-2.5',
            'border-primary-400 dark:border-primary-500 ring-2 ring-primary-500/20' => $isSelected,
            'border-gray-200 dark:border-gray-700' => ! $isSelected,
        ])>
            <div class="flex items-start gap-3">

                {{-- Avatar --}}
                <div class="relative shrink-0">
                    @if ($person?->profile_photo_path)
                        <img
                            src="{{ $person->profile_photo_url }}"
                            alt="{{ $person->name }}"
                            class="h-8 w-8 rounded-full object-cover"
                        />
                    @else
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-primary-500 to-primary-700 text-xs font-semibold text-white">
                            {{ $initial }}
                        </div>
                    @endif
                    <span @class([
                        'absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-gray-900',
                        'bg-warning-500' => $isPending,
                        'bg-success-500' => $isApproved,
                        'bg-danger-500' => $isDenied,
                    ])></span>
                </div>

                {{-- Content --}}
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                        <span class="text-xs font-semibold text-gray-900 dark:text-gray-100">
                            {{ $person?->name ?? 'Unknown user' }}
                        </span>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">
                            {{ $tab === 'incoming' ? 'requested access' : 'you requested access' }}
                        </span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="text-[11px] text-gray-400 dark:text-gray-500">
                            {{ $request->created_at->diffForHumans() }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1 rounded bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:text-gray-300">
                            <x-heroicon-m-lock-closed class="h-2.5 w-2.5" />
                            {{ $tier->getLabel() }}
                        </span>
                        <span @class([
                            'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium',
                            'bg-warning-100 dark:bg-warning-900/40 text-warning-700 dark:text-warning-300' => $isPending,
                            'bg-success-100 dark:bg-success-900/40 text-success-700 dark:text-success-300' => $isApproved,
                            'bg-danger-100 dark:bg-danger-900/40 text-danger-700 dark:text-danger-300' => $isDenied,
                        ])>
                            {{ $request->status->getLabel() }}
                        </span>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($email !== null)
                        {{ ($this->openEmailAction)(['emailId' => $email->getKey()]) }}
                    @endif

                    @if ($isOwner && $isPending)
                        {{ ($this->approveAccessRequestAction)(['requestId' => $request->id]) }}
                        {{ ($this->denyAccessRequestAction)(['requestId' => $request->id]) }}
                    @endif
                </div>
            </div>

            {{-- Email reference --}}
            @if ($email !== null)
                <div class="rounded-md border border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/40 px-2.5 py-1.5">
                    <div class="flex items-start gap-1.5">
                        <x-heroicon-o-envelope class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-800 dark:text-gray-200">
                                {{ $email->subject ?? '(No subject)' }}
                            </p>
                            @if (filled($email->snippet))
                                <p class="line-clamp-1 text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $email->snippet }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <p class="text-[11px] italic text-gray-400 dark:text-gray-500">
                    The associated email is no longer available.
                </p>
            @endif
        </div>
    @empty
        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-8 py-16 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                <x-heroicon-o-key class="h-8 w-8 text-gray-400 dark:text-gray-500" />
            </div>
            @if ($statusFilter !== null)
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">No {{ $statusFilter }} requests</p>
                <p class="max-w-sm text-xs text-gray-400 dark:text-gray-500">
                    Try a different filter or clear the active one.
                </p>
                <button
                    wire:click="setStatusFilter(null)"
                    type="button"
                    class="mt-1 inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700 transition-colors"
                >
                    Show all
                </button>
            @else
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                    {{ $tab === 'incoming' ? 'No incoming requests' : 'No sent requests' }}
                </p>
                <p class="max-w-sm text-xs text-gray-400 dark:text-gray-500">
                    {{ $tab === 'incoming'
                        ? 'When someone asks for access to one of your private emails, it will show up here.'
                        : "You haven't asked for access to any emails yet." }}
                </p>
            @endif
        </div>
    @endforelse
    </div>

    <x-filament::pagination
        :paginator="$this->requests"
        class="w-full [&_.fi-pagination-items]:col-start-3"
    />

    <x-filament-actions::modals />
</x-filament-panels::page>
