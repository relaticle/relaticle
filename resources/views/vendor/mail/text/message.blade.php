@props(['reason' => null, 'settingsUrl' => null, 'unsubscribeUrl' => null])
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{ $slot }}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer :reason="$reason" :settings-url="$settingsUrl" :unsubscribe-url="$unsubscribeUrl" />
</x-slot:footer>
</x-mail::layout>
