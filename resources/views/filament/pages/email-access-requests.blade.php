<x-filament-panels::page class="!pb-0">
    <div class="flex overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">

        {{-- ── Left panel: tabs + request list ───────────────────────── --}}
        <div class="flex w-80 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700">

            {{-- Tabs --}}
            <div class="flex shrink-0 border-b border-gray-200 dark:border-gray-700">
                <button
                    wire:click="setTab('incoming')"
                    type="button"
                    @class([
                        'flex flex-1 items-center justify-center gap-1.5 py-3 text-sm font-medium transition-colors focus:outline-none border-b-2',
                        'border-primary-500 text-primary-600 dark:text-primary-400' => $tab === 'incoming',
                        'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $tab !== 'incoming',
                    ])
                >
                    <x-heroicon-o-inbox class="h-4 w-4" />
                    Incoming
                    @if ($this->pendingIncomingCount > 0)
                        <span class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] rounded-full bg-warning-500 px-1 text-[10px] font-semibold leading-none text-white">
                            {{ $this->pendingIncomingCount > 99 ? '99+' : $this->pendingIncomingCount }}
                        </span>
                    @endif
                </button>
                <button
                    wire:click="setTab('sent')"
                    type="button"
                    @class([
                        'flex flex-1 items-center justify-center gap-1.5 py-3 text-sm font-medium transition-colors focus:outline-none border-b-2',
                        'border-primary-500 text-primary-600 dark:text-primary-400' => $tab === 'sent',
                        'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $tab !== 'sent',
                    ])
                >
                    <x-heroicon-o-paper-airplane class="h-4 w-4" />
                    Sent
                </button>
            </div>

            {{-- Request list --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($this->requests as $request)
                    @php
                        $isSelected = $selectedRequestId === $request->id;
                        $isPending  = $request->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::PENDING;
                        $person     = $tab === 'incoming' ? $request->requester : $request->owner;
                        $subject    = $request->email?->subject ?? '(subject hidden)';
                    @endphp
                    <button
                        wire:click="selectRequest('{{ $request->id }}')"
                        type="button"
                        @class([
                            'w-full text-left px-4 py-3 transition-colors focus:outline-none',
                            'bg-primary-50 dark:bg-primary-900/20' => $isSelected,
                            'hover:bg-gray-50 dark:hover:bg-gray-800/50' => ! $isSelected,
                        ])
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-xs font-semibold text-gray-900 dark:text-gray-100 truncate">
                                {{ $person?->name ?? 'Unknown' }}
                            </span>
                            <span @class([
                                'shrink-0 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium',
                                'bg-warning-100 dark:bg-warning-900/40 text-warning-700 dark:text-warning-300' => $isPending,
                                'bg-success-100 dark:bg-success-900/40 text-success-700 dark:text-success-300' => $request->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::APPROVED,
                                'bg-danger-100 dark:bg-danger-900/40 text-danger-700 dark:text-danger-300' => $request->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::DENIED,
                            ])>
                                {{ $request->status->getLabel() }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400 truncate">{{ $subject }}</p>
                        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                            {{ \Relaticle\EmailIntegration\Enums\EmailPrivacyTier::from($request->tier_requested)->getLabel() }}
                            · {{ $request->created_at->diffForHumans() }}
                        </p>
                    </button>
                @empty
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-key class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                        </div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">No requests</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $tab === 'incoming' ? 'No one has requested access to your emails.' : "You haven't sent any access requests." }}
                        </p>
                    </div>
                @endforelse
            </div>

        </div>

        {{-- ── Right panel: request detail ────────────────────────────── --}}
        <div class="relative flex flex-1 flex-col overflow-y-auto">

            @if ($this->selectedRequest !== null)
                @php
                    $req       = $this->selectedRequest;
                    $email     = $req->email;
                    $authUser  = auth()->user();
                    $isOwner   = $req->owner_id === $authUser->getKey();
                    $isPending = $req->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::PENDING;
                    $tier      = \Relaticle\EmailIntegration\Enums\EmailPrivacyTier::from($req->tier_requested);
                @endphp

                {{-- Request header --}}
                <div class="shrink-0 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                @if ($tab === 'incoming')
                                    <span class="text-gray-500 dark:text-gray-400 font-normal">From</span>
                                    {{ $req->requester?->name ?? 'Unknown' }}
                                @else
                                    <span class="text-gray-500 dark:text-gray-400 font-normal">To</span>
                                    {{ $req->owner?->name ?? 'Unknown' }}
                                @endif
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                Requested {{ $tier->getLabel() }} access · {{ $req->created_at->diffForHumans() }}
                            </p>
                        </div>

                        {{-- Status badge --}}
                        <span @class([
                            'shrink-0 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-warning-100 dark:bg-warning-900/40 text-warning-700 dark:text-warning-300' => $isPending,
                            'bg-success-100 dark:bg-success-900/40 text-success-700 dark:text-success-300' => $req->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::APPROVED,
                            'bg-danger-100 dark:bg-danger-900/40 text-danger-700 dark:text-danger-300' => $req->status === \Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus::DENIED,
                        ])>
                            {{ $req->status->getLabel() }}
                        </span>
                    </div>

                    {{-- Approve / Deny actions (owner + pending only) --}}
                    @if ($isOwner && $isPending)
                        <div class="mt-3 flex items-center gap-2">
                            <button
                                wire:click="mountAction('approveAccessRequest', { requestId: '{{ $req->id }}' })"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-success-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-success-700 transition-colors"
                            >
                                <x-heroicon-o-check class="h-3.5 w-3.5" />
                                Approve
                            </button>
                            <button
                                wire:click="mountAction('denyAccessRequest', { requestId: '{{ $req->id }}' })"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                            >
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                Deny
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Associated email preview --}}
                @if ($email !== null)
                    <div class="flex-1 px-6 py-5 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            Associated Email
                        </h3>

                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-4 space-y-3">

                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ $email->subject ?? '(No subject)' }}
                            </p>

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($email->from->isNotEmpty())
                                    <span class="flex items-center gap-1">
                                        <x-heroicon-o-user class="h-3.5 w-3.5 shrink-0" />
                                        {{ $email->from->first()?->email_address }}
                                    </span>
                                @endif
                                @if ($email->sent_at)
                                    <span class="flex items-center gap-1">
                                        <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" />
                                        {{ $email->sent_at->format('M j, Y · g:i A') }}
                                    </span>
                                @endif
                                <span class="flex items-center gap-1">
                                    <x-heroicon-o-lock-closed class="h-3.5 w-3.5 shrink-0" />
                                    {{ $email->privacy_tier->getLabel() }}
                                </span>
                            </div>

                            @if (filled($email->snippet))
                                <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-4">
                                    {{ $email->snippet }}
                                </p>
                            @endif

                            <div class="pt-1">
                                <a
                                    href="{{ \App\Filament\Pages\EmailInboxPage::getUrl(parameters: ['email' => $email->getKey()], tenant: filament()->getTenant()) }}"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
                                >
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                                    View in inbox
                                </a>
                            </div>

                        </div>
                    </div>
                @else
                    <div class="flex flex-1 items-center justify-center px-8 py-12 text-center">
                        <p class="text-sm text-gray-400 dark:text-gray-500">The associated email is no longer available.</p>
                    </div>
                @endif

            @else
                <div class="flex flex-col items-center justify-center gap-3 px-8 py-16 text-center h-full">
                    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                        <x-heroicon-o-key class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                    </div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Select a request</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">Choose a request from the list to review it</p>
                </div>
            @endif

        </div>

    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
