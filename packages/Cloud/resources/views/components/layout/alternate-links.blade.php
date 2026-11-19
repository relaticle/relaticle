@foreach(\Relaticle\Cloud\Marketing\Support\LocalizedUrl::alternates() as $hreflang => $href)
    <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}" />
@endforeach
