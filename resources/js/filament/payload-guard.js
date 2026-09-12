// Livewire renders a failed request as a full-screen error overlay. A payload over the
// cap is a content problem the writer can fix, so it gets a notification instead.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, content, preventDefault }) => {
            if (status !== 413) {
                return
            }

            preventDefault()

            let message = window.filamentData?.payloadTooLarge ?? ''

            try {
                message = JSON.parse(content).message || message
            } catch (error) {
                //
            }

            new window.FilamentNotification().title(message).danger().send()
        })
    })
})
