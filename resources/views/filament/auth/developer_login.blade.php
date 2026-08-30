@env('local')
    <div class="fixed bottom-4 end-4 z-10">
        <x-login-link
            email="manuk.minasyan1@gmail.com"
            :redirect-url="url()->getAppUrl()"
            class="text-xs text-gray-400 underline-offset-2 hover:text-gray-600 hover:underline dark:text-gray-600 dark:hover:text-gray-400"
        />
    </div>
@endenv
