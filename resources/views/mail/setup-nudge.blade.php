<x-mail::message :reason="__('mail.footer.reason.onboarding', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.setup_nudge.preheader', ['workspace' => $workspaceName, 'step' => $stepLabel]) }}</x-slot:preheader>
<h1>{{ __('mail.setup_nudge.heading', ['name' => $greetingName, 'workspace' => $workspaceName]) }}</h1>

{{ __('mail.setup_nudge.step', ['step' => $stepLabel]) }} {{ $stepDescription }}.

<x-mail::button :url="$conversationUrl">
{{ __('mail.setup_nudge.cta', ['assistant' => config('chat.assistant_name')]) }}
</x-mail::button>
</x-mail::message>
