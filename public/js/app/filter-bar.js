// The chips hold unfinished filters in the browser only. A rule reaches the server once it is complete,
// and the rules the server sends back replace the chips whenever they differ from what was sent.
;(() => {
    const TYPING_DELAY = 300
    const MAX_FIELD_RESULTS = 200

    const strip = (rule) => ({ field: rule.field, condition: rule.condition, ...('value' in rule ? { value: rule.value } : {}) })

    const register = () =>
        window.Alpine.data('filterBar', () => {
            let root = null

            return {
                fields: [],
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

                init() {
                    root = this.$root
                    this.read()
                    this.chips = this.server.map((rule) => this.fromRule(rule))
                    this.sent = this.serialize(this.completeRules())
                    this.observer = new MutationObserver(() => {
                        this.read()
                        this.reconcile()
                    })
                    this.observer.observe(root, { attributes: true, attributeFilter: ['data-state'] })
                },

                destroy() {
                    this.observer?.disconnect()
                    clearTimeout(this.timer)
                },

                read() {
                    const state = JSON.parse(root.dataset.state)

                    this.fields = state.fields
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
                    this.focusPop()
                },

                close(restoreFocus = true) {
                    const opener = this.opener

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

                    if (pop.contains(document.activeElement)) {
                        return
                    }

                    const target =
                        pop.querySelector('input') ?? pop.querySelector('[aria-selected="true"]') ?? pop.querySelector('[data-pop-item]')

                    target?.focus()
                },

                items(pop) {
                    return [...pop.querySelectorAll('input[data-filter-find], [data-pop-item]')].filter((item) => item.checkVisibility())
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
                    const chip = { id: this.nextId++, field: field.key, condition: null, value: null, display: '' }

                    this.chips.push(chip)
                    this.open = { id: chip.id, part: 'cond' }
                    this.find = ''
                    this.opener = null
                    this.$nextTick(() => {
                        this.opener = this.segment(chip, 'condition')
                        this.focusPop()
                    })
                },

                pickCondition(chip, condition) {
                    chip.condition = condition.value

                    if (!condition.takesValue) {
                        chip.value = null
                        chip.display = ''
                    }

                    const needsValue = condition.takesValue && !this.complete(chip)

                    this.open = needsValue ? { id: chip.id, part: 'val' } : null

                    if (needsValue) {
                        this.$nextTick(() => {
                            this.opener = this.segment(chip, 'value')
                            this.focusPop()
                        })
                    } else {
                        this.$nextTick(() => this.segment(chip, 'condition')?.focus())
                    }

                    this.push()
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
