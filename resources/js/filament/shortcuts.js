// One keydown listener for every single-key shortcut. Components register a scope with the
// entries they handle; the guard lives here once, so a letter never fires while typing.
const scopeOrder = ['composer', 'reader', 'list', 'global']

const blockers = '[role=dialog][aria-modal=true], [role=menu], [role=listbox], .fi-modal'

const hasOpenDropdown = () => document.querySelector(".fi-dropdown-panel[style*='display: block']") !== null

const nonTextInputs = ['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file', 'image']

const isEditable = (target) =>
    (target?.tagName === 'INPUT' && ! nonTextInputs.includes(target.type))
    || ['TEXTAREA', 'SELECT'].includes(target?.tagName)
    || target?.isContentEditable === true

const keyOf = (event) => {
    if (/^[a-z]$/i.test(event.key)) {
        return event.key.toLowerCase()
    }

    // Cyrillic or Armenian layouts type another letter on the same physical key.
    const physical = /^Key([A-Z])$/.exec(event.code ?? '')

    if (event.key.length === 1 && /\p{L}/u.test(event.key) && physical) {
        return physical[1].toLowerCase()
    }

    return event.key
}

const createStore = () => ({
    singleKeys: true,

    registrations: [],

    init() {
        this.readPreference()
        document.addEventListener('livewire:navigated', () => this.readPreference())
        document.addEventListener('keydown', (event) => {
            if (! event.ctrlKey && ! event.metaKey) this.dispatch(event)
        })
        document.addEventListener('keydown', (event) => {
            if (event.ctrlKey || event.metaKey) this.dispatch(event)
        }, true)
    },

    readPreference() {
        const flag = document.querySelector('[data-single-key-shortcuts]')

        this.singleKeys = flag ? flag.dataset.singleKeyShortcuts !== 'false' : true
    },

    lastId: 0,

    // Returns an id, so a component torn down after its replacement registered cannot remove the new one.
    register(name, { scope, entries, handlers, isActive = () => true }) {
        const id = ++this.lastId

        this.unregister(name)
        this.registrations.push({ id, name, scope, entries: entries.filter((entry) => entry.scopes.includes(scope) && handlers[entry.action]), handlers, isActive })

        return id
    },

    unregister(name, id = null) {
        this.registrations = this.registrations.filter((registration) => registration.name !== name || (id !== null && registration.id !== id))
    },

    allows(event, { editors = false, character = true, modified = false } = {}) {
        if (event.altKey || (! modified && (event.ctrlKey || event.metaKey))) {
            return false
        }

        if (character && ! this.singleKeys) {
            return false
        }

        if (! editors && isEditable(event.target)) {
            return false
        }

        if (event.target?.closest?.(blockers) || document.querySelector('.fi-modal-open') || hasOpenDropdown()) {
            return false
        }

        const sidePanel = document.querySelector('[data-chat-side-panel]')

        return ! (sidePanel && getComputedStyle(sidePanel).display !== 'none')
    },

    dispatch(event) {
        if (event.defaultPrevented || event.isComposing) {
            return
        }

        const modified = event.ctrlKey || event.metaKey
        const key = `${modified ? `Mod+${event.shiftKey ? 'Shift+' : ''}` : ''}${keyOf(event)}`

        // Shift makes ? and other symbols, but Shift+J is not j.
        if (event.shiftKey && /^[a-z]$/.test(key)) {
            return
        }

        for (const scope of scopeOrder) {
            const registrations = this.registrations.filter((registration) => registration.scope === scope).reverse()

            for (const registration of registrations) {
                const entry = registration.entries.find((candidate) => candidate.keys.includes(key))

                if (! entry || ! registration.isActive() || ! this.allows(event, { editors: entry.editors, character: key.length === 1, modified })) {
                    continue
                }

                if (registration.handlers[entry.action](event, entry) !== false) {
                    event.preventDefault()
                    if (modified) event.stopPropagation()

                    return
                }
            }
        }
    },

    entryFor(action) {
        return this.registrations.flatMap((registration) => registration.entries).find((entry) => entry.action === action)
    },

    // The off switch acts per key, so a hint or the sheet never shows a key that will not fire.
    liveKeys(entry) {
        return entry.keys.filter((key) => this.singleKeys || key.length !== 1)
    },

    keysFor(action) {
        const entry = this.entryFor(action)
        const keys = entry ? this.liveKeys(entry) : []

        return keys.length > 0 ? keys.map((key) => (key.length === 1 ? key.toUpperCase() : key)).join(' ') : null
    },

    hint(action, label) {
        const keys = this.keysFor(action)

        return keys ? `${label} (${keys.split(' ')[0].replace('Escape', 'Esc')})` : label
    },
})

document.addEventListener('alpine:init', () => {
    window.Alpine.store('shortcuts', createStore())
})
