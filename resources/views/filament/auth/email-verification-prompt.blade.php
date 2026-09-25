<div class="flex flex-col items-center text-center">
    <div class="flex size-12 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-500/10">
        <x-filament::icon icon="ri-mail-send-line" class="size-6 text-primary-600 dark:text-primary-400" />
    </div>

    <h1 class="mt-5 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
        {{ __('auth.verify_email.heading') }}
    </h1>

    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
        {{ __('auth.verify_email.description') }}
    </p>

    <p class="mt-1 w-full text-sm font-medium break-words text-gray-950 dark:text-white">
        {{ $email }}
    </p>

    <div
        class="mt-8 w-full"
        x-data="{
            seconds: 0,
            timer: null,
            start(from) {
                clearInterval(this.timer);
                this.seconds = from;

                if (from <= 0) {
                    return;
                }

                this.timer = setInterval(() => {
                    if (--this.seconds <= 0) {
                        this.seconds = 0;
                        clearInterval(this.timer);
                    }
                }, 1000);
            },
            init() {
                this.start($wire.resendCooldownSeconds);

                $wire.$watch('resendCooldownSeconds', (value) => this.start(value));
            },
        }"
    >
        <div x-show="seconds === 0" @if ($cooldownSeconds > 0) style="display: none;" @endif>
            {{ $resendAction }}
        </div>

        <x-filament::button x-show="seconds > 0" x-cloak color="gray" disabled class="w-full justify-center">
            <span x-text="seconds === 1 ? @js(__('auth.verify_email.resend_in_one')) : @js(__('auth.verify_email.resend_in')).replace(':seconds', seconds)"></span>
        </x-filament::button>
    </div>

    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
        {{ __('auth.verify_email.spam_hint') }}
    </p>

    <div class="mt-8 flex w-full flex-wrap items-center justify-center gap-x-1.5 border-t border-gray-200 pt-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400 [&_button]:underline [&_button]:underline-offset-2">
        <span>{{ __('auth.verify_email.wrong_email') }}</span>
        {{ $signOutAction }}
    </div>
</div>
