import { inView } from "motion"

const SVG_NS = "http://www.w3.org/2000/svg"
const COMET_LENGTH = 0.28
const HORIZONTAL_SPREAD = 0.6
const IDLE_GAP = 700
const ENTRANCE_DELAY = 250
const ENTRANCE_STAGGER = 60
const ARRIVAL = 0.8
const TRAVEL_EASE = "cubic-bezier(0.4, 0, 0.2, 1)"

export function initAgentNetwork(root) {
    const layout = root.querySelector("[data-network-layout]")
    const svg = root.querySelector("[data-network-lines]")
    const hub = root.querySelector("[data-network-hub]")
    const glow = root.querySelector("[data-network-glow]")
    const highlight = root.querySelector("[data-network-highlight]")
    const status = root.querySelector("[data-network-status]")
    const defaultStatus = status.textContent
    const groups = ["agents", "records"].map(name => root.querySelector(`[data-network-${name}]`))
    const nodes = [...root.querySelectorAll("[data-network-node]")]
    const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)")
    const tokens = getComputedStyle(root)
    const ease = tokens.getPropertyValue("--ease-out-expo").trim()
    const milliseconds = token => {
        const value = tokens.getPropertyValue(token).trim()
        return parseFloat(value) * (value.endsWith("ms") ? 1 : 1000)
    }
    const duration = milliseconds("--duration-base")
    const travelDuration = milliseconds("--duration-slow")

    const connections = nodes.map(node => ({
        node,
        incoming: node.dataset.networkSide === "agent",
        base: createPath("text-gray-400/80 dark:text-gray-600", {
            "stroke-width": "1",
            "stroke-dasharray": "1",
            "stroke-dashoffset": "1",
        }),
        route: createPath("text-primary-600 opacity-0 transition-opacity duration-200 dark:text-primary-400", { "stroke-width": "1.5" }),
        pulse: createComet(node.dataset.networkNode),
    }))
    const incoming = connections.filter(connection => connection.incoming)
    const outgoing = connections.filter(connection => !connection.incoming)

    let horizontal = false
    let visible = false
    let entered = false
    let active = null
    let hovered = null
    let focused = null
    let tapped = null
    let pointerStart = null
    let dragged = false
    let engaged = false
    let ambient = null
    let cycleIndex = 0
    const running = new Set()

    function createPath(className, attributes, parent = svg) {
        const path = document.createElementNS(SVG_NS, "path")
        path.setAttribute("class", className)
        path.setAttribute("stroke", "currentColor")
        path.setAttribute("pathLength", "1")
        Object.entries(attributes).forEach(([name, value]) => path.setAttribute(name, value))
        parent.append(path)
        return path
    }

    function createComet(name) {
        const comet = document.createElementNS(SVG_NS, "g")
        comet.setAttribute("class", "text-primary-600 opacity-0 dark:text-primary-400")
        comet.setAttribute("stroke-dasharray", `${COMET_LENGTH} 1`)
        comet.setAttribute("stroke-dashoffset", String(COMET_LENGTH))
        comet.dataset.networkPulse = name
        createPath("opacity-30", { "stroke-width": "6" }, comet)
        createPath("", { "stroke-width": "2" }, comet)
        svg.append(comet)
        return comet
    }

    function shapes(connection) {
        return [connection.base, connection.route, ...connection.pulse.children]
    }

    function motionAllowed() {
        return visible && !document.hidden && !reducedMotion.matches
    }

    function visibleConnections() {
        return horizontal ? connections : [incoming[0], outgoing[0]]
    }

    function selectedConnections() {
        if (!active) return []
        if (!horizontal) return [incoming[0], outgoing[0]]
        return connections.filter(connection =>
            connection.node === active || connection.node.dataset.networkSide !== active.dataset.networkSide
        )
    }

    function track(animation) {
        running.add(animation)
        const settled = animation.finished.catch(() => {})
        settled.then(() => running.delete(animation))
        return settled
    }

    function cancelRunning() {
        running.forEach(animation => animation.cancel())
        running.clear()
    }

    function sweep(connection, delay = 0) {
        return track(connection.pulse.animate([
            { strokeDashoffset: String(COMET_LENGTH), opacity: 0, offset: 0 },
            { opacity: 1, offset: 0.12 },
            { opacity: 1, offset: 0.88 },
            { strokeDashoffset: "-1", opacity: 0, offset: 1 },
        ], { duration: travelDuration, delay, easing: TRAVEL_EASE, fill: "none" }))
    }

    function pulseHub(delay = 0) {
        const options = { duration: duration * 2, delay, easing: "ease-out", fill: "none" }
        track(glow.animate({ opacity: [0, 1, 0] }, options))
        return track(highlight.animate(
            { opacity: [1, 1, 0], transform: ["scale(1)", "scale(1.02)", "scale(1.05)"] },
            options,
        ))
    }

    async function travel(source, destinations) {
        const arrival = travelDuration * ARRIVAL
        await Promise.all([
            ...source.map(connection => sweep(connection)),
            pulseHub(arrival),
            ...destinations.map(connection => sweep(connection, arrival)),
        ])
    }

    function wait(ms) {
        return new Promise(resolve => setTimeout(resolve, ms))
    }

    async function runAmbient(token) {
        while (ambient === token && engaged && !active && motionAllowed()) {
            const agents = horizontal ? incoming : [incoming[0]]
            const records = horizontal ? outgoing : [outgoing[0]]
            const agent = agents[cycleIndex % agents.length]
            const record = records[(cycleIndex * 3 + 1) % records.length]
            cycleIndex += 1
            await travel([agent], [record])
            if (ambient !== token) return
            await wait(IDLE_GAP)
        }
        if (ambient === token) ambient = null
    }

    function startAmbient() {
        if (ambient || !engaged || !entered || active || !motionAllowed()) return
        ambient = {}
        runAmbient(ambient)
    }

    function stopAmbient() {
        ambient = null
        cancelRunning()
    }

    function render() {
        const selected = selectedConnections()
        connections.forEach(connection => {
            connection.route.style.opacity = selected.includes(connection) ? "1" : "0"
        })
        highlight.style.opacity = active ? "1" : "0"
    }

    function previewRoutes() {
        if (!motionAllowed()) return
        const selected = selectedConnections()
        const source = selected.filter(connection => connection.incoming)
        const destinations = selected.filter(connection => !connection.incoming)
        travel(source, destinations).then(() => {
            if (!active) startAmbient()
        })
    }

    function preview(node) {
        if (active === node) return
        active = node
        nodes.forEach(item => {
            item.toggleAttribute("data-active", item === node)
            item.setAttribute("aria-pressed", String(item === node))
        })
        status.textContent = node?.dataset.networkDescription ?? defaultStatus
        stopAmbient()
        render()
        node ? previewRoutes() : startAmbient()
    }

    function clear() {
        hovered = focused = tapped = pointerStart = null
        preview(null)
    }

    function enter() {
        if (entered || !motionAllowed()) return
        entered = true
        const draws = visibleConnections().map((connection, index) => {
            const draw = connection.base.animate(
                [{ strokeDashoffset: "1" }, { strokeDashoffset: "0" }],
                { duration: travelDuration, delay: ENTRANCE_DELAY + index * ENTRANCE_STAGGER, easing: ease, fill: "none" },
            )
            return draw.finished.then(() => connection.base.removeAttribute("stroke-dashoffset"))
        })
        Promise.all(draws).then(() => {
            revealStatic()
            startAmbient()
        })
    }

    function revealStatic() {
        entered = true
        connections.forEach(connection => connection.base.removeAttribute("stroke-dashoffset"))
    }

    function port(rect, side) {
        const center = { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 }
        return {
            left: [rect.left, center.y],
            right: [rect.right, center.y],
            top: [center.x, rect.top],
            bottom: [center.x, rect.bottom],
        }[side]
    }

    function hubPort(rect, side, spread) {
        const [x, y] = port(rect, side)
        return horizontal ? [x, rect.top + rect.height * (0.5 + spread)] : [x, y]
    }

    function segment(bounds, [x1, y1], [x2, y2]) {
        const start = [x1 - bounds.left, y1 - bounds.top]
        const end = [x2 - bounds.left, y2 - bounds.top]
        if (!horizontal) return `M ${start[0]} ${start[1]} L ${end[0]} ${end[1]}`
        const middle = (start[0] + end[0]) / 2
        return `M ${start[0]} ${start[1]} C ${middle} ${start[1]}, ${middle} ${end[1]}, ${end[0]} ${end[1]}`
    }

    function measure() {
        if (document.hidden) return
        horizontal = getComputedStyle(layout).gridTemplateColumns.split(" ").length > 1
        const bounds = layout.getBoundingClientRect()
        const hubRect = hub.getBoundingClientRect()
        const groupRects = groups.map(group => group.getBoundingClientRect())
        const nodeRects = nodes.map(node => node.getBoundingClientRect())
        const shown = visibleConnections()

        connections.forEach((connection, index) => {
            const siblings = connection.incoming ? incoming : outgoing
            const rect = horizontal ? nodeRects[index] : groupRects[connection.incoming ? 0 : 1]
            const spread = (siblings.indexOf(connection) / (siblings.length - 1) - 0.5) * HORIZONTAL_SPREAD
            const nodeSide = horizontal ? (connection.incoming ? "right" : "left") : (connection.incoming ? "bottom" : "top")
            const hubSide = horizontal ? (connection.incoming ? "left" : "right") : (connection.incoming ? "top" : "bottom")
            const nodePort = port(rect, nodeSide)
            const hubEdge = hubPort(hubRect, hubSide, spread)
            const d = connection.incoming ? segment(bounds, nodePort, hubEdge) : segment(bounds, hubEdge, nodePort)
            shapes(connection).forEach(path => path.setAttribute("d", d))
            for (const element of [connection.base, connection.route, connection.pulse]) {
                element.style.display = shown.includes(connection) ? "" : "none"
            }
        })

        svg.setAttribute("viewBox", `0 0 ${bounds.width} ${bounds.height}`)
        render()
    }

    function nodeFor(target) {
        const node = target?.closest?.("[data-network-node]")
        return node && root.contains(node) ? node : null
    }

    root.addEventListener("pointerenter", event => {
        if (event.pointerType === "touch") return
        engaged = true
        startAmbient()
    })
    root.addEventListener("pointerleave", () => {
        engaged = false
        stopAmbient()
    })
    root.addEventListener("pointerover", event => {
        if (event.pointerType === "touch") return
        const node = nodeFor(event.target)
        if (!node || node === hovered) return
        hovered = node
        tapped = null
        preview(node)
    })
    root.addEventListener("pointerout", event => {
        if (event.pointerType === "touch") return
        const next = nodeFor(event.relatedTarget)
        if (next === nodeFor(event.target)) return
        hovered = next
        preview(hovered ?? focused)
    })
    root.addEventListener("focusin", event => {
        const node = nodeFor(event.target)
        if (!node?.matches(":focus-visible")) return
        focused = node
        hovered = tapped = null
        preview(node)
    })
    root.addEventListener("focusout", () => {
        focused = tapped = null
        preview(hovered)
    })
    document.addEventListener("pointerdown", event => {
        const node = nodeFor(event.target)
        dragged = false
        focused = null
        if (!node) {
            clear()
            return
        }
        pointerStart = { node, x: event.clientX, y: event.clientY }
    })
    document.addEventListener("pointerup", event => {
        if (!pointerStart) return
        const moved = Math.hypot(event.clientX - pointerStart.x, event.clientY - pointerStart.y) > 8
        dragged = nodeFor(event.target) !== pointerStart.node || moved
        pointerStart = null
        if (dragged) clear()
    })
    document.addEventListener("pointercancel", clear)
    root.addEventListener("click", event => {
        const node = nodeFor(event.target)
        if (!node || dragged) return
        if (event.pointerType === "mouse" && event.detail > 0) {
            preview(node)
            return
        }
        tapped = tapped === node ? null : node
        hovered = focused = null
        preview(tapped)
    })
    root.addEventListener("keydown", event => {
        if (event.key !== "Escape") return
        event.preventDefault()
        clear()
    })

    if (reducedMotion.matches) revealStatic()
    measure()
    new ResizeObserver(() => { if (visible) measure() }).observe(layout)
    document.fonts.ready.then(measure)
    nodes.forEach(node => { node.disabled = false })

    inView(root, () => {
        visible = true
        measure()
        entered ? startAmbient() : enter()
        return () => {
            visible = false
            clear()
            stopAmbient()
        }
    }, { amount: 0.3 })
    reducedMotion.addEventListener("change", () => {
        if (reducedMotion.matches) {
            stopAmbient()
            revealStatic()
            return
        }
        entered ? startAmbient() : enter()
    })
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            clear()
            stopAmbient()
            return
        }
        measure()
        entered ? startAmbient() : enter()
    })
    window.addEventListener("blur", () => {
        clear()
        stopAmbient()
    })
    window.addEventListener("focus", startAmbient)
    window.addEventListener("pagehide", clear)
}
