@props([
    'pendingActionId' => null,
    'operation' => 'create',
    'entityType' => 'company',
    'title' => '',
    'summary' => '',
    'fields' => [],
    'status' => 'pending',
    'resultMessage' => null,
    'recordUrl' => null,
])

@php
    $operationColors = [
        'create' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        'update' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'delete' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    ];
    $operationColor = $operationColors[$operation] ?? $operationColors['create'];
    $isPending = $status === 'pending';
    $isApproved = $status === 'approved';
    $isRestored = $status === 'restored';
    $isRejected = $status === 'rejected';
    $isExpired = $status === 'expired';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 my-2']) }}>
    {{-- Header --}}
    <div class="mb-3 flex items-center gap-2">
        <span class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase {{ $operationColor }}">
            {{ $operation }}
        </span>
        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</span>
    </div>

    {{-- Summary --}}
    @if($summary)
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ $summary }}</p>
    @endif

    {{-- Fields --}}
    @if(!empty($fields))
        <div class="mb-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-700/30">
            <dl class="space-y-1">
                @foreach($fields as $field)
                    <div class="flex items-baseline gap-2">
                        <dt class="w-24 shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ $field['label'] }}
                        </dt>
                        <dd class="text-xs text-gray-700 dark:text-gray-300">
                            {{ $field['value'] ?? $field['new'] ?? '' }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    {{-- Action Buttons / Status --}}
    @if($isPending)
        <div x-data="{ loading: false }" class="flex items-center gap-2">
            <button
                x-on:click="loading = true; $dispatch('chat:approve-action', { id: '{{ $pendingActionId }}' })"
                :disabled="loading"
                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
            >
                Accept
            </button>
            <button
                x-on:click="loading = true; $dispatch('chat:reject-action', { id: '{{ $pendingActionId }}' })"
                :disabled="loading"
                class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-red-700 disabled:opacity-50"
            >
                Reject
            </button>
        </div>
    @elseif($isApproved || $isRestored)
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <x-heroicon-s-check-circle class="h-4 w-4 text-emerald-500" />
                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    {{ $resultMessage ?? ($isRestored ? 'Restored' : 'Approved') }}
                </span>
            </div>
            @if($recordUrl)
                <a
                    href="{{ $recordUrl }}"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    View
                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                </a>
            @endif
        </div>
    @elseif($isRejected)
        <div class="flex items-center gap-2">
            <x-heroicon-s-x-circle class="h-4 w-4 text-red-500" />
            <span class="text-xs font-medium text-red-600 dark:text-red-400">Rejected</span>
        </div>
    @elseif($isExpired)
        <div class="flex items-center gap-2">
            <x-heroicon-s-clock class="h-4 w-4 text-gray-400" />
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Expired</span>
        </div>
    @endif

    {{ $slot }}
</div>
