// The chips hold unfinished filters in the browser only. A rule reaches the server once it is complete,
// and the rules the server sends back replace the chips whenever they differ from what was sent.
;(() => {
    const TYPING_DELAY = 300
    const MAX_FIELD_RESULTS = 200
    const SEARCH_THRESHOLD = 8
    const SHOWN_VALUES = 2
    const FADE_WIDTH = 32
    const LOOKUP_KINDS = ['member', 'relation', 'record']
    const LIST_KINDS = ['choice', 'multi_choice', ...LOOKUP_KINDS]

    const strip = (rule) => ({ field: rule.field, condition: rule.condition, ...('value' in rule ? { value: rule.value } : {}) })

    const register = () =>
        window.Alpine.data('filterBar', () => {
            let root = null

            return {
                fields: [],
                view: null,
                server: [],
                chips: [],
                open: null,
                find: '',
                nextId: 1,
                sent: '[]',
                inflight: 0,
                timer: null,
                opener: null,
                observer: null,
                sizeObserver: null,
                results: [],
                pinned: [],
                searching: false,
                ticket: 0,
                searchTimer: null,

                init() {
                    root = this.$root
                    this.read()
                    this.chips = this.server.map((rule) => this.fromRule(rule))
                    this.sent = this.serialize(this.completeRules())
                    this.observer = new MutationObserver(() => {
                        const view = this.view

                        this.read()

                        if (this.view !== view) {
                            this.chips = this.chips.filter((chip) => this.complete(chip))
                            this.open = null
                        }

                        this.reconcile()
                    })
                    this.observer.observe(root, { attributes: true, attributeFilter: ['data-state'] })
                    this.sizeObserver = new ResizeObserver(() => this.fade())
                    this.sizeObserver.observe(this.$refs.strip)
                    this.$watch('chips', () => this.$nextTick(() => this.fade()))
                },

                destroy() {
                    this.observer?.disconnect()
                    this.sizeObserver?.disconnect()
                    clearTimeout(this.timer)
                    clearTimeout(this.searchTimer)
                },

                read() {
                    const state = JSON.parse(root.dataset.state)

                    this.fields = state.fields
                    this.view = state.view
                    this.server = state.rules
                },

                serialize(rules) {
                    return JSON.stringify(rules.map(strip))
                },

                fieldOf(chip) {
                    return this.fields.find((field) => field.key === chip.field)
                },

                conditionOf(chip) {
                    return this.fieldOf(chip)?.conditions.find((condition) => condition.value === chip.condition)
                },

                segment(chip, name) {
                    return root.querySelector(`[data-chip-id="${chip.id}"] [data-filter-segment="${name}"]`)
                },

                fromRule(rule) {
                    return {
                        id: this.nextId++,
                        field: rule.field,
                        condition: rule.condition,
                        value: rule.value ?? null,
                        display: rule.display ?? '',
                        labels: Object.fromEntries((rule.labels ?? []).map((label) => [label.id, label.label])),
                    }
                },

                complete(chip) {
                    const condition = this.conditionOf(chip)

                    if (!condition) {
                        return false
                    }

                    if (!condition.takesValue) {
                        return true
                    }

                    const value = chip.value

                    if (typeof value === 'string') {
                        return value.trim() !== '' && (this.fieldOf(chip).kind !== 'number' || Number.isFinite(Number(value)))
                    }

                    return Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined
                },

                toRule(chip) {
                    const rule = { field: chip.field, condition: chip.condition }

                    if (!this.conditionOf(chip).takesValue) {
                        return rule
                    }

                    const numeric = this.fieldOf(chip).kind === 'number' && typeof chip.value === 'string'

                    return { ...rule, value: numeric ? Number(chip.value) : chip.value }
                },

                completeRules() {
                    return this.chips.filter((chip) => this.complete(chip)).map((chip) => this.toRule(chip))
                },

                label(chip) {
                    return [this.fieldOf(chip)?.label, this.conditionOf(chip)?.label, this.complete(chip) ? chip.display : '']
                        .filter(Boolean)
                        .join(' ')
                },

                matchingFields() {
                    const term = this.find.trim().toLowerCase()

                    return this.fields
                        .filter((field) => field.editable && field.label.toLowerCase().includes(term))
                        .slice(0, MAX_FIELD_RESULTS)
                },

                reconcile() {
                    if (this.inflight > 0 || this.timer) {
                        return
                    }

                    const complete = this.chips.filter((chip) => this.complete(chip))

                    if (this.serialize(complete.map((chip) => this.toRule(chip))) === this.serialize(this.server)) {
                        complete.forEach((chip, index) => (chip.display = this.server[index].display))

                        return
                    }

                    this.chips = this.server.map((rule) => this.fromRule(rule))
                    this.sent = this.serialize(this.server)
                    this.open = null
                },

                async push() {
                    clearTimeout(this.timer)
                    this.timer = null

                    const rules = this.completeRules()
                    const serialized = this.serialize(rules)

                    if (serialized === this.sent) {
                        return
                    }

                    this.sent = serialized
                    this.inflight++

                    try {
                        await this.$wire.setFilterRules(rules)
                    } finally {
                        this.inflight--
                        this.reconcile()
                    }
                },

                schedulePush() {
                    clearTimeout(this.timer)
                    this.timer = setTimeout(() => this.push(), TYPING_DELAY)
                },

                isOpen(id, part) {
                    return this.open?.id === id && this.open?.part === part
                },

                toggle(id, part, event) {
                    if (this.isOpen(id, part)) {
                        return this.close(false)
                    }

                    this.open = { id, part }
                    this.opener = event.currentTarget
                    this.find = ''
                    this.opened()
                    this.focusPop()
                },

                opened() {
                    const chip = this.chips.find((other) => other.id === this.open?.id)

                    this.resetLookup()
                    this.pinned = Array.isArray(chip?.value) ? [...chip.value] : []

                    if (this.open?.part === 'val' && this.isLookup(chip)) {
                        this.lookup(chip)
                    }
                },

                resetLookup() {
                    clearTimeout(this.searchTimer)
                    this.results = []
                    this.ticket++
                    this.searching = false
                },

                close(restoreFocus = true) {
                    const opener = this.opener

                    this.resetLookup()
                    this.open = null
                    this.opener = null
                    this.flush()

                    if (restoreFocus && opener?.isConnected) {
                        opener.focus()
                    }
                },

                flush() {
                    if (this.timer) {
                        this.push()
                    }
                },

                finish() {
                    this.close(true)
                },

                escape(event) {
                    if (this.open) {
                        event.stopPropagation()
                        this.close(true)
                    }
                },

                outside(event) {
                    if (this.open && !event.target.closest('[data-pop], [data-pop-trigger]')) {
                        this.close(false)
                    }
                },

                focusLeft(event) {
                    const next = event.relatedTarget

                    if (this.open && next && !next.closest('[data-pop], [data-pop-trigger]')) {
                        this.close(false)
                    }
                },

                fade() {
                    const strip = this.$refs.strip
                    const edge = strip.scrollWidth - strip.clientWidth - Math.abs(strip.scrollLeft)

                    strip.parentElement.toggleAttribute('data-fade-start', strip.scrollLeft > 1)
                    strip.parentElement.toggleAttribute('data-fade-end', edge > 1)
                },

                reveal(element) {
                    const strip = this.$refs.strip
                    const bounds = strip.getBoundingClientRect()
                    const box = element.getBoundingClientRect()

                    if (box.right > bounds.right - FADE_WIDTH) {
                        strip.scrollLeft += box.right - (bounds.right - FADE_WIDTH)
                    }

                    if (box.left < bounds.left + FADE_WIDTH) {
                        strip.scrollLeft -= bounds.left + FADE_WIDTH - box.left
                    }
                },

                visiblePop() {
                    return [...root.querySelectorAll('[data-pop][data-open="true"]')].find((pop) => pop.checkVisibility())
                },

                focusPop(attempts = 30) {
                    const pop = this.visiblePop()

                    if (!pop) {
                        if (attempts > 0) {
                            setTimeout(() => this.focusPop(attempts - 1), 20)
                        }

                        return
                    }


                    this.reveal(pop.closest('.fi-filter-chip') ?? pop.parentElement)

                    if (pop.contains(document.activeElement)) {
                        return
                    }

                    const target =
                        pop.querySelector('input') ?? pop.querySelector('[aria-selected="true"]') ?? pop.querySelector('[data-pop-item]')

                    target?.focus()
                },

                items(pop) {
                    return [...pop.querySelectorAll('input[data-filter-find], input[data-filter-search], [data-pop-item]')].filter((item) => item.checkVisibility())
                },

                navigate(event) {
                    const pop = event.currentTarget
                    const items = this.items(pop)
                    const index = items.indexOf(document.activeElement)
                    const step = { ArrowDown: 1, ArrowUp: -1 }[event.key]

                    if (step !== undefined && items.length) {
                        event.preventDefault()
                        items[(index + step + items.length) % items.length].focus()
                    } else if (['Home', 'End'].includes(event.key) && document.activeElement.tagName !== 'INPUT') {
                        event.preventDefault()
                        items[event.key === 'Home' ? 0 : items.length - 1]?.focus()
                    }
                },

                pickFirst() {
                    const first = this.matchingFields()[0]

                    if (first) {
                        this.addChip(first)
                    }
                },

                addChip(field) {
                    const chip = { id: this.nextId++, field: field.key, condition: null, value: null, display: '', labels: {} }

                    this.chips.push(chip)
                    this.resetLookup()
                    this.open = { id: chip.id, part: 'cond' }
                    this.find = ''
                    this.opener = null
                    this.$nextTick(() => {
                        this.opener = this.segment(chip, 'condition')
                        this.focusPop()
                    })
                },

                pickCondition(chip, condition) {
                    const crossesShape = this.conditionOf(chip)?.shape !== condition.shape

                    chip.condition = condition.value

                    if (!condition.takesValue || crossesShape) {
                        chip.value = null
                        chip.display = ''
                    }

                    const needsValue = condition.takesValue && !this.complete(chip)

                    this.open = needsValue ? { id: chip.id, part: 'val' } : null

                    if (needsValue) {
                        this.find = ''
                        this.opened()
                        this.$nextTick(() => {
                            this.opener = this.segment(chip, 'value')
                            this.focusPop()
                        })
                    } else {
                        this.$nextTick(() => this.segment(chip, 'condition')?.focus())
                    }

                    this.push()
                },

                isListEditor(chip) {
                    return this.conditionOf(chip)?.shape === 'list' && LIST_KINDS.includes(this.fieldOf(chip)?.kind)
                },

                isLookup(chip) {
                    return chip !== undefined && this.isListEditor(chip) && LOOKUP_KINDS.includes(this.fieldOf(chip).kind)
                },

                showSearch(chip) {
                    return this.isLookup(chip) || this.fieldOf(chip).options.length > SEARCH_THRESHOLD
                },

                labelFor(chip, id) {
                    const option = this.fieldOf(chip).options.find((candidate) => candidate.id === id)

                    return option?.label ?? chip.labels[id] ?? id
                },

                isChecked(chip, id) {
                    return Array.isArray(chip.value) && chip.value.includes(id)
                },

                optionColor(option) {
                    const color = option.color ?? ''

                    return /^(#[0-9a-f]{3,8}|oklch\([0-9. %]+\))$/i.test(color) ? color : null
                },

                listItems(chip) {
                    const field = this.fieldOf(chip)
                    const term = this.find.trim().toLowerCase()
                    const known = (id) => field.options.find((option) => option.id === id)
                    const pinned = this.pinned
                        .map((id) => ({ id, label: this.labelFor(chip, id), color: known(id)?.color ?? null }))
                        .sort((a, b) => Number(b.id === '@me') - Number(a.id === '@me'))
                    const pool = this.isLookup(chip)
                        ? [...field.options.filter((option) => option.label.toLowerCase().includes(term)), ...this.results]
                        : field.options.filter((option) => option.label.toLowerCase().includes(term))
                    const seen = new Set(pinned.map((item) => item.id))

                    return [...pinned, ...pool.filter((option) => !seen.has(option.id) && seen.add(option.id))]
                },

                toggleOption(chip, option) {
                    const current = Array.isArray(chip.value) ? chip.value : []

                    chip.labels[option.id] = option.label
                    chip.value = current.includes(option.id) ? current.filter((id) => id !== option.id) : [...current, option.id]
                    chip.display = this.summary(chip)
                    this.schedulePush()
                },

                summary(chip) {
                    const labels = (chip.value ?? []).map((id) => this.labelFor(chip, id))
                    const shown = labels.slice(0, SHOWN_VALUES).join(', ')

                    return labels.length > SHOWN_VALUES ? `${shown} +${labels.length - SHOWN_VALUES}` : shown
                },

                typed(chip) {
                    this.resetLookup()
                    this.pinned = Array.isArray(chip.value) ? [...chip.value] : []

                    if (this.isLookup(chip)) {
                        this.searching = true
                        this.searchTimer = setTimeout(() => this.lookup(chip), TYPING_DELAY)
                    }
                },

                async lookup(chip) {
                    const ticket = ++this.ticket

                    this.searching = true

                    try {
                        const results = await this.$wire.searchFilterOptions(chip.field, this.find.trim())

                        if (ticket === this.ticket) {
                            this.results = Array.isArray(results) ? results : []
                        }
                    } finally {
                        if (ticket === this.ticket) {
                            this.searching = false
                        }
                    }
                },

                setValue(chip, value) {
                    chip.value = value
                    chip.display = value
                    this.schedulePush()
                },

                remove(chip) {
                    this.chips = this.chips.filter((other) => other.id !== chip.id)
                    this.open = null
                    this.push()
                    this.$nextTick(() => root.querySelector('[data-filter-add]')?.focus())
                },
            }
        })

    if (window.Alpine && window.Alpine.version) {
        register()
    } else {
        document.addEventListener('alpine:init', register)
    }
})()
