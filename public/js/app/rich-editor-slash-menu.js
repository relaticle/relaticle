// Never import here: a second TipTap or ProseMirror copy breaks ProseMirror's instanceof
// checks, so both come from the editor's own bundle via window.FilamentRichEditor.

const { Extension } = window.FilamentRichEditor.tiptap.core
const { Plugin, PluginKey, TextSelection } = window.FilamentRichEditor.tiptap.pmState
const { Decoration, DecorationSet } = window.FilamentRichEditor.tiptap.pmView

const PLUGIN_KEY = new PluginKey('slashMenu')

// Only at the start of a block or after whitespace, so `https://` and `and/or` are quiet.
const TRIGGER = /(?:^|\s)(\/[a-zA-Z0-9]*)$/

// Blocks the caret cannot type its way out of. A document ending in one needs a
// paragraph after it, or the canvas below is unreachable.
const TRAPPING_BLOCKS = ['codeBlock', 'table', 'horizontalRule', 'details', 'grid']

const trailingParagraph = (state) =>
    state.tr.insert(state.doc.content.size, state.schema.nodes.paragraph.create())

const endsInTrappingBlock = (doc) => TRAPPING_BLOCKS.includes(doc.lastChild?.type.name)

const EMPTY_CONFIG = { items: [], noResults: '', placeholder: '' }

const config = (view) => {
    const host = view.dom.closest('[data-slash-menu]')

    return host ? JSON.parse(atob(host.getAttribute('data-slash-menu'))) : EMPTY_CONFIG
}

// Built as nodes, not a CSS `content` string, so the trigger can render as a key cap.
const placeholderElement = (template) => {
    const el = document.createElement('span')
    el.className = 'fi-slash-placeholder'
    el.setAttribute('aria-hidden', 'true')
    el.contentEditable = 'false'

    const [before, after = ''] = template.split(':key')
    const key = document.createElement('kbd')
    key.textContent = '/'

    el.append(document.createTextNode(before), key, document.createTextNode(after))

    return el
}

// Below this the panel is not worth flipping or capping to, so it is allowed to
// overflow the viewport and scroll the page instead.
const MIN_PANEL_HEIGHT = 160

// Measured, not CSS: the schema between the modal and the field is content-sized grids.
// Slack is read from the whole form, so a field below the editor keeps its room.
const CANVAS_MIN_HEIGHT = 160
const CANVAS_TOLERANCE = 2

class CanvasFitView {
    constructor(view) {
        this.content = view.dom.closest('.fi-fo-rich-editor-seamless .fi-fo-rich-editor-content')
        this.fit = this.fit.bind(this)

        if (! this.content) {
            return
        }

        // The modal body is `flex: 1`, so its own box already spans the panel and
        // reports no slack. The form schema inside it is sized to its content.
        const container = this.content.closest('.fi-modal-content')
        this.extent = container?.lastElementChild ?? container ?? this.content

        // The gap below the canvas is the container's own bottom padding, which sits
        // outside `extent`. Counting it is what keeps the panel from scrolling by it.
        this.gap = container ? parseFloat(getComputedStyle(container).paddingBottom) || 0 : 0

        // The sticky footer's top is the bottom of the usable area, and it does not
        // move when the canvas grows, so it is a stable limit to measure against.
        this.footer = this.content.closest('.fi-modal-window')?.querySelector(':scope > .fi-modal-footer')

        this.fit()

        window.addEventListener('resize', this.fit)

        // Each pass closes the gap exactly, so the observer settles in one more frame.
        // It also catches the fields above changing height without a window resize.
        this.observer = new ResizeObserver(this.fit)
        this.observer.observe(this.extent)
    }

    fit() {
        const limit = this.footer ? this.footer.getBoundingClientRect().top : window.innerHeight
        const slack = limit - this.extent.getBoundingClientRect().bottom - this.gap

        if (Math.abs(slack) < CANVAS_TOLERANCE) {
            return
        }

        const height = this.content.getBoundingClientRect().height

        this.content.style.minHeight = `${Math.max(height + slack, CANVAS_MIN_HEIGHT)}px`
    }

    destroy() {
        window.removeEventListener('resize', this.fit)
        this.observer?.disconnect()
    }
}

class SlashMenuView {
    constructor(view, settings) {
        this.view = view
        this.isOpen = false
        this.items = []
        this.activeIndex = 0
        this.range = null

        this.allItems = settings.items
        this.noResults = settings.noResults
        this.scope = view.dom.closest('[x-data]')

        this.panel = document.createElement('div')
        // fi-dropdown-list only to opt out of the panel's divide-y between children.
        this.panel.className = 'fi-dropdown-panel fi-dropdown-list fi-width-2xs fi-not-prose fi-slash-menu'
        this.panel.setAttribute('role', 'listbox')
        this.panel.hidden = true
        document.body.appendChild(this.panel)

        this.onDocumentPointerDown = (event) => {
            if (this.isOpen && ! this.panel.contains(event.target)) {
                this.close()
            }
        }
        document.addEventListener('mousedown', this.onDocumentPointerDown)
    }

    update() {
        const trigger = this.detectTrigger()

        if (! trigger) {
            this.close()

            return
        }

        this.range = trigger.range
        this.items = this.filter(trigger.query)
        this.activeIndex = 0
        this.render()
        this.open()
    }

    detectTrigger() {
        const { selection } = this.view.state

        if (! selection.empty) {
            return null
        }

        const { $from } = selection

        if ($from.parent.isAtom || ! $from.parent.isTextblock) {
            return null
        }

        const match = $from.parent.textBetween(0, $from.parentOffset, undefined, '\n').match(TRIGGER)

        if (! match) {
            return null
        }

        return {
            query: match[1].slice(1).toLowerCase(),
            range: { from: $from.pos - match[1].length, to: $from.pos },
        }
    }

    filter(query) {
        if (query === '') {
            return this.allItems
        }

        return this.allItems.filter(
            (item) =>
                item.id.toLowerCase().includes(query) ||
                item.label.toLowerCase().replace(/\s/g, '').includes(query),
        )
    }

    render() {
        this.panel.innerHTML = ''
        this.itemElements = []

        if (this.items.length === 0) {
            const empty = document.createElement('div')
            empty.className = 'fi-slash-menu-empty'
            empty.textContent = this.noResults.replace(':query', this.currentQuery())
            this.panel.appendChild(empty)

            return
        }

        let group = null

        this.items.forEach((item, index) => {
            if (item.group !== group) {
                group = item.group

                const heading = document.createElement('div')
                heading.className = 'fi-slash-menu-group'
                heading.textContent = group
                this.panel.appendChild(heading)
            }

            this.panel.appendChild(this.renderItem(item, index))
        })
    }

    renderItem(item, index) {
        const button = document.createElement('button')
        button.type = 'button'
        button.className = 'fi-slash-menu-item'
        button.setAttribute('role', 'option')
        button.setAttribute('aria-selected', index === this.activeIndex ? 'true' : 'false')
        button.title = item.description

        const icon = document.createElement('span')
        icon.className = 'fi-slash-menu-item-icon'
        icon.innerHTML = item.icon

        const label = document.createElement('span')
        label.className = 'fi-slash-menu-item-label'
        label.textContent = item.label

        button.append(icon, label)

        if (item.shortcut) {
            const shortcut = document.createElement('kbd')
            shortcut.className = 'fi-slash-menu-item-shortcut'
            shortcut.textContent = item.shortcut
            button.appendChild(shortcut)
        }

        // mousedown, not click: click fires after the editor has already lost focus,
        // and the command needs a live selection to act on.
        button.addEventListener('mousedown', (event) => {
            event.preventDefault()
            this.select(index)
        })
        button.addEventListener('mousemove', () => this.setActiveIndex(index))

        this.itemElements[index] = button

        return button
    }

    currentQuery() {
        return this.range ? this.view.state.doc.textBetween(this.range.from + 1, this.range.to) : ''
    }

    setActiveIndex(index) {
        if (this.activeIndex === index) {
            return
        }

        this.itemElements[this.activeIndex]?.setAttribute('aria-selected', 'false')
        this.itemElements[index]?.setAttribute('aria-selected', 'true')
        this.activeIndex = index
    }

    handleKeyDown(event) {
        if (! this.isOpen) {
            return false
        }

        if (event.key === 'Escape') {
            this.close()

            return true
        }

        if (this.items.length === 0) {
            return false
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            const step = event.key === 'ArrowDown' ? 1 : -1
            this.setActiveIndex((this.activeIndex + step + this.items.length) % this.items.length)
            this.itemElements[this.activeIndex]?.scrollIntoView({ block: 'nearest' })

            return true
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            this.select(this.activeIndex)

            return true
        }

        return false
    }

    select(index) {
        const item = this.items[index]

        if (! item) {
            return
        }

        const { from, to } = this.range

        this.close()
        this.view.dispatch(this.view.state.tr.delete(from, to))

        // Deferred so the component's `editorSelection` has caught up with the deletion:
        // an action that opens a modal reads it server-side to know where to insert.
        queueMicrotask(() => window.Alpine.evaluate(this.scope, item.action))
    }

    open() {
        this.isOpen = true
        this.panel.hidden = false
        this.position()
    }

    close() {
        this.isOpen = false
        this.panel.hidden = true
        this.range = null
        this.items = []
        this.activeIndex = 0
    }

    position() {
        const margin = 8
        const caret = this.view.coordsAtPos(this.range.from)

        const spaceBelow = window.innerHeight - caret.bottom - margin * 2
        const spaceAbove = caret.top - margin * 2

        // The chosen side caps the height, so a short viewport scrolls the list instead
        // of pushing it off-screen.
        this.panel.style.maxHeight = ''
        const placeAbove = this.panel.offsetHeight > spaceBelow && spaceAbove > spaceBelow
        this.panel.style.maxHeight = `${Math.max(placeAbove ? spaceAbove : spaceBelow, MIN_PANEL_HEIGHT)}px`

        const { offsetWidth: width, offsetHeight: height } = this.panel
        const top = placeAbove ? caret.top - height - margin : caret.bottom + margin
        const left = Math.min(caret.left, window.innerWidth - width - margin)

        this.panel.style.top = `${Math.max(top, margin) + window.scrollY}px`
        this.panel.style.left = `${Math.max(left, margin) + window.scrollX}px`
    }

    destroy() {
        document.removeEventListener('mousedown', this.onDocumentPointerDown)
        this.panel.remove()
    }
}

export default Extension.create({
    name: 'slashMenu',

    addProseMirrorPlugins() {
        const { editor } = this

        let settings = null
        const getSettings = () => (settings ??= config(editor.view))

        let menu = null

        return [
            new Plugin({
                key: PLUGIN_KEY,
                view(editorView) {
                    menu = new SlashMenuView(editorView, getSettings())

                    return menu
                },
                props: {
                    handleKeyDown: (view, event) => menu?.handleKeyDown(event) ?? false,
                },
            }),

            // ProseMirror sets role="textbox" but not this, so a screen reader
            // announces a single-line field and reads Enter as submit.
            new Plugin({
                props: { attributes: { 'aria-multiline': 'true' } },
            }),

            new Plugin({
                view: (editorView) => new CanvasFitView(editorView),
            }),

            new Plugin({
                props: {
                    decorations({ doc }) {
                        if (doc.childCount !== 1 || doc.firstChild.content.size > 0) {
                            return null
                        }

                        return DecorationSet.create(doc, [
                            Decoration.widget(1, () => placeholderElement(getSettings().placeholder), { side: 1, key: 'slash-placeholder' }),
                        ])
                    },
                },
            }),

            new Plugin({
                appendTransaction(transactions, oldState, newState) {
                    if (! transactions.some((tr) => tr.docChanged)) {
                        return null
                    }

                    return endsInTrappingBlock(newState.doc) ? trailingParagraph(newState) : null
                },
                props: {
                    // Content that loaded with a trapping block last never went through
                    // appendTransaction, so the click that reveals the gap also closes it.
                    handleClick(view, pos, event) {
                        const last = view.dom.lastElementChild

                        if (! last || ! endsInTrappingBlock(view.state.doc)) {
                            return false
                        }

                        if (event.clientY <= last.getBoundingClientRect().bottom) {
                            return false
                        }

                        const tr = trailingParagraph(view.state)
                        view.dispatch(tr.setSelection(TextSelection.near(tr.doc.resolve(tr.doc.content.size))))

                        return true
                    },
                },
            }),
        ]
    },
})
