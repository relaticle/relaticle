@php
    $assistantName = (string) config('chat.assistant_name');

    $steps = [
        ['mailbox', __('Mon 9:12'), __('Adds Lena Ortiz and Northwind after your first email.')],
        ['you', __('Tue 14:30'), __('Moves Northwind renewal to Negotiation on the board.')],
        ['claude', __('Wed 10:05'), __('Creates a follow-up task for Thursday from a chat in Claude.')],
        ['rela', __('Fri 16:48'), __('Marks the opportunity as won after you approve the change.')],
    ];
@endphp

<x-cloud::marketing.frame class="spotlight dark" data-story>
    <x-cloud::marketing.frame-header :label="__('One record')" :lead="__('Four hands, one opportunity.')">
        {{ __('Your inbox, you, your agent and :name all work on the same record.', ['name' => $assistantName]) }}
    </x-cloud::marketing.frame-header>

    <ol class="bg-rail relative grid grid-cols-1 gap-px md:grid-cols-4">
        @foreach($steps as [$actor, $time, $action])
            <li data-story-step class="bg-surface relative p-6 md:p-8">
                <div class="flex items-center justify-between gap-3">
                    <x-cloud::marketing.actor :as="$actor"/>
                    <span class="type-label border-rail rounded-full border px-2 py-0.5 text-gray-400">{{ $time }}</span>
                </div>
                <p class="mt-6 text-sm leading-relaxed text-gray-300">{{ $action }}</p>
                @if($actor === 'rela')
                    <p class="mt-4 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-400">
                        <x-ri-checkbox-circle-fill class="h-4 w-4"/>
                        {{ __('Approved by you') }}
                    </p>
                @endif
            </li>
        @endforeach
    </ol>
</x-cloud::marketing.frame>
