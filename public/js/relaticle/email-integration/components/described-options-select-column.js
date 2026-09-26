export default function describedOptionsSelectColumn({
    isDisabled,
    name,
    options,
    recordKey,
    state,
}) {
    return {
        error: undefined,

        isDisabled,

        isLoading: false,

        name,

        open: false,

        options,

        recordKey,

        state,

        unsubscribeLivewireHook: null,

        get selectedLabel() {
            return (
                this.options.find((option) => option.value === this.state)
                    ?.label ?? ''
            )
        },

        init() {
            this.unsubscribeLivewireHook = Livewire.interceptMessage(
                ({ message, onSuccess }) => {
                    onSuccess(() => {
                        this.$nextTick(() => {
                            if (this.isLoading) {
                                return
                            }

                            if (
                                message.component.id !==
                                this.$root.closest('[wire\\:id]')?.attributes[
                                    'wire:id'
                                ].value
                            ) {
                                return
                            }

                            const serverState = this.getServerState()

                            if (
                                serverState === undefined ||
                                this.state === serverState
                            ) {
                                return
                            }

                            this.state = serverState
                        })
                    })
                },
            )

            this.$watch('state', async () => {
                const serverState = this.getServerState()

                if (
                    serverState === undefined ||
                    this.state === serverState
                ) {
                    return
                }

                this.isLoading = true

                const response = await this.$wire.updateTableColumnState(
                    this.name,
                    this.recordKey,
                    this.state,
                )

                this.error = response?.error ?? undefined

                if (!this.error && this.$refs.serverState) {
                    this.$refs.serverState.value = this.state
                }

                this.isLoading = false
            })
        },

        getServerState() {
            if (!this.$refs.serverState) {
                return undefined
            }

            return this.$refs.serverState.value
        },

        select(value) {
            if (this.isDisabled) {
                return
            }

            this.state = value
            this.open = false
        },

        destroy() {
            this.unsubscribeLivewireHook?.()
        },
    }
}
