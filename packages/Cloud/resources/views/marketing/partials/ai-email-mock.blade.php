@php
    $subject = __('Kovra renewal: next steps');
    $rows = [
        [__('Subject'), $subject],
        [__('From'), 'lena@northwind.io'],
        [__('To'), 'sarah.chen@kovra.io'],
        [__('Message'), __("Hi Sarah,\n\nThanks for the call today. Could you confirm the seat count for the renewal by Friday? I will send the final terms as soon as I have it.")],
        [__('Signature'), __('Lena Ortiz, Northwind')],
    ];
@endphp

<div class="bg-dots flex h-full min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
    <x-cloud::marketing.chat-window class="w-full max-w-lg">
        <div class="space-y-3 p-4 sm:p-5">
            <p class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                {{ __('Email Sarah the renewal next steps and ask her to confirm seats by Friday.') }}
            </p>

            <p class="px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">{{ __('Here is the email. It goes out only when you press Send.') }}</p>

            <div class="overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10" data-ai-email>
                <div class="px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Send Email') }}</div>
                <p class="truncate px-4 pb-2.5 pt-1.5 text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ __('Send email ":subject"', ['subject' => $subject]) }}</p>

                <dl class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                    @foreach($rows as [$label, $value])
                        <div class="flex items-start gap-3 px-4 py-2 text-sm">
                            <dt class="w-20 shrink-0 leading-5 text-gray-700 sm:w-24 dark:text-gray-300">{{ $label }}</dt>
                            <dd class="min-w-0 flex-1 whitespace-pre-line leading-5 text-gray-900 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                    <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discard') }}</span>
                    <span class="inline-flex h-7 items-center rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm">{{ __('Send') }}</span>
                </div>
            </div>
        </div>
    </x-cloud::marketing.chat-window>
</div>
