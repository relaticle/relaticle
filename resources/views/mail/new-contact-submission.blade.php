<x-mail::message>
# New Contact Submission

**Name:** {{ $data['name'] }}

**Email:** {{ $data['email'] }}

@if($data['company'])
**Company:** {{ $data['company'] }}
@endif

**Message:**

{{ $data['message'] }}

<x-mail::button :url="'mailto:' . $data['email']">
Reply to {{ $data['name'] }}
</x-mail::button>

</x-mail::message>
