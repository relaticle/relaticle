// Columns of a Flowforge board drag by their header. The package renders the markup, so this
// finds its containers by the documented ff-* classes and re-binds when a morph replaces one.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('boardStageOrder', () => ({
        observer: null,
        frame: null,
        sortables: [],

        init() {
            const page = this.$el.closest('.fi-page') ?? document.body

            this.bind(page)

            this.observer = new MutationObserver(() => {
                this.frame ??= requestAnimationFrame(() => {
                    this.frame = null
                    this.bind(page)
                })
            })
            this.observer.observe(page, { childList: true, subtree: true })
        },

        destroy() {
            this.observer?.disconnect()
            this.sortables.forEach((sortable) => sortable.destroy())
        },

        bind(page) {
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

            this.sortables = this.sortables.filter((sortable) => sortable.el.isConnected)

            page.querySelectorAll('.ff-columns, .ff-lanes-heads').forEach((container) => {
                if (container.hasAttribute('data-columns-sortable')) {
                    return
                }

                container.setAttribute('data-columns-sortable', '')

                this.sortables.push(
                    window.Sortable.create(container, {
                        draggable: '.ff-column',
                        handle: '.ff-column-header',
                        filter: '.ff-column-actions, .ff-column-count',
                        preventOnFilter: false,
                        direction: 'horizontal',
                        forceFallback: true,
                        fallbackTolerance: 5,
                        fallbackClass: 'ff-column-dragging',
                        ghostClass: 'ff-column-placeholder',
                        chosenClass: 'ff-column-chosen',
                        delay: 200,
                        delayOnTouchOnly: true,
                        animation: reduceMotion ? 0 : 200,
                        scrollSensitivity: 100,
                        scrollSpeed: 15,
                        forceAutoScrollFallback: true,
                        onEnd: (event) => {
                            if (event.oldDraggableIndex === event.newDraggableIndex) {
                                return
                            }

                            this.$wire.reorderBoardStages(
                                [...container.querySelectorAll(':scope > .ff-column')].map((column) => column.dataset.columnRoot),
                            )
                        },
                    }),
                )
            })
        },
    }))
})
