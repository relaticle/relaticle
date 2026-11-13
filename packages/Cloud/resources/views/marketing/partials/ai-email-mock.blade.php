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
                <div class="flex items-start gap-2.5 px-4 pb-2.5 pt-3">
                    <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                        <x-lucide-mail class="h-3.5 w-3.5"/>
                    </span>
                    <p class="min-w-0 flex-1 truncate text-sm leading-6">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('Send email') }}</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $subject }}</span>
                    </p>
                    <span class="mt-1 inline-flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                        <x-lucide-lock class="h-3 w-3" aria-hidden="true"/>
                        {{ __('Own approval') }}
                    </span>
                </div>

                <dl class="pb-2 pe-3 ps-[3.125rem]">
                    @foreach($rows as [$label, $value])
                        <div class="flex items-start gap-3 py-1.5 text-sm">
                            <dt class="w-20 shrink-0 text-xs leading-5 text-gray-500 sm:w-24 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="min-w-0 flex-1 whitespace-pre-line leading-5 text-gray-900 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                    <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Don\'t send') }}</span>
                    <x-cloud::marketing.rela-approve tabindex="-1" :label="__('Send email')" :shortcut="false"/>
                </div>
            </div>
        </div>
    </x-cloud::marketing.chat-window>
</div>
