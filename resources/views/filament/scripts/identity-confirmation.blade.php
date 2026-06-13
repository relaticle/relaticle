@assets
    @vite('resources/js/passkeys.js')
@endassets

<script>
    document.addEventListener('livewire:init', () => {
        const routes = {
            options: '{{ route('passkey.confirm-options') }}',
            submit: '{{ route('passkey.confirm') }}',
        };

        const modalSubmitForm = () =>
            document.querySelector('.fi-modal-window [type=submit]')?.closest('form') ?? null;

        const startProcessing = (message) =>
            modalSubmitForm()?.dispatchEvent(
                new CustomEvent('form-processing-started', { detail: { message } }),
            );

        const stopProcessing = () =>
            modalSubmitForm()?.dispatchEvent(new CustomEvent('form-processing-finished'));

        Livewire.on('confirm-identity-ceremony', async ({ componentId }) => {
            const component = Livewire.find(componentId);

            if (! component) {
                return;
            }

            startProcessing(@js(__('profile.sections.passkeys.waiting')));

            try {
                await window.Passkeys.verify({ routes });

                startProcessing(@js(__('profile.sections.passkeys.confirmed')));
                await new Promise((resolve) => setTimeout(resolve, 600));

                await component.call('callMountedAction');
            } catch (e) {
                stopProcessing();

                if (e?.constructor?.name === 'UserCancelledError') {
                    return;
                }

                component.call('notifyIdentityConfirmationFailed');
                component.call('unmountAction');
            }
        });
    });
</script>
