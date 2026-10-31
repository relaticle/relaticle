<div class="flex min-h-full flex-col">
    <div class="flex justify-center py-6">
        <x-brand.logo-lockup size="md" class="text-gray-900 dark:text-white" />
    </div>

    <div class="mx-auto w-full max-w-[960px] flex-1 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{ $slot }}
    </div>

    <div class="flex items-center justify-center gap-x-1 py-6 text-xs text-gray-400 dark:text-gray-500">
        <span>&copy; {{ date('Y') }} Relaticle</span>
        @if($privacyUrl = config('relaticle.legal.privacy_url'))
            <span>&middot;</span>
            <a href="{{ $privacyUrl }}" class="hover:text-gray-600 dark:hover:text-gray-300">{{ __('auth.footer.privacy_policy') }}</a>
        @endif
        @if($termsUrl = config('relaticle.legal.terms_url'))
            <span>&middot;</span>
            <a href="{{ $termsUrl }}" class="hover:text-gray-600 dark:hover:text-gray-300">{{ __('auth.footer.terms_of_service') }}</a>
        @endif
        <span>&middot;</span>
        <form method="POST" action="{{ filament()->getLogoutUrl() }}" class="inline">
            @csrf
            <button type="submit" class="hover:text-gray-600 dark:hover:text-gray-300">{{ __('auth.verify_email.sign_out') }}</button>
        </form>
    </div>
</div>
