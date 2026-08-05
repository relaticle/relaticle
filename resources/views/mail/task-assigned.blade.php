<x-mail::message>
# New task assigned to you

**{{ $taskTitle }}**

<x-mail::button :url="$taskUrl">
View task
</x-mail::button>
</x-mail::message>
