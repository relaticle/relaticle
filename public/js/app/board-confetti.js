// Confetti for a card moved into a stage that celebrates. Flowforge dispatches kanban-card-moved from the mover's component
// only after the server accepted the move, so the burst never reaches another browser or a refused move.

const PALETTE = ['#5d54e8', '#a78bfa', '#34d399', '#fbbf24', '#38bdf8', '#f472b6']
const GRAVITY = 1000
const MAX_DT = 0.05
const MAX_LIFETIME = 6
const SHADES = 8
const BACK_SHADE = 0.78
const PUSH_RADIUS = 120
const PUSH_GAIN = 0.35
const MAX_CURSOR_SPEED = 3000

const rgbOf = (css) => {
    const probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true })

    probe.fillStyle = css
    probe.fillRect(0, 0, 1, 1)

    return [...probe.getImageData(0, 0, 1, 1).data.slice(0, 3)]
}

const stageColor = (columnId) => {
    const dot = document.querySelector(`[data-column-root="${CSS.escape(columnId)}"] .ff-column-dot`)

    if (! dot) {
        return null
    }

    const swatch = document.createElement('span')

    swatch.style.color = 'var(--color-500)'
    dot.appendChild(swatch)

    const color = getComputedStyle(swatch).color

    swatch.remove()

    return color
}

const colorsFor = (columnId) => {
    const stage = stageColor(columnId)
    const colors = PALETTE.map(rgbOf)

    return stage ? [...colors, ...Array(PALETTE.length).fill(rgbOf(stage))] : colors
}

// Front and back faces in SHADES brightness steps each, so a frame only picks a string.
const stylesFor = (rgb) => {
    const styles = []

    for (const face of [1, BACK_SHADE]) {
        for (let level = 0; level < SHADES; level++) {
            const shade = face * (0.55 + 0.45 * (level / (SHADES - 1)))

            styles.push(`rgb(${rgb.map((channel) => Math.round(channel * shade)).join(',')})`)
        }
    }

    return styles
}

const piece = (x, y, angle, spread, speedMin, speedMax, colors, delay = 0) => {
    const direction = angle + (Math.random() - 0.5) * spread
    const speed = speedMin + Math.random() * (speedMax - speedMin)
    const kind = Math.random()
    const shape = kind < 0.15 ? 2 : kind < 0.4 ? 1 : 0

    return {
        x,
        y,
        vx: Math.cos(direction) * speed,
        vy: Math.sin(direction) * speed,
        rotation: Math.random() * Math.PI * 2,
        rotationSpeed: (Math.random() - 0.5) * 10,
        tumbleX: Math.random() * Math.PI * 2,
        tumbleXSpeed: 5 + Math.random() * 8,
        tumbleY: Math.random() * Math.PI * 2,
        tumbleYSpeed: 3 + Math.random() * 6,
        flutter: 40 + Math.random() * 90,
        wobble: 60 + Math.random() * 80,
        size: 0.8 + Math.random() * 0.5,
        shape,
        width: shape === 1 ? 3 + Math.random() * 2 : 7 + Math.random() * 7,
        height: shape === 1 ? 14 + Math.random() * 8 : 5 + Math.random() * 5,
        styles: stylesFor(colors[(Math.random() * colors.length) | 0]),
        age: -delay,
        ttl: 2.6 + Math.random() * 1.4,
        alive: true,
    }
}

const build = (colors, width, height) => {
    const cap = width < 640 ? 80 : 150
    const cannon = Math.floor(cap * 0.4)
    const pieces = []

    for (const fromLeft of [true, false]) {
        const angle = fromLeft ? -Math.PI / 3.4 : Math.PI - Math.PI / 3.4

        for (let i = 0; i < cannon; i++) {
            pieces.push(piece(fromLeft ? -20 : width + 20, height * 0.62, angle, Math.PI / 4, 1300, 2600, colors))
        }
    }

    for (let i = pieces.length; i < cap; i++) {
        pieces.push(piece(Math.random() * width, -20, Math.PI / 2, Math.PI / 8, 80, 260, colors, 0.28 + Math.random() * 0.3))
    }

    return pieces
}

const step = (p, dt, width, height) => {
    p.age += dt

    if (p.age < 0) {
        return
    }

    if (p.age >= p.ttl || p.y > height + 60 || p.x < -400 || p.x > width + 400) {
        p.alive = false

        return
    }

    p.tumbleX += p.tumbleXSpeed * dt
    p.tumbleY += p.tumbleYSpeed * dt
    p.rotation += p.rotationSpeed * dt

    const facing = Math.abs(Math.cos(p.tumbleX) * Math.cos(p.tumbleY))
    const decay = Math.exp(-(1.2 + 2.8 * facing) * p.size * dt)

    p.vx = p.vx * decay + (Math.random() - 0.5) * p.wobble * dt * 8
    p.vy = p.vy * decay + GRAVITY * dt
    p.x += (p.vx + Math.sin(p.tumbleY) * p.flutter * facing * 3) * dt
    p.y += p.vy * dt
}

const draw = (ctx, p, dpr) => {
    if (p.age < 0) {
        return
    }

    const flip = Math.cos(p.tumbleX)
    const skew = Math.sin(p.tumbleY) * 0.35
    const scaleY = Math.max(Math.abs(flip), 0.08)
    const cos = Math.cos(p.rotation)
    const sin = Math.sin(p.rotation)
    const life = 1 - p.age / p.ttl
    const level = Math.min(SHADES - 1, Math.floor(Math.abs(flip) * SHADES))

    ctx.globalAlpha = life < 0.25 ? life / 0.25 : 1
    ctx.fillStyle = p.styles[(flip >= 0 ? 0 : SHADES) + level]
    ctx.setTransform(cos * dpr, sin * dpr, (cos * skew - sin * scaleY) * dpr, (sin * skew + cos * scaleY) * dpr, p.x * dpr, p.y * dpr)

    if (p.shape === 2) {
        ctx.beginPath()
        ctx.arc(0, 0, p.width / 2, 0, Math.PI * 2)
        ctx.fill()
    } else {
        ctx.fillRect(-p.width / 2, -p.height / 2, p.width, p.height)
    }
}

const fire = (columnId) => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || document.querySelector('[data-board-confetti]')) {
        return
    }

    const canvas = document.createElement('canvas')

    canvas.dataset.boardConfetti = ''
    canvas.setAttribute('aria-hidden', 'true')
    canvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:2147483000;'
    document.body.appendChild(canvas)

    const ctx = canvas.getContext('2d')
    const dpr = Math.min(window.devicePixelRatio || 1, 2)
    const pieces = build(colorsFor(columnId), window.innerWidth, window.innerHeight)
    const cursor = { x: 0, y: 0, time: 0, seen: false }
    const start = performance.now()
    let last = start
    let frameId = 0

    const resize = () => {
        canvas.width = window.innerWidth * dpr
        canvas.height = window.innerHeight * dpr
    }

    // Pieces near the cursor take its velocity, so a swipe scatters them and a resting pointer barely moves them.
    const push = (event) => {
        const dt = (event.timeStamp - cursor.time) / 1000

        if (cursor.seen && dt > 0) {
            const speed = Math.min(Math.hypot(event.clientX - cursor.x, event.clientY - cursor.y) / dt, MAX_CURSOR_SPEED)
            const vx = (event.clientX - cursor.x) / dt
            const vy = (event.clientY - cursor.y) / dt
            const norm = Math.hypot(vx, vy) || 1

            for (const p of pieces) {
                const dx = p.x - event.clientX
                const dy = p.y - event.clientY
                const distance = Math.hypot(dx, dy)

                if (p.age < 0 || distance >= PUSH_RADIUS) {
                    continue
                }

                const falloff = (1 - distance / PUSH_RADIUS) ** 2
                const gain = speed * PUSH_GAIN * falloff

                p.vx += (vx / norm) * gain + (dx / (distance || 1)) * gain * 0.3
                p.vy += (vy / norm) * gain + (dy / (distance || 1)) * gain * 0.3
            }
        }

        cursor.x = event.clientX
        cursor.y = event.clientY
        cursor.time = event.timeStamp
        cursor.seen = true
    }

    const end = () => {
        cancelAnimationFrame(frameId)
        window.removeEventListener('resize', resize)
        window.removeEventListener('pointermove', push)
        canvas.remove()
    }

    const frame = (now) => {
        const dt = Math.min((now - last) / 1000, MAX_DT)
        let alive = 0

        last = now
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight)

        for (const p of pieces) {
            if (! p.alive) {
                continue
            }

            step(p, dt, window.innerWidth, window.innerHeight)

            if (p.alive) {
                alive++
                draw(ctx, p, dpr)
            }
        }

        if (alive === 0 || now - start > MAX_LIFETIME * 1000) {
            end()

            return
        }

        frameId = requestAnimationFrame(frame)
    }

    resize()
    window.addEventListener('resize', resize)
    window.addEventListener('pointermove', push, { passive: true })
    frameId = requestAnimationFrame(frame)
}

window.addEventListener('kanban-card-moved', (event) => {
    const columnId = String(event.detail?.[0]?.columnId ?? '')
    const celebrating = JSON.parse(document.querySelector('[data-board-celebrate]')?.dataset.boardCelebrate ?? '[]')

    if (columnId !== '' && celebrating.includes(columnId)) {
        fire(columnId)
    }
})
