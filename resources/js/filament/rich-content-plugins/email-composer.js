import { Extension, Node } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'

const settingsFor = (element) => {
    const host = element.closest('[data-email-commands]')

    return host ? JSON.parse(atob(host.dataset.emailCommands)) : { variables: {}, commands: [], labels: {} }
}

const positionPanel = (panel, rectangle) => {
    panel.style.left = `${Math.max(8, Math.min(rectangle.left, innerWidth - panel.offsetWidth - 8))}px`
    panel.style.top = `${Math.max(8, Math.min(rectangle.bottom + 8, innerHeight - panel.offsetHeight - 8))}px`
}

const makePanel = (role) => {
    const panel = document.createElement('div')
    panel.className = 'fi-dropdown-panel fi-dropdown-list fi-not-prose ei-command-panel'
    panel.setAttribute('role', role)
    panel.popover = 'manual'
    document.body.append(panel)

    return panel
}

const Variable = Node.create({
    name: 'emailVariable',
    group: 'inline',
    inline: true,
    atom: true,

    addAttributes() {
        return {
            code: { default: '', parseHTML: (element) => element.dataset.code },
            fallback: { default: '', parseHTML: (element) => element.dataset.fallback ?? '' },
        }
    },

    parseHTML() { return [{ tag: 'span[data-type="emailVariable"]' }] },

    renderHTML({ node }) {
        const { code, fallback } = node.attrs

        return ['span', { 'data-type': 'emailVariable', 'data-code': code, 'data-fallback': fallback },
            `{{ ${code}${fallback ? ` | ${fallback}` : ''} }}`]
    },

    renderText({ node }) {
        return `{{ ${node.attrs.code}${node.attrs.fallback ? ` | ${node.attrs.fallback}` : ''} }}`
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            const settings = settingsFor(editor.view.dom)
            const dom = document.createElement('span')
            dom.setAttribute('role', 'button')
            dom.tabIndex = 0
            let current = node
            let panel = null
            let outside = null
            const close = () => {
                panel?.remove()
                panel = null
                document.removeEventListener('pointerdown', outside)
            }
            const paint = () => {
                const { code, fallback } = current.attrs
                const known = Object.hasOwn(settings.variables, code)
                dom.className = `ei-variable ${! known ? 'ei-variable-unknown' : fallback ? 'ei-variable-fallback' : 'ei-variable-empty'}`
                dom.dataset.type = 'emailVariable'
                dom.dataset.code = code
                dom.dataset.fallback = fallback
                dom.textContent = `${settings.variables[code] ?? code}${fallback ? ` | ${fallback}` : ''}`
                dom.title = known ? settings.labels.editFallback : settings.labels.unknown
                dom.setAttribute('aria-label', `${dom.textContent}. ${dom.title}`)
            }
            const edit = () => {
                close()
                panel = makePanel('dialog')
                panel.setAttribute('aria-label', settings.labels.editFallback)
                const form = document.createElement('form')
                const label = document.createElement('label')
                label.textContent = settings.labels.fallback
                const input = document.createElement('input')
                input.type = 'text'
                input.maxLength = 255
                input.value = current.attrs.fallback
                label.append(input)
                const save = document.createElement('button')
                save.type = 'submit'
                save.className = 'fi-btn fi-size-sm'
                save.textContent = settings.labels.save
                form.append(label, save)
                form.addEventListener('submit', (event) => {
                    event.preventDefault()
                    const position = getPos()
                    if (typeof position === 'number' && editor.state.doc.nodeAt(position)?.type.name === 'emailVariable') {
                        editor.view.dispatch(editor.state.tr.setNodeMarkup(position, undefined, { ...current.attrs, fallback: input.value.trim() }))
                    }
                    close()
                    editor.commands.focus()
                })
                panel.append(form)
                panel.showPopover()
                positionPanel(panel, dom.getBoundingClientRect())
                input.focus()
                panel.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') { event.preventDefault(); close(); editor.commands.focus() }
                })
                outside = (event) => { if (! panel?.contains(event.target) && ! dom.contains(event.target)) close() }
                document.addEventListener('pointerdown', outside)
            }
            dom.addEventListener('click', edit)
            dom.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); edit() }
            })
            paint()

            return {
                dom,
                update(next) {
                    if (next.type.name !== 'emailVariable') return false
                    current = next
                    paint()
                    return true
                },
                stopEvent: () => true,
                ignoreMutation: () => true,
                destroy: close,
            }
        }
    },
})

class CommandMenu {
    constructor(view) {
        this.view = view
        this.settings = settingsFor(view.dom)
        this.panel = makePanel('listbox')
        this.panel.setAttribute('aria-label', this.settings.labels.insert ?? '')
        this.selected = 0
        this.range = null
        this.outside = (event) => { if (! this.panel.contains(event.target)) this.close() }
        document.addEventListener('pointerdown', this.outside)
    }

    close() { this.range = null; this.panel.hidePopover() }

    update() {
        const { $from, empty } = this.view.state.selection
        const match = empty && this.view.hasFocus() && ! $from.parent.type.spec.code
            ? $from.parent.textBetween(0, $from.parentOffset, undefined, '\n').match(/(?:^|\s)([{/][\w.]*)$/)
            : null
        if (! match) return this.close()
        const query = match[1].slice(1).toLowerCase()
        const variables = match[1][0] === '{'
        this.items = variables
            ? Object.entries(this.settings.variables).map(([id, label]) => ({ id, label, kind: 'variable' }))
            : this.settings.commands
        this.items = this.items.filter((item) => `${item.id} ${item.label}`.toLowerCase().includes(query))
        this.range = { from: $from.pos - match[1].length, to: $from.pos }
        this.selected = Math.min(this.selected, Math.max(0, this.items.length - 1))
        this.panel.replaceChildren()
        this.items.forEach((item, index) => {
            const button = document.createElement('button')
            button.type = 'button'
            button.className = 'fi-slash-menu-item'
            button.setAttribute('role', 'option')
            button.setAttribute('aria-selected', String(index === this.selected))
            button.textContent = item.label
            button.addEventListener('mousedown', (event) => { event.preventDefault(); this.select(index) })
            this.panel.append(button)
        })
        if (this.items.length === 0) this.panel.textContent = this.settings.labels.noResults
        this.panel.showPopover()
        positionPanel(this.panel, this.view.coordsAtPos($from.pos))
    }

    select(index) {
        const item = this.items[index]
        if (! item || ! this.range) return
        const { from, to } = this.range
        this.close()
        const transaction = this.view.state.tr.delete(from, to)
        if (item.kind === 'variable') {
            transaction.insert(from, this.view.state.schema.nodes.emailVariable.create({ code: item.id, fallback: '' }))
        }
        this.view.dispatch(transaction)
        this.view.focus()
        if (item.kind !== 'variable') {
            const component = this.view.dom.closest('[wire\\:id]')
            const wire = window.Livewire.find(component.getAttribute('wire:id'))
            if (item.kind === 'template') wire.applyTemplate(item.id)
            if (item.kind === 'signature') wire.set('signatureId', item.id)
        }
    }

    key(event) {
        if (! this.range) return false
        if (event.key === 'Escape') { this.close(); return true }
        if (['ArrowDown', 'ArrowUp'].includes(event.key) && this.items.length) {
            this.selected = (this.selected + (event.key === 'ArrowDown' ? 1 : -1) + this.items.length) % this.items.length
            this.update()
            this.panel.children[this.selected]?.scrollIntoView({ block: 'nearest' })
            return true
        }
        if (['Enter', 'Tab'].includes(event.key) && this.items.length) { this.select(this.selected); return true }
        return false
    }

    destroy() { document.removeEventListener('pointerdown', this.outside); this.panel.remove() }
}

export default Extension.create({
    name: 'emailComposer',
    addExtensions() { return [Variable] },
    onCreate() { this.editor.view.dispatch(this.editor.state.tr.setMeta('parseEmailVariables', true)) },
    addProseMirrorPlugins() {
        let menu

        return [new Plugin({
            key: new PluginKey('emailComposer'),
            view(view) {
                menu = new CommandMenu(view)
                return menu
            },
            props: { handleKeyDown: (view, event) => menu?.key(event) ?? false },
            appendTransaction(transactions, oldState, state) {
                if (! transactions.some((transaction) => transaction.docChanged || transaction.getMeta('parseEmailVariables'))) return null
                const matches = []
                state.doc.descendants((node, position, parent) => {
                    if (! node.isText || parent.type.spec.code) return
                    for (const match of node.text.matchAll(/\{\{\s*([\w.]+)(?:\s*\|\s*([^}]*))?\s*\}\}/g)) {
                        matches.push({ from: position + match.index, to: position + match.index + match[0].length, code: match[1], fallback: (match[2] ?? '').trim() })
                    }
                })
                if (! matches.length) return null
                const transaction = state.tr
                for (const { from, to, code, fallback } of matches.reverse()) {
                    transaction.replaceWith(from, to, state.schema.nodes.emailVariable.create({ code, fallback }))
                }
                return transaction
            },
        })]
    },
})
