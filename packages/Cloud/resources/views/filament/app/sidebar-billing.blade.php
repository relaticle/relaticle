@php
    // A failure gets a solid button; an offer keeps the quieter outlined pill.
    $actionClasses = $billing['urgent']
        ? 'border-transparent bg-danger-600 px-2 py-1 text-white group-hover:bg-danger-500'
        : 'border-gray-200 px-1.5 py-0.5 text-gray-600 group-hover:border-gray-300 group-hover:text-gray-900 dark:border-white/10 dark:text-gray-300 dark:group-hover:text-white';
@endphp

{{-- The whole row is the target, not just a button at its end: the
     line states the deadline and the click acts on it, so there is
     no dead text sitting next to a live control. --}}
<div class="mt-2 border-t border-gray-200/60 pt-2 dark:border-white/10">
    <div class="fi-sidebar-item mx-4">
        @if(! $billing['urgent'])
            <button
                type="button"
                class="fi-sidebar-item-btn group w-full text-start"
                x-on:click="$dispatch('open-modal', { id: @js(\Relaticle\Cloud\Billing\Livewire\UpgradeModal::MODAL_ID) })"
            >
                <x-heroicon-o-arrow-up-circle class="fi-icon fi-size-lg fi-sidebar-item-icon" />

                <span class="fi-sidebar-item-label">{{ $billing['label'] }}</span>

                <span class="flex-shrink-0 rounded-md border text-xs font-medium transition {{ $actionClasses }}">
                    {{ $billing['action'] }}
                </span>
            </button>
        @else
            <a href="{{ resolve(\App\Contracts\Billing::class)->url($workspace) }}" class="fi-sidebar-item-btn group">
                <x-heroicon-o-arrow-up-circle class="fi-icon fi-size-lg fi-sidebar-item-icon" />

                <span class="fi-sidebar-item-label">{{ $billing['label'] }}</span>

                <span class="flex-shrink-0 rounded-md border text-xs font-medium transition {{ $actionClasses }}">
                    {{ $billing['action'] }}
                </span>
            </a>
        @endif
    </div>
</div>
