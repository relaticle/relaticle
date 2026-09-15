<x-mail::layout>
<x-slot:preheader>{{ __('mail.mailbox_import_complete.preheader', ['email' => $connectedEmail, 'team' => $teamName]) }}</x-slot:preheader>
<x-slot:header></x-slot:header>

@include('mail.partials.logo-lockup')

<p>{{ __('mail.mailbox_import_complete.greeting', ['name' => $greetingName]) }}</p>

<p>
{{ __('mail.mailbox_import_complete.synced_before_email') }}
<strong>{{ $connectedEmail }}</strong>
{{ __('mail.mailbox_import_complete.synced_before_team') }}
<strong>{{ $teamName }}</strong>
{{ __('mail.mailbox_import_complete.synced_after_team') }}
</p>

<p>
{{ __('mail.mailbox_import_complete.found_before_counts') }}
<strong>{{ number_format($emailCount) }}</strong>
{{ __('mail.mailbox_import_complete.found_between_counts') }}
<strong>{{ number_format($calendarCount) }}</strong>
{{ __('mail.mailbox_import_complete.found_after_counts') }}
</p>

<x-mail::button :url="$workspaceUrl">
{{ __('mail.mailbox_import_complete.cta') }}
</x-mail::button>

<x-slot:subcopy>
<x-mail::subcopy>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-top: 1px solid #e4e4e7; margin-top: 8px;">
<tr>
<td align="center" width="50%" style="padding: 24px 12px 0;">
<p style="margin: 0 0 4px; font-size: 28px; font-weight: 700; line-height: 1.2;">{{ number_format($emailCount) }}</p>
<p style="margin: 0; font-size: 14px;">{{ __('mail.mailbox_import_complete.stat_emails') }}</p>
</td>
<td align="center" width="50%" style="padding: 24px 12px 0;">
<p style="margin: 0 0 4px; font-size: 28px; font-weight: 700; line-height: 1.2;">{{ number_format($calendarCount) }}</p>
<p style="margin: 0; font-size: 14px;">{{ __('mail.mailbox_import_complete.stat_calendar_events') }}</p>
</td>
</tr>
</table>
</x-mail::subcopy>
</x-slot:subcopy>

<x-slot:footer>
<x-mail::footer :reason="__('mail.footer.reason.mailbox_import', ['team' => $teamName])" />
</x-slot:footer>
</x-mail::layout>
