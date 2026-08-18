{{--
    Seeds the signed-in user's timezone from the browser once, then never again — the
    render hook that includes this only fires while `users.timezone` is still null, and
    the action behind the endpoint refuses to overwrite a value that is already set.

    x-init rather than a DOMContentLoaded listener because the panel is an SPA: a
    wire:navigate arrival never fires that event.
--}}
<div
    x-data
    x-init="
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (timezone) {
            fetch(@js($endpoint), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @js(csrf_token()),
                },
                body: JSON.stringify({ timezone }),
            }).catch(() => {});
        }
    "
></div>
