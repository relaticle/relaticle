<x-mail::message :reason="__('mail.footer.reason.digest')" settings-url="https://app.relaticle.test/settings" unsubscribe-url="https://relaticle.test/unsub">
<x-slot:preheader>Preview line for the inbox</x-slot:preheader>
# Probe heading

<x-mail::list title="Overdue" :rows="$rows" />

<x-mail::button url="https://app.relaticle.test">
Open
</x-mail::button>
</x-mail::message>
