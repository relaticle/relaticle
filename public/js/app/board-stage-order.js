// Columns of a Flowforge board drag by their header. The package renders the markup, so this
// finds its containers by the documented ff-* classes and re-binds when a morph replaces one.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('boardStageOrder', () => ({
        observer: null,
        frame: null,

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
            cancelAnimationFrame(this.frame)
            document.querySelectorAll('.ff-columns, .ff-lanes-heads').forEach((container) => window.Sortable.get(container)?.destroy())
        },

        columnIds(container) {
            return [...container.querySelectorAll(':scope > .ff-column:not(.ff-column-dragging)')].map((column) => column.dataset.columnRoot)
        },

        restore(container, ids) {
            ids.map((id) => container.querySelector(`:scope > [data-column-root="${CSS.escape(id)}"]`))
                .filter((column) => column !== null)
                .forEach((column) => container.append(column))

            new window.FilamentNotification().title(window.filamentData?.boardStageOrderFailed ?? '').danger().send()
        },

        bind(page) {
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

            page.querySelectorAll('.ff-columns, .ff-lanes-heads').forEach((container) => {
                if (window.Sortable.get(container)) {
                    return
                }

                let before = []

                window.Sortable.create(container, {
                    group: { name: 'board-columns', pull: false, put: false },
                    draggable: '.ff-column',
                    handle: '.ff-column-header',
                    filter: '.ff-column-actions, .ff-count, .ff-column-header button',
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
                    onStart: () => {
                        before = this.columnIds(container)
                    },
                    onEnd: (event) => {
                        if (event.oldDraggableIndex === event.newDraggableIndex) {
                            return
                        }

                        this.$wire.reorderBoardStages(this.columnIds(container)).catch(() => this.restore(container, before))
                    },
                })
            })
        },
    }))
})
