// Board pieces: the View settings panel, the drag animation every sortable list shares, and the
// column drag by header. The package renders the columns, so that finds its containers by the
// documented ff-* classes and re-binds when a morph replaces one.

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('dragAnimation', () => (duration = 150) => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : duration))

    window.Alpine.data('boardViewSettings', () => ({
        ...window.viewSettingsAddPanel({
            root: (component) => component.$root,
            items: '[data-board-add-item]',
            key: 'boardAddItem',
            back: '[data-board-panel=add] [data-view-settings-back]',
            pick: 'add',
        }),
        panel: 'main',
        openPanel(name) {
            this.panel = name
            this.$nextTick(() => requestAnimationFrame(() => {
                const view = this.$root.querySelector(`[data-board-panel='${name}']`)

                if (name === 'add') {
                    return this.focusAddStart()
                }

                ;(view.querySelector('[aria-checked=true]') ?? view.querySelector('[data-view-settings-back]'))?.focus()
            }))
        },
        closePanel() {
            const name = this.panel

            this.panel = 'main'
            this.query = ''
            this.$nextTick(() => requestAnimationFrame(() => this.$root.querySelector(`[data-panel-trigger='${name}']`)?.focus()))
        },
        syncSwitch(wrapper) {
            const toggle = wrapper.querySelector('[data-visible]')

            if (toggle) {
                window.Alpine.$data(toggle).state = toggle.dataset.visible === 'true'
            }
        },
        kinds: {
            stage: { list: 'list', row: '[data-stage-id]', key: 'stageId', trigger: '[data-stage-actions]' },
            attribute: { list: 'attributeList', row: '[data-card-attribute]', key: 'cardAttribute', trigger: '[data-attribute-actions]' },
        },
        rowStage: null,
        rowTrigger: null,
        rows(kind) {
            return [...this.$refs[this.kinds[kind].list].querySelectorAll(this.kinds[kind].row)]
        },
        ids(kind) {
            return this.rows(kind).map((row) => row.dataset[this.kinds[kind].key])
        },
        reorder(kind) {
            return kind === 'stage' ? this.$wire.reorderBoardStages(this.ids(kind)) : this.$wire.reorderCardAttributes(this.ids(kind))
        },
        move(kind, id, step) {
            const rows = this.rows(kind)
            const index = rows.findIndex((row) => row.dataset[this.kinds[kind].key] === id)
            const target = rows[index + step]

            if (! target) {
                return
            }

            step < 0 ? target.before(rows[index]) : target.after(rows[index])
            const saved = this.reorder(kind)
            const focusRow = () => rows[index].querySelector(this.kinds[kind].trigger)?.focus()

            this.closeRowMenu()
            this.$nextTick(focusRow)
            saved.then(focusRow, focusRow)
        },
        remove(id) {
            const rows = this.rows('attribute')
            const index = rows.findIndex((row) => row.dataset.cardAttribute === id)
            const neighbour = rows[index + 1] ?? rows[index - 1]
            const focusNext = () => (neighbour?.isConnected ? neighbour.querySelector(this.kinds.attribute.trigger) : this.$root.querySelector('[data-panel-trigger=add]'))?.focus()
            const saved = this.$wire.removeCardAttribute(id)

            this.closeRowMenu()
            saved.then(() => this.$nextTick(focusNext), focusNext)
        },
        closeRowMenu() {
            window.Alpine.$data(this.$refs.rowMenu.querySelector('.fi-dropdown')).close()
        },
        rowMenuShown() {
            return !! document.querySelector('.fi-board-stages-row-menu')?._x_isShown
        },
        escapeRowMenu() {
            this.$nextTick(() => requestAnimationFrame(() => this.rowTrigger?.focus()))
        },
        openRowMenu(trigger, event, kind, id) {
            this.rowTrigger = trigger

            const panel = document.querySelector('.fi-board-stages-row-menu')

            if (panel._x_isShown && panel.trigger === trigger) {
                return
            }

            const ids = this.ids(kind)

            this.rowStage = { kind, id, isFirst: ids[0] === id, isLast: ids.at(-1) === id }

            setTimeout(() => {
                panel.open(trigger)

                if (event.detail === 0) {
                    this.$nextTick(() => [...panel.querySelectorAll('.fi-dropdown-list-item')].find((item) => item.offsetParent !== null && ! item.disabled)?.focus())
                }
            })
        },

        async add(key) {
            if (this.pending.includes(key)) {
                return
            }

            const root = this.$root

            this.pending.push(key)

            try {
                await this.$wire.addCardAttribute(key)
            } finally {
                this.pending = this.pending.filter((pending) => pending !== key)
                this.revision++
            }

            this.$nextTick(() => requestAnimationFrame(() => this.focusAddStart(root)))
        },
    }))

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
            return [...container.querySelectorAll(':scope > .ff-column:not(.ff-column-dragging)')]
                .map((column) => column.dataset.columnRoot)
                .filter((id) => id !== this.$el.dataset.boardNoValueColumn)
        },

        restore(container, ids) {
            ids.map((id) => container.querySelector(`:scope > [data-column-root="${CSS.escape(id)}"]`))
                .filter((column) => column !== null)
                .forEach((column) => container.append(column))

            new window.FilamentNotification().title(window.filamentData?.boardStageOrderFailed ?? '').danger().send()
        },

        bind(page) {
            page.querySelectorAll('.ff-columns, .ff-lanes-heads').forEach((container) => {
                if (window.Sortable.get(container)) {
                    return
                }

                let before = []

                window.Sortable.create(container, {
                    group: { name: 'board-columns', pull: false, put: false },
                    draggable: `.ff-column:not([data-column-root="${CSS.escape(this.$el.dataset.boardNoValueColumn)}"])`,
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
                    animation: this.$dragAnimation(200),
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
