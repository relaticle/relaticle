@php
    use App\Services\AvatarService;

    $samAvatar = resolve(AvatarService::class)->generateAuto('Sam Patel');
    $mayaAvatar = resolve(AvatarService::class)->generateAuto('Maya Chen');

    $emails = [
        [$samAvatar, __('Re: Renewal quote for 2027'), 'Sam Patel, Lena Ortiz', __('Thanks Lena. The dates work, so I will send the contract on Thursday.'), 'Sam Patel', __('9:24 AM'), false],
        [null, __('Renewal quote for 2027'), 'Lena Ortiz, Sam Patel', __('Hi Sam, thanks for the call. The volume discount works for us.'), 'Sam Patel', __('Yesterday'), false],
        [$mayaAvatar, __('(subject hidden)'), 'Maya Chen, Lena Ortiz', null, 'Maya Chen', __('Yesterday'), true],
        [$mayaAvatar, __('Implementation timeline'), 'Maya Chen, Lena Ortiz', null, 'Maya Chen', __('Mon'), true],
    ];
@endphp

<x-cloud::marketing.window {{ $attributes }}>
    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-white/[0.06]">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10" aria-hidden="true">
            <x-heroicon-o-user class="size-5"/>
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">Lena Ortiz</p>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ __('Head of Operations at Northwind') }}</p>
        </div>
        <span class="hidden items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-700 sm:inline-flex dark:border-white/[0.1] dark:text-gray-300">
            <x-ri-mail-line class="h-3.5 w-3.5"/>
            {{ __('Compose email') }}
        </span>
    </div>

    <div class="flex gap-5 overflow-hidden border-b border-gray-100 px-4 text-xs font-medium text-gray-500 sm:px-5 dark:border-white/[0.06] dark:text-gray-400" aria-hidden="true">
        <span class="py-2.5">{{ __('Tasks') }}</span>
        <span class="py-2.5">{{ __('Notes') }}</span>
        <span class="border-b-2 border-primary py-2.5 text-primary dark:border-primary-400 dark:text-primary-400">{{ __('Emails') }} <span class="ml-0.5 rounded bg-primary-50 px-1 text-micro dark:bg-primary-500/15">4</span></span>
        <span class="py-2.5">{{ __('Meetings') }}</span>
        <span class="hidden py-2.5 sm:inline">{{ __('Activity log') }}</span>
    </div>

    <ul class="divide-y divide-gray-100 dark:divide-white/[0.06]">
        @foreach($emails as [$senderAvatar, $subject, $participants, $preview, $mailbox, $time, $restricted])
            <li class="flex gap-3 px-4 py-3 sm:px-5" data-mail-row>
                @if($senderAvatar)
                    <img src="{{ $senderAvatar }}" alt="" class="size-8 shrink-0 rounded-full">
                @else
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10" aria-hidden="true">
                        <x-heroicon-o-user class="size-4"/>
                    </span>
                @endif
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <p @class(['min-w-0 flex-1 truncate text-sm font-medium', $subject === __('(subject hidden)') ? 'text-gray-500 dark:text-gray-400' : 'text-gray-900 dark:text-white'])>{{ $subject }}</p>
                        <span class="hidden shrink-0 items-center gap-1 text-micro text-gray-400 sm:inline-flex dark:text-gray-500">
                            <x-ri-mail-fill class="h-3 w-3"/>
                            {{ __('via :name', ['name' => $mailbox]) }}
                        </span>
                        <span class="shrink-0 text-micro text-gray-400 dark:text-gray-500">{{ $time }}</span>
                    </div>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $participants }}</p>
                    @if($restricted)
                        <span class="mt-1.5 inline-flex items-center gap-1 rounded-md border border-gray-200 px-1.5 py-0.5 text-micro text-gray-600 dark:border-white/[0.1] dark:text-gray-400">
                            <x-ri-lock-line class="h-3 w-3"/>
                            {{ __('Request access from :name', ['name' => \Illuminate\Support\Str::before($mailbox, ' ')]) }}
                        </span>
                    @else
                        <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">{{ $preview }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</x-cloud::marketing.window>
