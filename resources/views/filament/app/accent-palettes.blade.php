@props(['accents'])

{{--
    The brand ramp is baked into the `@theme` block in resources/css/theme.css, so
    overriding Filament's `--primary-*` alone changes nothing: components read the
    `--color-primary-*` tokens. Both are set here, and the attribute selector is
    what outranks the `:root` defaults.
--}}
<style>
    @foreach($accents as $accent)
        @php($ramp = \Filament\Support\Colors\Color::hex($accent->value))
        html[data-accent="{{ $accent->name }}"] {
            @foreach($ramp as $shade => $value)
                --primary-{{ $shade }}: {{ $value }};
                --color-primary-{{ $shade }}: {{ $value }};
            @endforeach
            --color-primary: {{ $ramp[600] }};
        }
    @endforeach
</style>
