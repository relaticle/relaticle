<footer class="mx-auto w-full max-w-md px-6 pb-8 text-center">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {!! __('auth.footer.terms_notice', [
            'terms' => '<a href="'.e(url('/terms-of-service')).'" class="underline underline-offset-2 hover:text-gray-700 dark:hover:text-gray-300">'.e(__('auth.footer.terms_of_service')).'</a>',
        ]) !!}
    </p>

    <p class="mt-4 flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
        <span>{{ __('auth.footer.copyright', ['year' => now()->year]) }}</span>
        <a href="{{ url('/privacy-policy') }}" class="underline underline-offset-2 hover:text-gray-700 dark:hover:text-gray-300">{{ __('auth.footer.privacy_policy') }}</a>
        <a href="{{ url('/contact') }}" class="underline underline-offset-2 hover:text-gray-700 dark:hover:text-gray-300">{{ __('auth.footer.support') }}</a>
    </p>
</footer>
