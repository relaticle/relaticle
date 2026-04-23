@feature(App\Features\SocialAuth::class)
<div class="space-y-3">
    <x-filament::button
        :href="route('auth.socialite.redirect', 'google')"
        :spa-mode="false"
        tag="a"
        color="gray"
        class="w-full justify-center"
    >
       <span class="flex">
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
        Continue with Google
    </span>
    </x-filament::button>

    <x-filament::button
        :href="route('auth.socialite.redirect', 'github')"
        :spa-mode="false"
        tag="a"
        color="gray"
        class="w-full justify-center flex"
    >
    <span class="flex">
        <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24" fill="currentColor">
            <path
                d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
        </svg>
        Continue with GitHub
    </span>
    </x-filament::button>

    <div class="flex items-center justify-center my-4">
        <div class="w-full border-t border-gray-300 dark:border-gray-700"></div>
        <span class="mx-2 text-gray-500 dark:text-gray-400">or</span>
        <div class="w-full border-t border-gray-300 dark:border-gray-700"></div>
    </div>

</div>
@endfeature
