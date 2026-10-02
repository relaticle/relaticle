@props([
    'storageKey',
    'target',
    'label',
    'side' => 'end',
])

<div
    x-data="{
        storageKey: @js($storageKey),
        width: window.resizableWidthStore.read(@js($storageKey)),
        isResizing: false,
        get growsTowardLeft() {
            return (@js($side) === 'start') !== (document.dir === 'rtl')
        },
        get bounds() {
            return window.resizableWidths[this.storageKey]
        },
        get target() {
            return document.querySelector(@js($target))
        },
        get max() {
            const reserve = parseFloat(getComputedStyle(this.target).getPropertyValue('--resize-reserve')) || 0

            return reserve > 0 ? Math.min(this.bounds.max, this.target.parentElement.clientWidth - reserve) : this.bounds.max
        },
        clamp(width, max = this.max) {
            return Math.round(Math.min(max, Math.max(this.bounds.min, width)))
        },
        apply(width, max = this.max) {
            this.width = this.clamp(width, max)
            document.documentElement.style.setProperty(this.bounds.property, `${this.width}px`)
        },
        save() {
            window.resizableWidthStore.write(this.storageKey, this.width)
        },
        start(event) {
            if (event.button !== 0) return

            const handle = event.currentTarget
            const edge = this.target.getBoundingClientRect()
            const growsTowardLeft = this.growsTowardLeft
            const max = this.max
            let moved = false

            handle.setPointerCapture(event.pointerId)
            this.isResizing = true
            document.documentElement.classList.add('fi-resizing')

            const move = (moveEvent) => {
                moved = true
                this.apply(growsTowardLeft ? edge.right - moveEvent.clientX : moveEvent.clientX - edge.left, max)
            }

            handle.addEventListener('pointermove', move)
            handle.addEventListener('lostpointercapture', () => {
                handle.removeEventListener('pointermove', move)
                this.isResizing = false
                document.documentElement.classList.remove('fi-resizing')

                if (moved) this.save()
            }, { once: true })
        },
        nudge(delta) {
            this.apply(this.clamp(this.width ?? this.target.offsetWidth) + (this.growsTowardLeft ? -delta : delta))
            this.save()
        },
        jump(width) {
            this.apply(width)
            this.save()
        },
        reset() {
            document.documentElement.style.removeProperty(this.bounds.property)
            this.width = null
            this.save()
        },
    }"
    x-on:pointerdown="start($event)"
    x-on:dblclick="reset()"
    x-on:keydown.arrow-left.prevent="nudge(-16)"
    x-on:keydown.arrow-right.prevent="nudge(16)"
    x-on:keydown.home.prevent="jump(bounds.min)"
    x-on:keydown.end.prevent="jump(max)"
    x-bind:class="{ 'fi-resize-handle-active': isResizing }"
    role="separator"
    aria-orientation="vertical"
    aria-label="{{ $label }}"
    x-bind:aria-valuenow="clamp(width ?? target.offsetWidth)"
    x-bind:aria-valuemin="bounds.min"
    x-bind:aria-valuemax="max"
    tabindex="0"
    {{ $attributes->class(['fi-resize-handle', 'fi-resize-handle-start' => $side === 'start']) }}
></div>
