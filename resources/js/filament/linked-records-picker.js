// The picker behind `LinkedRecordsSelect`. Closed, it is one row of record links with a count
// of the ones that do not fit. Open, focus stays in the search box and the list follows it.
const picker = (config) => ({
    state: config.state,
    messages: config.messages,
    known: {},
    groups: [],
    recent: null,
    query: '',
    open: false,
    loading: false,
    failed: false,
    activeValue: null,
    visibleCount: Number.MAX_SAFE_INTEGER,
    request: 0,
    resizes: null,

    init() {
        this.remember(config.selected)

        // Measuring inside the observer callback changes layout in the frame being observed.
        this.resizes = new ResizeObserver(() => requestAnimationFrame(() => this.measure()))
        this.resizes.observe(this.$refs.chips)

        this.$watch('state', () => this.$nextTick(() => this.measure()))
        this.$nextTick(() => this.measure())
    },

    destroy() {
        this.resizes?.disconnect()
    },

    get values() {
        return Array.isArray(this.state) ? this.state : []
    },

    get selectedOptions() {
        return this.values.map((value) => this.known[value]).filter(Boolean)
    },

    get hiddenCount() {
        return Math.max(this.selectedOptions.length - this.visibleCount, 0)
    },

    // A linked record already shows as a chip above the list, so the list offers only the rest.
    get visibleGroups() {
        return this.groups
            .map((group) => ({ ...group, options: group.options.filter((option) => !this.isSelected(option.value)) }))
            .filter((group) => group.options.length > 0)
    },

    get visibleValues() {
        return this.visibleGroups.flatMap((group) => group.options.map((option) => option.value))
    },

    get resultCount() {
        return this.visibleValues.length
    },

    get message() {
        if (this.failed) {
            return this.messages.failed
        }

        if (this.resultCount > 0) {
            return ''
        }

        if (this.loading) {
            return this.messages.searching
        }

        const searching = this.query.trim() !== ''

        if (this.groups.length > 0) {
            return searching ? this.messages.linked : this.messages.more
        }

        return searching ? this.messages.empty : this.messages.none
    },

    get announcement() {
        if (!this.open) {
            return ''
        }

        if (this.message !== '') {
            return this.message
        }

        return this.resultCount === 1
            ? this.messages.result
            : this.messages.results.replace(':count', this.resultCount)
    },

    remember(options) {
        for (const option of options ?? []) {
            this.known[option.value] = option
        }
    },

    isSelected(value) {
        return this.values.includes(value)
    },

    optionId(value) {
        return value === null ? null : `${config.id}-${value.replace(':', '-')}`
    },

    measure() {
        const row = this.$refs.chips
        const chips = [...row.querySelectorAll(':scope > .fi-linked-records-chip')]

        if (row.clientWidth === 0 || chips.length === 0) {
            this.visibleCount = Number.MAX_SAFE_INTEGER

            return
        }

        const gap = parseFloat(getComputedStyle(row).columnGap) || 0
        const widths = chips.map((chip) => chip.offsetWidth)
        const total = widths.reduce((sum, width) => sum + width, 0) + gap * (chips.length - 1)

        if (total <= row.clientWidth) {
            this.visibleCount = chips.length

            return
        }

        const reserved = this.$refs.count.offsetWidth + gap
        let used = 0
        let visible = 0

        for (const width of widths) {
            if (visible > 0 && used + width + reserved > row.clientWidth) {
                break
            }

            used += width + gap
            visible++
        }

        this.visibleCount = visible
    },

    openPanel() {
        if (config.isDisabled || (this.open && this.$refs.panel._x_isShown)) {
            return
        }

        this.open = true
        this.groups = this.recent ?? []
        this.$refs.panel.open(this.$refs.anchor)
        this.focusSearch()
        this.warm()
    },

    // Pointing at the field or tabbing to it fetches the recent records, so the panel opens filled.
    warm() {
        if (this.recent === null && !this.loading) {
            this.load('')
        }
    },

    closePanel(refocus = false) {
        if (!this.open) {
            return
        }

        this.open = false
        this.request++
        this.loading = false
        this.failed = false
        this.query = ''
        this.activeValue = null
        this.$refs.panel.close()

        if (refocus) {
            this.$refs.trigger.focus({ preventScroll: true })
        }
    },

    // The floating panel becomes visible a tick after `open()`, and a hidden input cannot take focus.
    focusSearch() {
        if (!this.open) {
            return
        }

        if (!this.$refs.panel._x_isShown) {
            requestAnimationFrame(() => this.focusSearch())

            return
        }

        this.$refs.search.focus({ preventScroll: true })
    },

    // Until the results for the new query arrive, Enter must not pick a row from the old list.
    pending() {
        this.activeValue = null
        this.loading = true
    },

    search() {
        const search = this.query.trim()

        search === '' ? this.showRecent() : this.load(search)
    },

    showRecent() {
        if (this.recent === null) {
            this.load('')

            return
        }

        this.request++
        this.loading = false
        this.failed = false
        this.groups = this.recent
        this.activeValue = null
    },

    async load(search) {
        const request = ++this.request

        this.loading = true
        this.failed = false

        try {
            const groups = (await config.fetch(search)) ?? []

            if (request !== this.request) {
                return
            }

            groups.forEach((group) => this.remember(group.options))

            if (search === '') {
                this.recent = groups
            }

            this.groups = groups
            this.activeValue = search === '' ? null : (this.visibleValues[0] ?? null)
        } catch (error) {
            if (request !== this.request) {
                return
            }

            this.groups = []
            this.failed = true
        } finally {
            if (request === this.request) {
                this.loading = false
            }
        }
    },

    link(option) {
        const values = this.visibleValues
        const index = values.indexOf(option.value)

        this.state = [...this.values, option.value]

        if (this.query === '') {
            this.activeValue = values[index + 1] ?? values[index - 1] ?? null
        } else {
            this.query = ''
            this.showRecent()
        }

        this.$refs.search.focus({ preventScroll: true })
    },

    remove(value) {
        this.state = this.values.filter((selected) => selected !== value)
        this.$refs.search.focus({ preventScroll: true })
    },

    removeLast() {
        if (this.query === '' && this.values.length > 0) {
            this.state = this.values.slice(0, -1)
        }
    },

    choose() {
        const option = this.known[this.activeValue]

        if (option && !this.isSelected(option.value)) {
            this.link(option)
        }
    },

    move(step) {
        const values = this.visibleValues

        if (values.length === 0) {
            return
        }

        const index = values.indexOf(this.activeValue)

        this.activeValue =
            index === -1
                ? values[step > 0 ? 0 : values.length - 1]
                : values[(index + step + values.length) % values.length]

        this.$nextTick(() => {
            document.getElementById(this.optionId(this.activeValue))?.scrollIntoView({ block: 'nearest' })
        })
    },
})

const register = () => window.Alpine.data('linkedRecordsPicker', picker)

if (window.Alpine) {
    register()
} else {
    document.addEventListener('alpine:init', register)
}
