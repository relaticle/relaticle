// Each header rendered by ResizableColumns::apply() runs one `resizableColumn` instance,
// which owns its drag handle.

// Every data column carries an exact width. Under `table-layout: fixed` the actions column,
// which has only a floor, takes whatever space is left so the table still fills its wrapper.
const SELECTION_CELL_WIDTH = 52
// Only fits an ActionGroup trigger: bare row actions would overflow into the next column.
const ACTIONS_CELL_WIDTH = 64

let dragGuideline = null
let dragBadge = null

// The guideline lives inside the scroll wrapper, so a horizontal scroll mid-drag keeps it on
// the column edge. The badge is fixed and follows the cursor.
function ensureDragGuideline(wrapper) {
    if (!dragGuideline) {
        dragGuideline = document.createElement('div')
        dragGuideline.className = 'column-resize-guideline'
    }
    if (dragGuideline.parentElement !== wrapper) {
        wrapper.appendChild(dragGuideline)
    }
    return dragGuideline
}

// `wire:navigate` swaps the whole body, which detaches a badge created on an earlier page.
function ensureDragBadge() {
    if (!dragBadge?.isConnected) {
        dragBadge = document.createElement('div')
        dragBadge.className = 'column-resize-badge'
        document.body.appendChild(dragBadge)
    }
    return dragBadge
}

function showDragOverlay(wrapper, contentX, height, width, cursorX, cursorY) {
    const guideline = ensureDragGuideline(wrapper)
    Object.assign(guideline.style, { display: 'block', left: `${contentX}px`, height: `${height}px` })
    const badge = ensureDragBadge()
    Object.assign(badge.style, { display: 'block', left: `${cursorX}px`, top: `${cursorY}px` })
    badge.textContent = `${width}px`
}

function moveDragOverlay(contentX, width, cursorX, cursorY) {
    if (dragGuideline) {
        dragGuideline.style.left = `${contentX}px`
    }
    if (dragBadge) {
        dragBadge.style.left = `${cursorX}px`
        dragBadge.style.top = `${cursorY}px`
        dragBadge.textContent = `${width}px`
    }
}

function hideDragOverlay() {
    if (dragGuideline) dragGuideline.style.display = 'none'
    if (dragBadge) dragBadge.style.display = 'none'
}

// `.fi-ta-content-ctn` scrolls horizontally, which defeats `position: sticky` on the header,
// and a scroll listener lags the compositor. A scroll-driven animation pins it instead.
const stickyHeadersSupported =
    typeof CSS !== 'undefined' && CSS.supports('animation-timeline', 'scroll()')

let stickyHeadersWired = false
let stickyHeadersStyleElement = null

const PAGE_SCROLL_TIMELINE = '--resizable-page-scroll'

// The panel scrolls inside `.fi-layout` (html and body are overflow hidden), not the document.
function pageScroller(table) {
    let node = table.closest('.fi-ta-content-ctn')?.parentElement
    while (node && node !== document.body) {
        if (/auto|scroll/.test(getComputedStyle(node).overflowY)) return node
        node = node.parentElement
    }

    return null
}

function pinLine(scrollerTop) {
    const topbar = document.querySelector('.fi-topbar-ctn')
    const topbarBottom = topbar && ['sticky', 'fixed'].includes(getComputedStyle(topbar).position)
        ? topbar.getBoundingClientRect().bottom
        : 0

    return Math.max(scrollerTop, topbarBottom)
}

function stickyHeaderStyleElement() {
    if (!stickyHeadersStyleElement) {
        stickyHeadersStyleElement = document.createElement('style')
        stickyHeadersStyleElement.id = 'resizable-sticky-headers'
        document.head.appendChild(stickyHeadersStyleElement)
    }

    return stickyHeadersStyleElement
}

// Keyframes cannot interpolate CSS variables, so each table's pixel range is written out.
function measureStickyHeaders() {
    if (!stickyHeadersSupported) return

    let css = ''

    document.querySelectorAll('table.fi-ta-resizable-table > thead').forEach((thead, index) => {
        const table = thead.parentElement
        const maxShift = Math.max(0, table.offsetHeight - thead.offsetHeight)

        if (maxShift === 0) {
            thead.removeAttribute('data-sticky-header')

            return
        }

        const scroller = pageScroller(table)
        const scrollerTop = scroller ? scroller.getBoundingClientRect().top : 0
        const scrollTop = scroller ? scroller.scrollTop : window.scrollY
        const tableTop = table.getBoundingClientRect().top - scrollerTop + scrollTop
        const start = Math.max(0, tableTop - (pinLine(scrollerTop) - scrollerTop))

        if (scroller) {
            scroller.style.scrollTimeline = `${PAGE_SCROLL_TIMELINE} block`
        }

        thead.dataset.stickyHeader = String(index)
        css +=
            `@keyframes pin-header-${index}{from{transform:translateY(0)}to{transform:translateY(${maxShift}px)}}` +
            `[data-sticky-header="${index}"]{` +
            'will-change:transform;' +
            `animation:pin-header-${index} linear both;` +
            `animation-timeline:${scroller ? PAGE_SCROLL_TIMELINE : 'scroll(root block)'};` +
            `animation-range:${start}px ${start + maxShift}px}`
    })

    stickyHeaderStyleElement().textContent = css
}

let stickyRecalcPending = false

function scheduleStickyRecalc() {
    if (!stickyHeadersSupported) return
    if (stickyRecalcPending) return

    stickyRecalcPending = true
    requestAnimationFrame(() => {
        stickyRecalcPending = false
        measureStickyHeaders()
    })
}

function wireStickyHeaders() {
    if (stickyHeadersWired) return
    stickyHeadersWired = true

    if (!stickyHeadersSupported) return

    window.addEventListener('resize', scheduleStickyRecalc, { passive: true })
    document.addEventListener('livewire:navigated', scheduleStickyRecalc)

    measureStickyHeaders()
}

function computeMinTableWidth(table) {
    let total = 0
    table.querySelectorAll('thead th').forEach((th) => {
        const saved = th.dataset.resizableWidth
        if (saved) { total += parseInt(saved, 10); return }
        if (th.classList.contains('fi-ta-selection-cell')) { total += SELECTION_CELL_WIDTH; return }
        if (th.classList.contains('fi-ta-actions-header-cell')) { total += ACTIONS_CELL_WIDTH; return }
        total += Math.round(th.offsetWidth)
    })
    return total
}

function applyTableSizing(table) {
    table.style.tableLayout = 'fixed'

    const wrapper = table.closest('.fi-ta-content-ctn')
    const wrapperWidth = wrapper?.clientWidth ?? 0
    const columnsWidth = computeMinTableWidth(table)

    table.style.width = columnsWidth > wrapperWidth ? `${columnsWidth}px` : '100%'
    table.style.minWidth = ''
}

const widthRecalcPending = new WeakSet()

function scheduleSizingUpdate(table) {
    if (widthRecalcPending.has(table)) return
    widthRecalcPending.add(table)
    requestAnimationFrame(() => {
        widthRecalcPending.delete(table)
        if (table.isConnected) {
            applyTableSizing(table)
            applyPinnedOffsets(table)
            scheduleStickyRecalc()
        }
    })
}

// Observing the wrappers catches sidebar collapses and window resizes without watching rows.
const wrapperResizeObserver = typeof ResizeObserver === 'undefined'
    ? null
    : new ResizeObserver((entries) => {
        entries.forEach((entry) => {
            const table = entry.target.querySelector('table.fi-ta-resizable-table')
            if (table) scheduleSizingUpdate(table)
        })
    })

// A Livewire morph resets the wrapper's horizontal scroll to 0. The position is kept off the
// DOM, which the morph would strip, so a sort or search does not jump back to the left edge.
const wrapperScrollLeft = new WeakMap()
const scrollTrackedWrappers = new WeakSet()

function trackWrapperScroll(wrapper) {
    if (scrollTrackedWrappers.has(wrapper)) return
    scrollTrackedWrappers.add(wrapper)
    wrapper.addEventListener('scroll', () => {
        wrapperScrollLeft.set(wrapper, wrapper.scrollLeft)
        markScrolledSideways(wrapper.querySelector('table.fi-ta-table'))
    }, { passive: true })
}

function markScrolledSideways(table) {
    table?.toggleAttribute('data-scrolled-x', (table.closest('.fi-ta-content-ctn')?.scrollLeft ?? 0) > 0)
}

// Each pinned cell sticks at the selection column's width plus the pinned widths before it.
function applyPinnedOffsets(table) {
    const pinned = headersOf(table).filter((th) => th.dataset.columnPinned === '1')
    const selection = table.querySelector('thead .fi-ta-selection-cell')
    let left = selection?.offsetWidth ?? 0
    const offsets = pinned.map((th) => {
        const offset = left
        left += parseInt(th.dataset.resizableWidth, 10) || th.offsetWidth
        return offset
    })
    const active = pinned.length > 0

    table.classList.toggle('fi-ta-has-pinned', active)
    table.querySelectorAll('[data-column-pinned-last]').forEach((cell) => cell.removeAttribute('data-column-pinned-last'))
    pinned.forEach((th, index) => {
        [th, ...table.querySelectorAll(`.fi-ta-cell-${CSS.escape(th.dataset.resizableColumnId)}`)].forEach((cell) => {
            cell.style.left = active ? `${offsets[index]}px` : ''
            cell.toggleAttribute('data-column-pinned-last', active && index === pinned.length - 1)
        })
    })
    markScrolledSideways(table)
    keepPinsInColumnManager(table, pinned.map(headerName))
}

// Filament's column manager has no per-item lock, so its sortable list leaves the pins where they are.
function keepPinsInColumnManager(table, pinned) {
    const list = table.closest('.fi-ta')?.querySelector('.fi-ta-col-manager-items')
    if (!list?.sortable) return

    const isPinned = (item) => pinned.includes(item?.getAttribute('x-sortable-item')?.replace(/^column::/, ''))

    list.sortable.option('onMove', (event) => !isPinned(event.related))
    list.querySelectorAll(':scope > [x-sortable-item]').forEach((item) => item.toggleAttribute('data-column-pinned', isPinned(item)))
}

function lockTable(table) {
    if (table.dataset.resizableLayoutLocked === '1') return

    table.classList.add('fi-ta-resizable-table')
    applyTableSizing(table)

    const wrapper = table.closest('.fi-ta-content-ctn')
    if (wrapper) {
        trackWrapperScroll(wrapper)
        if (getComputedStyle(wrapper).position === 'static') {
            wrapper.style.position = 'relative'
        }
        if (wrapperResizeObserver && !wrapper.dataset.resizableWrapperObserved) {
            wrapperResizeObserver.observe(wrapper)
            wrapper.dataset.resizableWrapperObserved = '1'
        }
    }

    table.dataset.resizableLayoutLocked = '1'
    applyPinnedOffsets(table)
    scheduleStickyRecalc()
}

let morphHookRegistered = false

// A morph keeps the server-rendered widths but strips the table class, the lock flag, the
// label wrappers and the handles, so every resizable table is rebuilt from its headers.
function rebuildResizableTablesAfterMorph({ el } = {}) {
    const tables = new Set()
    document.querySelectorAll('thead th[x-data^="resizableColumn"]').forEach((th) => {
        const table = th.closest('table.fi-ta-table')
        if (table) tables.add(table)
    })

    tables.forEach((table) => {
        delete table.dataset.resizableLayoutLocked
        lockTable(table)
        table.querySelectorAll('thead th[x-data^="resizableColumn"]').forEach(reapplyHeader)
        applyPinnedOffsets(table)

        // Other components morph too, and only the table's own morph has rebuilt its headers.
        if (el?.contains(table)) restoreColumnFocus(table)

        const wrapper = table.closest('.fi-ta-content-ctn')
        const savedScrollLeft = wrapper && wrapperScrollLeft.get(wrapper)
        if (wrapper && savedScrollLeft && wrapper.scrollLeft !== savedScrollLeft) {
            wrapper.scrollLeft = savedScrollLeft
        }
    })

    scheduleStickyRecalc()
}

// `morphed` fires once per component after the morph settles. `morph.updated` fires per node
// mid-morph, and the rest of the same pass strips what it adds.
function ensureGlobalMorphHook() {
    if (morphHookRegistered) return
    if (!window.Livewire?.hook) return

    window.Livewire.hook('morphed', rebuildResizableTablesAfterMorph)

    morphHookRegistered = true
}

// A block laid out at `max-content` takes the width its content asks for. All writes come before
// any read, because a read between writes forces a full table layout per element.
function naturalWidths(elements) {
    const saved = elements.map((element) => element && { width: element.style.width, maxWidth: element.style.maxWidth })
    elements.forEach((element) => element && Object.assign(element.style, { width: 'max-content', maxWidth: 'none' }))
    const widths = elements.map((element) => (element ? element.getBoundingClientRect().width : 0))
    elements.forEach((element, index) => element && Object.assign(element.style, saved[index]))

    return widths
}

// The cells are border-box, so the width a column is given must also cover its 1px divider.
function horizontalInset(element) {
    const style = getComputedStyle(element)

    return ['paddingLeft', 'paddingRight', 'borderLeftWidth', 'borderRightWidth']
        .reduce((total, property) => total + parseFloat(style[property]), 0)
}

function createHandleBar() {
    const handle = document.createElement('button')
    handle.type = 'button'
    // Mouse-only for now, so it stays out of the tab order.
    handle.tabIndex = -1
    handle.classList.add('column-resize-handle-bar')
    handle.setAttribute('aria-label', window.filamentData?.resizeColumnLabel ?? '')
    handle.title = window.filamentData?.resizeColumnHint ?? ''

    return handle
}

function reapplyHeader(th) {
    if (!th.querySelector(':scope > .fi-ta-resizable-label')) {
        const wrapper = document.createElement('span')
        wrapper.className = 'fi-ta-resizable-label'
        Array.from(th.childNodes).forEach((node) => {
            if (node.nodeType === 1 && (node.classList?.contains('column-resize-handle-bar') || node.classList?.contains('fi-ta-column-handle'))) return
            wrapper.appendChild(node)
        })
        th.insertBefore(wrapper, th.firstChild)
    }
    if (!th.querySelector('.column-resize-handle-bar')) {
        window.Alpine?.$data?.(th)?.createHandle?.()
    }
    if (!th.querySelector('.fi-ta-column-handle')) {
        window.Alpine?.$data?.(th)?.createColumnControls?.()
    }
}

// A move re-renders the headers, so the control that started it is focused again afterwards.
let pendingColumnFocus = null

function rememberColumnFocus(column) {
    pendingColumnFocus = column
}

function forgetColumnFocus() {
    pendingColumnFocus = null
}

function restoreColumnFocus(table) {
    if (!pendingColumnFocus) return
    const handle = columnHandle(table, pendingColumnFocus)
    pendingColumnFocus = null
    handle?.focus()
}

// A morph strips and rebuilds the header handles, so a handle is found again by its column.
function columnHandle(table, column) {
    const th = headersOf(table).find((header) => headerName(header) === column)

    return th?.querySelector('.fi-ta-column-handle') ?? null
}

function headersOf(table) {
    return [...table.querySelectorAll('thead th[x-data^="resizableColumn"]')]
}

function headerName(th) {
    return th ? window.Alpine?.$data?.(th)?.columnName ?? null : null
}

// The pinned columns stay put, so a column moves only among the unpinned ones.
function isMovable(th) {
    return th !== undefined && th.dataset.columnPinned !== '1'
}

function moveTargets(th) {
    const headers = headersOf(th.closest('table'))
    const index = headers.indexOf(th)
    const left = headers[index - 1]
    const canSwapWith = (other) => isMovable(th) && isMovable(other)

    return {
        movable: isMovable(th),
        canMoveLeft: canSwapWith(left),
        canMoveRight: canSwapWith(headers[index + 1]),
        before: {
            left: left ? headerName(left) : null,
            right: headers[index + 2] ? headerName(headers[index + 2]) : null,
        },
        // Where focus goes when this column is hidden.
        neighbour: headerName(left ?? headers[index + 1]),
    }
}

// A header press only turns into a drag past this distance, so a click still sorts.
const DRAG_THRESHOLD = 4
// Within this distance of the scroll wrapper's edge, a drag scrolls the table sideways.
const AUTO_SCROLL_EDGE = 48
const AUTO_SCROLL_MAX_SPEED = 18

// A copy of the real column follows the pointer: its header and every cell, each at its own height and
// offset, so group rows keep their gaps. It ends where the table's visible area does.
function createDragGhost(th, cells) {
    const top = th.getBoundingClientRect().top
    const visibleBottom = Math.min(window.innerHeight, th.closest('.fi-ta-content-ctn')?.getBoundingClientRect().bottom ?? window.innerHeight)
    const ghost = document.createElement('div')
    ghost.className = 'fi-ta-column-drag-ghost'
    // The copied cells carry Livewire and Alpine directives that must not start up again.
    ghost.setAttribute('x-ignore', '')
    // A copy for the eye only: screen readers and the keyboard skip it.
    ghost.inert = true
    const { font, color } = getComputedStyle(th.closest('table'))
    Object.assign(ghost.style, { width: `${th.offsetWidth}px`, height: `${Math.min(visibleBottom, (cells.at(-1) ?? th).getBoundingClientRect().bottom) - top}px`, font, color })
    ghost.append(...[...cells, th].map((cell) => copyColumnCell(cell, cell.getBoundingClientRect().top - top)))
    ghost.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'))
    document.body.appendChild(ghost)

    return ghost
}

function copyColumnCell(cell, top) {
    const copy = document.createElement('div')
    copy.className = `${cell.className} fi-ta-column-drag-ghost-cell`
    Object.assign(copy.style, { top: `${top}px`, height: `${cell.offsetHeight}px`, padding: getComputedStyle(cell).padding })
    copy.append(...[...cell.children].filter((child) => !child.matches('.fi-ta-column-handle, .column-resize-handle-bar')).map((child) => child.cloneNode(true)))

    return copy
}

// Slots keep the layout from before the drag, so the shifted columns never feed back into the target.
function columnSlots(table) {
    return headersOf(table).map((th) => ({
        th,
        left: th.offsetLeft,
        width: th.offsetWidth,
        elements: [th, ...table.querySelectorAll(`.fi-ta-cell-${CSS.escape(th.dataset.resizableColumnId)}`)],
    }))
}

// A neighbour swaps once the dragged column's leading edge passes its middle, the same distance either way.
// The pinned columns keep their places.
function dropIndex(slots, source, left) {
    const right = left + source.width
    const start = slots.indexOf(source)
    const middle = (slot) => slot.left + slot.width / 2
    const before = slots.slice(0, start).filter((slot) => middle(slot) < left).length
    const after = slots.slice(start + 1).filter((slot) => middle(slot) < right).length
    const pinned = slots.filter((slot) => slot.th.dataset.columnPinned === '1').length

    return Math.max(before + after, pinned)
}

function shiftColumns(slots, source, index) {
    const order = slots.filter((slot) => slot !== source)
    order.splice(index, 0, source)

    let left = slots[0].left
    order.forEach((slot) => {
        const shift = left - slot.left
        slot.elements.forEach((element) => {
            element.style.transform = shift ? `translateX(${shift}px)` : ''
        })
        left += slot.width
    })
}

function moveColumnCells(source, target, last) {
    source.elements.forEach((element) => {
        const row = element.parentElement
        const anchor = (target ?? last).elements.find((cell) => cell.parentElement === row)
        if (!anchor) return
        target ? row.insertBefore(element, anchor) : anchor.after(element)
    })
}

function clearShift(slots) {
    slots.forEach((slot) => slot.elements.forEach((element) => {
        element.style.transform = ''
    }))
}

// A press on a movable header drags it, except on its controls. Touch keeps swiping the table.
function startHeaderDrag(event, th, wire) {
    if (event.button !== 0 || event.pointerType === 'touch' || !isMovable(th)) return
    if (event.target.closest('.fi-ta-column-handle, .column-resize-handle-bar')) return

    startColumnDrag(event, th, wire)
}

// The press that ends a drag still clicks what it lands on, and a header click would sort.
function swallowNextClick() {
    const swallow = (clickEvent) => {
        clickEvent.preventDefault()
        clickEvent.stopPropagation()
    }
    window.addEventListener('click', swallow, { capture: true, once: true })
    setTimeout(() => window.removeEventListener('click', swallow, true))
}

function startColumnDrag(event, th, wire) {

    const table = th.closest('table')
    const wrapper = table.closest('.fi-ta-content-ctn')
    const slots = columnSlots(table)
    const source = slots.find((slot) => slot.th === th)
    const others = slots.filter((slot) => slot !== source)
    const startIndex = slots.indexOf(source)
    const grabOffset = event.clientX - th.getBoundingClientRect().left
    const startX = event.clientX
    let ghost = null
    let index = startIndex
    let pointer = { x: event.clientX, y: event.clientY }
    let scrollFrame = null
    let moveFrame = null

    const update = () => {
        Object.assign(ghost.style, { left: `${pointer.x - grabOffset}px`, top: `${table.tHead.getBoundingClientRect().top}px` })
        index = dropIndex(slots, source, pointer.x - grabOffset - table.getBoundingClientRect().left)
        shiftColumns(slots, source, index)
    }

    // The pointer reports more often than the screen paints, so the moves in a frame shift the columns once.
    const scheduleUpdate = () => {
        moveFrame ??= requestAnimationFrame(() => {
            moveFrame = null
            update()
        })
    }

    const autoScroll = () => {
        scrollFrame = null
        if (!ghost || !wrapper) return

        const box = wrapper.getBoundingClientRect()
        const towardsLeft = AUTO_SCROLL_EDGE - (pointer.x - box.left)
        const towardsRight = AUTO_SCROLL_EDGE - (box.right - pointer.x)
        const pressure = Math.max(towardsLeft, towardsRight)
        if (pressure <= 0) return

        const before = wrapper.scrollLeft
        wrapper.scrollLeft += Math.sign(towardsRight - towardsLeft) * Math.ceil(AUTO_SCROLL_MAX_SPEED * Math.min(1, pressure / AUTO_SCROLL_EDGE))
        if (wrapper.scrollLeft === before) return

        update()
        scrollFrame = requestAnimationFrame(autoScroll)
    }

    const onMove = (moveEvent) => {
        pointer = { x: moveEvent.clientX, y: moveEvent.clientY }

        if (!ghost) {
            if (Math.abs(moveEvent.clientX - startX) < DRAG_THRESHOLD) return
            window.getSelection()?.removeAllRanges()
            ghost = createDragGhost(th, source.elements.slice(1))
            source.elements.forEach((element) => element.classList.add('fi-ta-column-dragging'))
            table.classList.add('fi-ta-reordering')
            document.body.classList.add('reordering-column')
        }

        scheduleUpdate()
        scrollFrame ??= requestAnimationFrame(autoScroll)
    }

    const finish = (shouldDrop) => {
        document.removeEventListener('pointermove', onMove)
        document.removeEventListener('pointerup', onUp)
        document.removeEventListener('pointercancel', onCancel)
        document.removeEventListener('keydown', onKey)
        if (scrollFrame) cancelAnimationFrame(scrollFrame)
        if (moveFrame) cancelAnimationFrame(moveFrame)

        if (!ghost) return

        update()
        swallowNextClick()
        ghost.remove()
        source.elements.forEach((element) => element.classList.remove('fi-ta-column-dragging'))
        table.classList.remove('fi-ta-reordering')
        document.body.classList.remove('reordering-column')
        clearShift(slots)

        const box = (wrapper ?? table).getBoundingClientRect()
        const insideTable = pointer.x >= box.left && pointer.x <= box.right && pointer.y >= box.top && pointer.y <= table.getBoundingClientRect().bottom

        if (!shouldDrop || !insideTable || index === startIndex) return

        // The cells move in the page at once, so the server's re-render arrives with nothing left to change.
        moveColumnCells(source, others[index] ?? null, others.at(-1))
        wire.moveTableColumn(headerName(th), others[index] ? headerName(others[index].th) : null).catch(forgetColumnFocus)
    }

    const onUp = (upEvent) => {
        pointer = { x: upEvent.clientX, y: upEvent.clientY }
        finish(true)
    }
    const onCancel = () => finish(false)
    const onKey = (keyEvent) => {
        if (keyEvent.key === 'Escape') finish(false)
    }

    document.addEventListener('pointermove', onMove)
    document.addEventListener('pointerup', onUp)
    document.addEventListener('pointercancel', onCancel)
    document.addEventListener('keydown', onKey)
}

function registerColumnMenu() {
    window.Alpine.data('tableColumnMenu', () => ({
        column: null,
        trigger: null,
        panel: null,
        hideable: false,
        movable: false,
        pinned: false,
        canMoveLeft: false,
        canMoveRight: false,
        before: { left: null, right: null },
        neighbour: null,
        top: 0,
        right: 0,

        open(detail) {
            if (this.column === detail.column) {
                this.close(true)

                return
            }

            pendingColumnFocus = null
            this.close(false)

            // Placed by its right edge in the same update that shows it, so it never appears elsewhere first.
            // A hidden panel still reports its CSS width, which keeps it on screen beside a column at the left edge.
            const rect = detail.trigger.getBoundingClientRect()
            const width = parseFloat(getComputedStyle(this.panel).width) || 0
            // The viewport width without a classic scrollbar, which `fixed` positioning also excludes.
            const viewportWidth = document.documentElement.clientWidth
            const right = Math.min(viewportWidth - rect.right, viewportWidth - width - 8)
            Object.assign(this, detail, { top: rect.bottom + 4, right: Math.max(8, right) })
            this.trigger.setAttribute('aria-expanded', 'true')

            this.$nextTick(() => this.items()[0]?.focus())
        },

        items() {
            return [...this.panel.querySelectorAll('[role="menuitem"]')].filter((item) => !item.disabled && item.offsetParent !== null)
        },

        step(delta) {
            const items = this.items()
            const index = items.indexOf(document.activeElement)
            items[(index + delta + items.length) % items.length]?.focus()
        },

        close(returnFocus) {
            if (this.column === null) return
            const trigger = this.trigger?.isConnected
                ? this.trigger
                : columnHandle(this.$el.closest('.fi-ta'), this.column)
            trigger?.setAttribute('aria-expanded', 'false')
            if (returnFocus) trigger?.focus()
            this.column = null
        },

        choose(run, focusColumn) {
            rememberColumnFocus(focusColumn)
            Promise.resolve(run()).catch(forgetColumnFocus)
            this.close(false)
        },
    }))
}

function registerResizableColumn() {
    ensureGlobalMorphHook()
    wireStickyHeaders()
    window.Alpine.data('resizableColumn', (columnName, columnId, min = 40, max = 1000) => ({
        columnName,
        minWidth: min,
        maxWidth: max,
        tableSelector: '.fi-ta-table',
        tableWrapperSelector: '.fi-ta-content-ctn',
        // Relationship columns keep the dot in Filament's cell class (`user.name`).
        cellSelector: `.fi-ta-cell-${CSS.escape(columnId)}`,
        column: null,
        table: null,
        tableWrapper: null,
        handleBar: null,

        init() {
            this.column = this.$el
            this.table = this.$el.closest(this.tableSelector)
            this.tableWrapper = this.$el.closest(this.tableWrapperSelector)
            if (!this.column || !this.table || !this.tableWrapper) return

            this.column.classList.add('fi-ta-resizable-header')

            lockTable(this.table)
            this.wrapHeaderLabel()
            this.createHandle()
            this.createColumnControls()

            // Alpine re-runs init when the header's x-data changes, so the listener is bound once.
            if (!this.column.reorderListenerBound) {
                this.column.reorderListenerBound = true
                this.column.addEventListener('pointerdown', (e) => startHeaderDrag(e, this.column, this.$wire))
            }
        },

        createColumnControls() {
            this.column.querySelector(':scope > .fi-ta-column-handle')?.remove()

            const handle = document.createElement('button')
            handle.type = 'button'
            handle.className = 'fi-ta-column-handle'
            handle.setAttribute('aria-label', window.filamentData?.columnOptionsLabel ?? '')
            handle.setAttribute('aria-haspopup', 'menu')
            if (isMovable(this.column)) {
                handle.addEventListener('keydown', (e) => this.moveByKey(e))
            }
            handle.addEventListener('click', (e) => {
                e.preventDefault()
                e.stopPropagation()
                window.dispatchEvent(new CustomEvent('column-menu-open', {
                    detail: {
                        column: columnName,
                        trigger: handle,
                        hideable: this.column.dataset.columnHideable === '1',
                        pinned: this.column.dataset.columnPinned === '1',
                        ...moveTargets(this.column),
                    },
                }))
            })

            this.column.appendChild(handle)
        },

        moveByKey(event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
            event.preventDefault()
            event.stopPropagation()

            const targets = moveTargets(this.column)
            const isLeft = event.key === 'ArrowLeft'
            if (isLeft ? !targets.canMoveLeft : !targets.canMoveRight) return

            rememberColumnFocus(columnName)
            this.$wire.moveTableColumn(columnName, isLeft ? targets.before.left : targets.before.right).catch(forgetColumnFocus)
        },

        wrapHeaderLabel() {
            if (this.column.querySelector(':scope > .fi-ta-resizable-label')) return
            const wrapper = document.createElement('span')
            wrapper.className = 'fi-ta-resizable-label'
            while (this.column.firstChild) {
                wrapper.appendChild(this.column.firstChild)
            }
            this.column.appendChild(wrapper)
        },

        createHandle() {
            const existing = this.column.querySelector('.column-resize-handle-bar')
            if (existing) existing.remove()

            this.handleBar = createHandleBar()
            this.column.appendChild(this.handleBar)
            this.handleBar.addEventListener('pointerdown', (e) => this.startResize(e))
            this.handleBar.addEventListener('dblclick', (e) => {
                e.preventDefault()
                e.stopPropagation()
                this.autoFit()
            })
        },

        startResize(event) {
            if (event.button !== 0) return
            event.preventDefault()
            event.stopPropagation()
            this.handleBar.classList.add('active')
            document.body.classList.add('resizing-column')

            const startX = event.pageX
            const startColumnWidth = this.column.offsetWidth
            // In the wrapper's content coordinates the column's left edge does not move with
            // scrollLeft, so the guideline sits at contentLeftEdge + currentWidth.
            const wrapperRect = this.tableWrapper.getBoundingClientRect()
            const contentLeftEdge =
                this.column.getBoundingClientRect().left - wrapperRect.left + this.tableWrapper.scrollLeft
            const guidelineHeight = this.table.offsetHeight
            let currentWidth = startColumnWidth
            // Shown on the first move only, so a double-click never flashes the overlay.
            let overlayShown = false

            const onMove = (moveEvent) => {
                if (moveEvent.pointerId !== event.pointerId || moveEvent.pageX === startX) return
                currentWidth = Math.round(
                    Math.min(
                        this.maxWidth,
                        Math.max(this.minWidth, startColumnWidth + (moveEvent.pageX - startX)),
                    ),
                )
                this.applyWidth(currentWidth)

                const contentX = contentLeftEdge + currentWidth
                if (overlayShown) {
                    moveDragOverlay(contentX, currentWidth, moveEvent.clientX, moveEvent.clientY)
                } else {
                    showDragOverlay(this.tableWrapper, contentX, guidelineHeight, currentWidth, moveEvent.clientX, moveEvent.clientY)
                    overlayShown = true
                }
            }

            const onUp = (upEvent) => {
                if (upEvent.pointerId !== event.pointerId) return
                this.handleBar.classList.remove('active')
                document.body.classList.remove('resizing-column')
                hideDragOverlay()
                if (currentWidth !== startColumnWidth) {
                    this.saveWidth(currentWidth)
                }
                document.removeEventListener('pointermove', onMove)
                document.removeEventListener('pointerup', onUp)
                document.removeEventListener('pointercancel', onUp)
            }

            document.addEventListener('pointermove', onMove)
            document.addEventListener('pointerup', onUp)
            document.addEventListener('pointercancel', onUp)
        },

        applyWidth(width) {
            const headerStyle = `min-width: ${width}px; width: ${width}px; max-width: ${width}px`
            const cellStyle = `${headerStyle}; overflow: hidden`

            this.column.style.cssText = headerStyle
            this.column.dataset.resizableWidth = String(width)
            this.table.querySelectorAll(this.cellSelector).forEach((cell) => {
                cell.style.cssText = cellStyle
                cell.dataset.resizableWidth = String(width)
            })
            // The style reset above clears a pinned cell's `left`, and only a pinned column moves the pins after it.
            if (this.column.dataset.columnPinned === '1') applyPinnedOffsets(this.table)
            scheduleSizingUpdate(this.table)
        },

        // Saved on release, not after a delay: a sort sent before the save re-renders the old width.
        saveWidth(width) {
            this.$wire.updateResizableColumnWidth(columnName, String(width))
        },

        // `table-layout: fixed` never sizes a column to its content, so the header label and each
        // cell's content are laid out at their natural width and measured, icons and padding included.
        autoFit() {
            const width = this.measureContentWidth()
            this.applyWidth(width)
            this.saveWidth(width)
        },

        measureContentWidth() {
            const label = this.column.querySelector(':scope > .fi-ta-resizable-label')
            const contents = [...this.table.querySelectorAll(this.cellSelector)]
                .map((cell) => cell.firstElementChild)
                .filter(Boolean)

            const insets = [this.column, ...contents.map((content) => content.parentElement)].map(horizontalInset)
            const widths = naturalWidths([label, ...contents])
            const widest = Math.max(...widths.map((width, index) => width + insets[index]))

            return Math.ceil(Math.min(this.maxWidth, Math.max(this.minWidth, widest)))
        },
    }))
}

if (window.Alpine && window.Alpine.version) {
    registerResizableColumn()
    registerColumnMenu()
} else {
    document.addEventListener('alpine:init', registerResizableColumn)
    document.addEventListener('alpine:init', registerColumnMenu)
}
