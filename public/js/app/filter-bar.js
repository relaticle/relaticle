// The chips hold unfinished filters in the browser only. A rule reaches the server once it is complete,
// and the rules the server sends back replace the chips whenever they differ from what was sent.
;(() => {
    const TYPING_DELAY = 300
    const MAX_FIELD_RESULTS = 200
    const SEARCH_THRESHOLD = 8
    const SHOWN_VALUES = 2
    const VALUE_BUDGET = 28
    const FADE_WIDTH = 32
    const LOOKUP_KINDS = ['member', 'relation', 'record']
    const LIST_KINDS = ['choice', 'multi_choice', ...LOOKUP_KINDS]
    const NUMERIC_KINDS = ['number', 'inactivity']
    const DEFAULT_PERIOD_UNIT = 'days'
    const ME = '@me'

    const strip = (rule) => ({ field: rule.field, condition: rule.condition, ...('value' in rule ? { value: rule.value } : {}) })

    const register = () =>
        window.Alpine.data('filterBar', () => {
            let root = null
            let scroller = null

            return {
                fields: [],
                units: [],
                sorts: [],
                sortable: [],
                sortTarget: null,
                grouping: null,
                groups: [],
                deleted: null,
                deletable: false,
                view: null,
                viewerId: null,
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
                    scroller = this.$refs.strip
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
                    this.sizeObserver.observe(scroller)
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
                    this.units = state.periodUnits
                    this.sorts = state.sorts
                    this.sortable = state.sortable
                    this.grouping = state.grouping
                    this.groups = state.groups
                    this.deleted = state.deleted
                    this.deletable = state.deletable
                    this.view = state.view
                    this.viewerId = state.viewerId
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
                        labels: Object.fromEntries((rule.labels ?? []).map((label) => [label.id, label])),
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

                    if (condition.shape === 'period' || condition.shape === 'unit') {
                        return this.periodComplete(value, condition.shape === 'period')
                    }

                    if (typeof value === 'string') {
                        return value.trim() !== '' && this.numberComplete(chip, value)
                    }

                    return Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined
                },

                numberComplete(chip, value) {
                    const field = this.fieldOf(chip)

                    if (field.kind === 'inactivity') {
                        const days = Number(value)

                        return Number.isInteger(days) && days >= 1 && days <= field.limit
                    }

                    return field.kind !== 'number' || Number.isFinite(Number(value))
                },

                unitOf(name) {
                    return this.units.find((unit) => unit.value === name)
                },

                periodComplete(value, needsAmount) {
                    const unit = this.unitOf(value?.unit)

                    if (!unit) {
                        return false
                    }

                    const amount = Number(value.amount)

                    return !needsAmount || (String(value.amount ?? '').trim() !== '' && Number.isInteger(amount) && amount >= 1 && amount <= unit.max)
                },

                toRule(chip) {
                    const rule = { field: chip.field, condition: chip.condition }
                    const shape = this.conditionOf(chip).shape

                    if (shape === 'none') {
                        return rule
                    }

                    if (shape === 'unit') {
                        return { ...rule, value: { unit: chip.value.unit } }
                    }

                    if (shape === 'period') {
                        return { ...rule, value: { amount: Number(chip.value.amount), unit: chip.value.unit } }
                    }

                    const numeric = NUMERIC_KINDS.includes(this.fieldOf(chip).kind) && typeof chip.value === 'string'

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
                        .filter((field) => field.label.toLowerCase().includes(term))
                        .slice(0, MAX_FIELD_RESULTS)
                },

                canAddDeleted(label) {
                    return this.deletable && !this.deleted && label.toLowerCase().includes(this.find.trim().toLowerCase())
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
                    // The popover that was open stays in the page until Alpine renders, and would take the focus.
                    this.$nextTick(() => this.focusPop())
                },

                opened() {
                    const chip = this.chips.find((other) => other.id === this.open?.id)

                    this.resetLookup()
                    this.pinned = chip ? this.valueIds(chip) : []

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
                    if (!this.open) {
                        return
                    }

                    event.stopPropagation()

                    if (this.isOpen('sort', 'pick') && this.sorts.length > 0) {
                        return this.showSorts(this.sortTarget)
                    }

                    this.close(true)
                },

                outside(event) {
                    if (this.open && !event.composedPath().some((node) => node.matches?.('[data-pop], [data-pop-trigger]'))) {
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
                    const edge = scroller.scrollWidth - scroller.clientWidth - Math.abs(scroller.scrollLeft)

                    scroller.parentElement.toggleAttribute('data-fade-start', scroller.scrollLeft > 1)
                    scroller.parentElement.toggleAttribute('data-fade-end', edge > 1)
                },

                reveal(element) {
                    const bounds = scroller.getBoundingClientRect()
                    const box = element.getBoundingClientRect()

                    if (box.right > bounds.right - FADE_WIDTH) {
                        scroller.scrollLeft += box.right - (bounds.right - FADE_WIDTH)
                    }

                    if (box.left < bounds.left + FADE_WIDTH) {
                        scroller.scrollLeft -= bounds.left + FADE_WIDTH - box.left
                    }
                },

                revealFocus(target) {
                    const chip = target.closest('.fi-filter-chip')
                    const fits = chip && chip.offsetWidth <= scroller.clientWidth - FADE_WIDTH * 2

                    this.reveal(fits ? chip : target)
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


                    this.revealFocus(this.opener ?? pop.parentElement)

                    if (pop.contains(document.activeElement)) {
                        return
                    }

                    const target =
                        pop.querySelector('input') ?? pop.querySelector('[aria-selected="true"], [aria-checked="true"]') ?? pop.querySelector('[data-pop-item]')

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
                        this.pickField(first)
                    }
                },

                pickField(field) {
                    const chip = this.chips.find((other) => other.id === this.open?.id)

                    if (!chip) {
                        return this.addChip(field)
                    }

                    this.changeField(chip, field)
                },

                async addDeleted() {
                    this.close(false)
                    await this.$wire.setDeletedRecordsFilter('included')
                    this.read()
                    this.open = { id: 'deleted', part: 'val' }
                    this.opener = null
                    this.$nextTick(() => {
                        this.opener = root.querySelector('[data-deleted-mode-trigger]')
                        this.focusPop()
                    })
                },

                async setDeleted(mode) {
                    this.close(true)
                    await this.$wire.setDeletedRecordsFilter(mode)
                },

                async removeDeleted() {
                    this.close(false)
                    await this.$wire.setDeletedRecordsFilter(null)
                    this.$nextTick(() => root.querySelector('[data-filter-add]')?.focus())
                },

                sortSummary(empty, one, more, ascending, descending) {
                    const [first] = this.sorts

                    if (!first) {
                        return empty
                    }

                    return (this.sorts.length > 1 ? more : one)
                        .replace(':column', first.label)
                        .replace(':direction', first.direction === 'asc' ? ascending : descending)
                        .replace(':count', this.sorts.length - 1)
                },

                choosingSort() {
                    return this.isOpen('sort', 'pick') || this.sorts.length === 0
                },

                sortChoices() {
                    const term = this.find.trim().toLowerCase()
                    const used = this.sorts.map((sort) => sort.column).filter((column) => column !== this.sortTarget)

                    return this.sortable.filter((column) => !used.includes(column.key) && column.label.toLowerCase().includes(term))
                },

                sortIcon(column) {
                    return this.sortable.find((option) => option.key === column)?.icon ?? ''
                },

                chooseSort(target) {
                    this.open = { id: 'sort', part: 'pick' }
                    this.sortTarget = target
                    this.find = ''
                    this.$nextTick(() => this.focusPop())
                },

                showSorts(focusColumn = null, control = 'field') {
                    this.open = { id: 'sort', part: 'main' }
                    this.sortTarget = null
                    this.find = ''
                    this.focusSort(focusColumn, control)
                },

                focusSort(column, control) {
                    this.$nextTick(() => {
                        const row = column === null ? null : root.querySelector(`[data-sort-row][data-column="${CSS.escape(column)}"]`)

                        ;(row?.querySelector(`[data-sort-${control}]`) ?? root.querySelector('[data-sort-pop] [data-pop-item], [data-sort-pop] input'))?.focus()
                    })
                },

                pickFirstSort() {
                    const first = this.sortChoices()[0]

                    if (first) {
                        this.pickSort(first)
                    }
                },

                pickSort(column) {
                    const sorts = this.sortTarget === null
                        ? [...this.sorts, { column: column.key, direction: 'asc' }]
                        : this.sorts.map((sort) => (sort.column === this.sortTarget ? { ...sort, column: column.key } : sort))

                    this.applySorts(sorts)
                    this.showSorts(column.key)
                },

                flipSortAt(column) {
                    this.applySorts(this.sorts.map((sort) => (sort.column === column ? { ...sort, direction: sort.direction === 'asc' ? 'desc' : 'asc' } : sort)))
                },

                removeSort(column) {
                    const index = this.sorts.findIndex((sort) => sort.column === column)
                    const sorts = this.sorts.filter((sort) => sort.column !== column)
                    const next = sorts[Math.min(index, sorts.length - 1)]

                    this.applySorts(sorts)
                    this.focusSort(next?.column ?? null, 'remove')
                },

                moveSort(column, delta) {
                    const index = this.sorts.findIndex((sort) => sort.column === column)

                    if (index + delta < 0 || index + delta >= this.sorts.length) {
                        return
                    }

                    this.reorderSort(column, index + delta)
                    this.focusSort(column, 'grip')
                },

                reorderSort(column, position) {
                    const moved = this.sorts.find((sort) => sort.column === column)
                    const sorts = this.sorts.filter((sort) => sort.column !== column)

                    sorts.splice(position, 0, moved)
                    this.applySorts(sorts)
                },

                async applySorts(sorts) {
                    this.sorts = sorts.map((sort) => ({ ...sort, label: this.sortable.find((option) => option.key === sort.column)?.label ?? sort.column }))

                    await this.$wire.setFilterBarSorts(sorts.map(({ column, direction }) => ({ column, direction })))
                },

                async groupBy(group) {
                    const flipped = this.grouping?.id === group.id && this.grouping.direction === 'asc' ? 'desc' : 'asc'

                    await this.$wire.setFilterBarGrouping(group.id, flipped)
                },

                async flipGrouping() {
                    await this.$wire.setFilterBarGrouping(this.grouping.id, this.grouping.direction === 'asc' ? 'desc' : 'asc')
                },

                async clearGrouping() {
                    this.close(true)
                    await this.$wire.setFilterBarGrouping(null, null)
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

                changeField(chip, field) {
                    if (chip.field === field.key) {
                        return this.close(true)
                    }

                    Object.assign(chip, { field: field.key, condition: null, value: null, display: '', labels: {} })
                    this.resetLookup()
                    this.open = { id: chip.id, part: 'cond' }
                    this.find = ''
                    this.opener = null
                    this.$nextTick(() => {
                        this.opener = this.segment(chip, 'condition')
                        this.focusPop()
                    })
                    this.push()
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

                    return option?.label ?? chip.labels[id]?.label ?? id
                },

                valueIds(chip) {
                    const ids = Array.isArray(chip.value) ? chip.value : []

                    if (this.fieldOf(chip)?.kind !== 'member') {
                        return ids
                    }

                    return [...new Set(ids.map((id) => (id === this.viewerId ? ME : id)))]
                },

                shownValues(chip) {
                    const shown = []
                    let used = 0

                    for (const id of this.valueIds(chip)) {
                        const length = this.labelFor(chip, id).length

                        if (shown.length === SHOWN_VALUES || (shown.length > 0 && used + length > VALUE_BUDGET)) {
                            break
                        }

                        shown.push(id)
                        used += length
                    }

                    return shown
                },

                markedValues(chip) {
                    if (!LOOKUP_KINDS.includes(this.fieldOf(chip)?.kind) || !Array.isArray(chip.value) || !this.complete(chip)) {
                        return []
                    }

                    const options = this.fieldOf(chip).options

                    return this.shownValues(chip).map((id) => ({
                        ...chip.labels[id],
                        ...options.find((option) => option.id === id),
                        id,
                        label: this.labelFor(chip, id),
                    }))
                },

                hiddenValues(chip) {
                    return this.valueIds(chip).length - this.shownValues(chip).length
                },

                isChecked(chip, id) {
                    return this.valueIds(chip).includes(id)
                },

                optionColor(option) {
                    const color = option.color ?? ''

                    return /^(#[0-9a-f]{3,8}|oklch\([0-9. %]+\))$/i.test(color) ? color : null
                },

                listItems(chip) {
                    const field = this.fieldOf(chip)
                    const term = this.find.trim().toLowerCase()
                    const known = (id) => field.options.find((option) => option.id === id)
                    const pinned = this.pinned.map((id) => ({ color: null, ...chip.labels[id], ...known(id), id, label: this.labelFor(chip, id) }))
                    const pool = this.isLookup(chip)
                        ? [...field.options.filter((option) => option.label.toLowerCase().includes(term)), ...this.results]
                        : field.options.filter((option) => option.label.toLowerCase().includes(term))
                    const seen = new Set(pinned.map((item) => item.id))

                    return [...pinned, ...pool.filter((option) => !seen.has(option.id) && seen.add(option.id))].sort(
                        (a, b) => Number(b.id === ME) - Number(a.id === ME),
                    )
                },

                toggleOption(chip, option) {
                    const current = Array.isArray(chip.value) ? chip.value : []
                    const stored = option.id === ME && this.fieldOf(chip).kind === 'member' ? [ME, this.viewerId] : [option.id]

                    chip.labels[option.id] = option
                    chip.value = stored.some((id) => current.includes(id)) ? current.filter((id) => !stored.includes(id)) : [...current, option.id]
                    chip.display = this.summary(chip)
                    this.schedulePush()
                },

                summary(chip) {
                    const labels = this.valueIds(chip).map((id) => this.labelFor(chip, id))
                    const shown = labels.slice(0, SHOWN_VALUES).join(', ')

                    return labels.length > SHOWN_VALUES ? `${shown} +${labels.length - SHOWN_VALUES}` : shown
                },

                typed(chip) {
                    this.resetLookup()
                    this.pinned = this.valueIds(chip)

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

                isDateEditor(chip) {
                    return this.fieldOf(chip)?.kind === 'date' && this.conditionOf(chip)?.shape === 'text'
                },

                isPeriodEditor(chip) {
                    return this.conditionOf(chip)?.shape === 'period'
                },

                isUnitEditor(chip) {
                    return this.conditionOf(chip)?.shape === 'unit'
                },

                inputType(chip) {
                    return NUMERIC_KINDS.includes(this.fieldOf(chip).kind) ? 'number' : 'text'
                },

                dateText(value) {
                    const [year, month, day] = value.split('-').map(Number)
                    const date = new Date(year, month - 1, day)

                    return Number.isNaN(date.getTime())
                        ? value
                        : new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium' }).format(date)
                },

                setDate(chip, value) {
                    chip.value = value
                    chip.display = value ? this.dateText(value) : ''
                    this.schedulePush()
                },

                setToday(chip, label) {
                    chip.value = '@today'
                    chip.display = label
                    this.push()
                    this.close(true)
                },

                periodText(value) {
                    const unit = this.unitOf(value.unit)

                    return `${value.amount} ${Number(value.amount) === 1 ? unit.singular : unit.label}`
                },

                periodUnit(chip) {
                    return chip.value?.unit ?? DEFAULT_PERIOD_UNIT
                },

                setPeriod(chip, change) {
                    chip.value = { amount: '', unit: DEFAULT_PERIOD_UNIT, ...chip.value, ...change }
                    chip.display = this.complete(chip) ? this.periodText(chip.value) : ''
                    this.schedulePush()
                },

                pickUnit(chip, unit) {
                    chip.value = { unit: unit.value }
                    chip.display = unit.singular
                    this.push()
                    this.close(true)
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
