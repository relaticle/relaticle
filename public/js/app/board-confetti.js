// Confetti for a card moved into a stage that celebrates. Flowforge dispatches kanban-card-moved from the mover's component
// only after the server accepted the move, so the burst never reaches another browser or a refused move.

const PALETTE = ['#ff4d6d', '#ffb703', '#3ddc97', '#4cc9f0', '#7b5cff', '#ff7ab6', '#ff8a3d']
const STAGE_SHARE = 0.25
// Gravity is in window heights per second squared, so a burst plays the same at any size. Drag averages about 4.2 per
// second, close to damping by 0.93 per 60Hz frame.
const GRAVITY = 1.26
const DRAG = 2.6
const FACING_DRAG = 3.4
const RAIN_DRAG = 0.7
const MAX_DT = 0.05
const MAX_LIFETIME = 6
const FADE_OUT = 0.5
const SHADES = 8
const BACK_SHADE = 0.88
const EDGE_SHADE = 0.82
const PUSH_RADIUS = 120
const PUSH_GAIN = 0.56
const MAX_CURSOR_SPEED = 3000

const hexRgb = (hex) => {
    return [1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16))
}

const linearToSrgb = (v) => Math.round(255 * Math.min(1, Math.max(0, v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055)))

const oklchRgb = (l, c, hue) => {
    const a = c * Math.cos(hue)
    const b = c * Math.sin(hue)
    const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3
    const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3
    const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3

    return [
        linearToSrgb(4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_),
        linearToSrgb(-1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_),
        linearToSrgb(-0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_),
    ]
}

// A custom property computes to the written color, so hex, rgb(), color(srgb) and oklch() are read without a canvas or a probe element.
const parseColor = (css) => {
    if (/^#[0-9a-f]{6}$/i.test(css)) {
        return hexRgb(css)
    }

    const numbers = (css.match(/-?\d*\.?\d+(?:e-?\d+)?%?/g) ?? []).map((n) => (n.endsWith('%') ? parseFloat(n) / 100 : parseFloat(n)))

    if (css.startsWith('rgb')) {
        return numbers.slice(0, 3)
    }

    if (css.startsWith('color(srgb')) {
        return numbers.slice(0, 3).map((n) => Math.round(n * 255))
    }

    if (css.startsWith('oklch')) {
        return oklchRgb(numbers[0], numbers[1], (numbers[2] * Math.PI) / 180)
    }

    return null
}

const stageColor = (columnId) => {
    const dot = document.querySelector(`[data-column-root="${CSS.escape(columnId)}"] .ff-column-dot`)

    if (! dot) {
        return null
    }

    const color = getComputedStyle(dot).getPropertyValue('--color-500').trim()

    return parseColor(color)
}

// Front and back faces in SHADES brightness steps each, so a frame only picks a string.
const stylesFor = (rgb) => {
    const styles = []

    for (const face of [1, BACK_SHADE]) {
        for (let level = 0; level < SHADES; level++) {
            const shade = face * (EDGE_SHADE + (1 - EDGE_SHADE) * (level / (SHADES - 1)))

            styles.push(`rgb(${rgb.map((channel) => Math.round(channel * shade)).join(',')})`)
        }
    }

    return styles
}

const stylesForBurst = (columnId) => {
    const stage = stageColor(columnId)

    return {
        palette: PALETTE.map((hex) => stylesFor(hexRgb(hex))),
        stage: stage ? stylesFor(stage) : null,
    }
}

const piece = (x, y, vx, vy, styles, drag = 1) => {
    const kind = Math.random()
    const shape = kind < 0.15 ? 2 : kind < 0.4 ? 1 : 0

    return {
        x,
        y,
        vx,
        vy,
        drag,
        rotation: Math.random() * Math.PI * 2,
        rotationSpeed: (Math.random() - 0.5) * 10,
        tumbleX: Math.random() * Math.PI * 2,
        tumbleXSpeed: 5 + Math.random() * 8,
        tumbleY: Math.random() * Math.PI * 2,
        tumbleYSpeed: 3 + Math.random() * 6,
        flutter: 120 + Math.random() * 270,
        size: 0.85 + Math.random() * 0.4,
        shape,
        width: shape === 1 ? 4 + Math.random() : shape === 2 ? 7 + Math.random() : 8 + Math.random() * 2,
        height: shape === 1 ? 14 + Math.random() * 2 : 11 + Math.random() * 2,
        styles: styles.stage && Math.random() < STAGE_SHARE ? styles.stage : styles.palette[(Math.random() * styles.palette.length) | 0],
    }
}

// Each piece leaves a disc just off its edge, aimed at its own point on the top edge, at a speed scaled to that distance.
// One shared aim point piles both cannons into one clump over the middle of a wide board.
const build = (styles, width, height) => {
    const small = width < 640
    const pieces = []

    for (const side of [1, -1]) {
        const edge = side > 0 ? -60 : width + 60

        for (let i = 0; i < (small ? 35 : 50); i++) {
            const around = Math.random() * Math.PI * 2
            const radius = Math.random() * 50
            const y = height * 0.85 + Math.sin(around) * radius
            const reach = width * (0.1 + Math.random() * 0.85)
            const angle = Math.atan2(y, reach) + (Math.random() - 0.5) * 0.12
            const speed = Math.hypot(reach, y) * (3.3 + Math.random() * 1.1)

            pieces.push(piece(edge + Math.cos(around) * radius, y, side * Math.cos(angle) * speed, -Math.sin(angle) * speed, styles))
        }
    }

    for (let i = 0; i < (small ? 40 : 60); i++) {
        pieces.push(piece(Math.random() * width, -20 - Math.random() * height * 0.6, 0, height * 0.15, styles, RAIN_DRAG))
    }

    return pieces
}

// Time runs at 0.8 for 0.7s once the cannons peak, so the burst hangs before it falls.
const hang = (since) => {
    if (since < 0 || since > 0.7) {
        return 1
    }

    const ramp = since < 0.05 ? since / 0.05 : since > 0.5 ? (0.7 - since) / 0.2 : 1

    return 1 - 0.2 * ramp * ramp
}

// Drag grows as a piece turns flat to its fall, which makes the throw slow down fast and each piece fall at its own speed.
const step = (p, dt, gravity) => {
    const rising = p.vy < 0

    p.tumbleX += p.tumbleXSpeed * dt
    p.tumbleY += p.tumbleYSpeed * dt
    p.rotation += p.rotationSpeed * dt

    const facing = Math.abs(Math.cos(p.tumbleX) * Math.cos(p.tumbleY))
    const decay = Math.exp(-(DRAG + FACING_DRAG * facing) * p.size * p.drag * dt)

    p.vx *= decay
    p.vy = p.vy * decay + gravity * dt
    p.x += (p.vx + Math.sin(p.tumbleY) * p.flutter * facing) * dt
    p.y += p.vy * dt

    return rising && p.vy >= 0
}

const draw = (ctx, p, dpr) => {
    const flip = Math.cos(p.tumbleX)
    const skew = Math.sin(p.tumbleY) * 0.35
    const scaleY = Math.max(Math.abs(flip), 0.08)
    const cos = Math.cos(p.rotation)
    const sin = Math.sin(p.rotation)
    const level = Math.min(SHADES - 1, Math.floor(Math.abs(flip) * SHADES))

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

let addVolley = null

const fire = (columnId) => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return
    }

    // Read the stage color before the canvas lands: appending it first makes this read recalculate the whole board.
    const pieces = build(stylesForBurst(columnId), window.innerWidth, window.innerHeight)

    if (addVolley) {
        addVolley(pieces)

        return
    }

    const canvas = document.createElement('canvas')

    canvas.dataset.boardConfetti = ''
    canvas.setAttribute('aria-hidden', 'true')
    canvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:2147483000;'
    document.body.appendChild(canvas)

    const ctx = canvas.getContext('2d')
    const dpr = Math.min(window.devicePixelRatio || 1, 1.5)
    const cursor = { x: 0, y: 0, time: 0, seen: false }
    let last = performance.now()
    let elapsed = 0
    let endsAt = MAX_LIFETIME
    let peakedAt = Infinity
    let frameId = 0

    addVolley = (more) => {
        pieces.push(...more)
        endsAt = elapsed + MAX_LIFETIME
        peakedAt = Infinity
    }

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

                if (distance >= PUSH_RADIUS) {
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
        addVolley = null
    }

    const frame = (now) => {
        const width = window.innerWidth
        const height = window.innerHeight
        const dt = Math.min((now - last) / 1000, MAX_DT) * hang(elapsed - peakedAt)
        let kept = 0

        last = now
        elapsed += dt

        if (! canvas.isConnected || elapsed > endsAt) {
            end()

            return
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.clearRect(0, 0, width, height)
        ctx.globalAlpha = Math.min(1, (endsAt - elapsed) / FADE_OUT)

        for (const p of pieces) {
            if (step(p, dt, GRAVITY * height) && peakedAt === Infinity) {
                peakedAt = elapsed
            }

            if (p.y > height + 40 || p.x < -400 || p.x > width + 400) {
                continue
            }

            pieces[kept++] = p
            draw(ctx, p, dpr)
        }

        pieces.length = kept

        if (kept === 0) {
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

// Two frames out: the first renders the board the move just morphed, so the burst never shares a frame with that work.
window.addEventListener('kanban-card-moved', (event) => {
    const columnId = String(event.detail?.[0]?.columnId ?? '')

    if (columnId === '') {
        return
    }

    requestAnimationFrame(() => requestAnimationFrame(() => {
        const celebrating = JSON.parse(document.querySelector('[data-board-celebrate]')?.dataset.boardCelebrate ?? '[]')

        if (celebrating.includes(columnId)) {
            fire(columnId)
        }
    }))
})
