<div
    x-data="{ last: localStorage.getItem('relaticle:last-login') }"
    class="flex flex-col gap-3"
>
    <x-filament::button
        :href="route('auth.socialite.redirect', 'google')"
        :spa-mode="false"
        tag="a"
        color="gray"
        class="w-full justify-center"
        x-on:click="localStorage.setItem('relaticle:last-login', 'google')"
    >
        <span class="flex items-center">
            <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24">
                <path fill="#EA4335"
                      d="M5.266 9.765A7.077 7.077 0 0 1 12 4.909c1.69 0 3.218.6 4.418 1.582L19.91 3C17.782 1.145 15.055 0 12 0 7.27 0 3.198 2.698 1.24 6.65l4.026 3.115Z"/>
                <path fill="#34A853"
                      d="M16.04 18.013c-1.09.703-2.474.987-4.04.987a7.077 7.077 0 0 1-6.723-4.823l-4.04 3.067A11.965 11.965 0 0 0 12 24c2.933 0 5.735-1.043 7.834-3l-3.793-2.987Z"/>
                <path fill="#4A90E2"
                      d="M19.834 21c2.195-2.048 3.62-5.096 3.62-9 0-.71-.109-1.473-.272-2.182H12v4.637h6.436c-.317 1.559-1.17 2.766-2.395 3.558L19.834 21Z"/>
                <path fill="#FBBC05"
                      d="M5.277 14.268A7.12 7.12 0 0 1 4.909 12c0-.782.125-1.533.357-2.235L1.24 6.65A11.934 11.934 0 0 0 0 12c0 1.92.445 3.73 1.237 5.335l4.04-3.067Z"/>
            </svg>
            {{ __('auth.login.continue_with', ['provider' => 'Google']) }}
            <span x-show="last === 'google'" x-cloak class="ms-2 rounded-full bg-primary-100 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ __('auth.login.last_used') }}</span>
        </span>
    </x-filament::button>

    @if (filled(config('services.microsoft.client_id')))
        <x-filament::button
            :href="route('auth.socialite.redirect', 'microsoft')"
            :spa-mode="false"
            tag="a"
            color="gray"
            class="w-full justify-center"
            x-on:click="localStorage.setItem('relaticle:last-login', 'microsoft')"
        >
            <span class="flex items-center">
                <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24">
                    <path fill="#F35325" d="M1 1h10v10H1z"/>
                    <path fill="#81BC06" d="M13 1h10v10H13z"/>
                    <path fill="#05A6F0" d="M1 13h10v10H1z"/>
                    <path fill="#FFBA08" d="M13 13h10v10H13z"/>
                </svg>
                {{ __('auth.login.continue_with', ['provider' => 'Microsoft']) }}
                <span x-show="last === 'microsoft'" x-cloak class="ms-2 rounded-full bg-primary-100 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ __('auth.login.last_used') }}</span>
            </span>
        </x-filament::button>
    @endif
</div>
