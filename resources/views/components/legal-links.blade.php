@foreach(['privacy_url' => 'auth.footer.privacy_policy', 'terms_url' => 'auth.footer.terms_of_service'] as $key => $label)
    @if($url = config("relaticle.legal.{$key}"))
        <span>&middot;</span>
        <a href="{{ $url }}" {{ $attributes }}>{{ __($label) }}</a>
    @endif
@endforeach
