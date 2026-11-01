@php
    $termsUrl = config('relaticle.legal.terms_url');
    $privacyUrl = config('relaticle.legal.privacy_url');
    $contactUrl = config('relaticle.legal.contact_url');
@endphp
<footer class="mx-auto w-full max-w-md px-6 pb-8 text-center">
    @if($termsUrl)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {!! __('auth.footer.terms_notice', [
                'terms' => '<a href="'.e($termsUrl).'" class="underline underline-offset-2 hover:text-gray-700 dark:hover:text-gray-300">'.e(__('auth.footer.terms_of_service')).'</a>',
            ]) !!}
        </p>
    @endif

    <p @class(['flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-gray-500 dark:text-gray-400', 'mt-4' => $termsUrl])>
        <span>{{ __('auth.footer.copyright', ['year' => now()->year]) }}</span>
        @if($privacyUrl)
            <a href="{{ $privacyUrl }}" class="underline underline-offset-2 hover:text-gray-700 dark:hover:text-gray-300">{{ __('auth.footer.privacy_policy') }}</a>
        @endif
        @if($contactUrl)
            <a href="{{ $contactUrl }}" class="underline underline-offset-2 hover:text-gray-700 dark:hover:text-gray-300">{{ __('auth.footer.support') }}</a>
        @endif
    </p>
</footer>
