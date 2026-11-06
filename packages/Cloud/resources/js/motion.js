import { animate } from "motion/mini"
import { inView, spring, stagger } from "motion"

const EASE = [0.22, 1, 0.36, 1]
const FAST = 0.2
const BASE = 0.3
const SLOW = 0.45

window.animate = animate
window.spring = spring
window.dispatchEvent(new CustomEvent("motion-ready"))

const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches

class DemoCancelled extends Error {}

function rise(distance = 16) {
    return { opacity: [0, 1], transform: [`translateY(${distance}px)`, "none"] }
}

function settle(elements) {
    [elements].flat().forEach((element) => {
        element.style.opacity = ""
        element.style.transform = ""
    })
}

function hide(elements) {
    [elements].flat().forEach((element) => {
        element.style.opacity = "0"
        element.style.transform = ""
    })
}

function once(target, amount, play) {
    inView(target, (element) => {
        play(element)
    }, { amount })
}

function aborted(signal) {
    return new Promise((resolve) => signal.addEventListener("abort", resolve, { once: true }))
}

function playOnEntry(root, amount, { cycle, reset, target = root }) {
    const running = new Set()
    let controller = null

    const setState = (state) => {
        root.dataset.demoState = state
    }

    const stop = () => {
        controller?.abort()
        controller = null
        running.forEach((controls) => controls.stop())
        running.clear()
    }

    const play = async () => {
        stop()

        const { signal } = controller = new AbortController()

        const step = async (controls) => {
            running.add(controls)
            await Promise.race([controls, aborted(signal)])
            running.delete(controls)

            if (signal.aborted) {
                throw new DemoCancelled()
            }
        }

        setState("playing")

        try {
            reset()
            await cycle(step)
            setState("done")
        } catch (error) {
            if (! (error instanceof DemoCancelled)) {
                throw error
            }
        }
    }

    reset()
    setState("idle")

    inView(target, () => {
        play()

        return () => {
            stop()
            reset()
            setState("idle")
        }
    }, { amount })
}

function revealShowcases() {
    document.querySelectorAll("[data-feature-row] .feat-card").forEach((cell) => {
        const visual = cell.firstElementChild

        hide([visual])
        once(cell, 0.25, () => {
            animate(visual, rise(), { duration: SLOW, ease: EASE }).then(() => settle(visual))
        })
    })
}

function playApproval() {
    const demo = document.querySelector("[data-approval]")

    if (! demo) {
        return
    }

    const ask = demo.querySelector("[data-approval-ask]")
    const tool = demo.querySelector("[data-approval-tool]")
    const reply = demo.querySelector("[data-approval-reply]")
    const card = demo.querySelector("[data-approval-card]")
    const button = demo.querySelector("[data-approval-button]")

    playOnEntry(demo, 0.6, {
        reset: () => {
            hide([ask, reply, card])
            settle([button])
            tool.classList.add("invisible")
            demo.dispatchEvent(new CustomEvent("demo-reset"))
        },
        cycle: async (step) => {
            await step(animate(ask, rise(8), { duration: BASE, ease: EASE }))

            tool.classList.remove("invisible")
            await step(animate(tool, { opacity: [0, 1] }, { duration: FAST, delay: 0.2 }))
            await step(animate(tool, { opacity: [1, 0] }, { duration: FAST, delay: 0.7 }))
            tool.classList.add("invisible")

            await step(animate(reply, rise(6), { duration: BASE, ease: EASE }))
            await step(animate(card, rise(12), { duration: SLOW, ease: EASE }))
            settle([ask, reply, card])

            await step(animate(button, { transform: ["none", "scale(1.06)", "none"] }, { duration: SLOW, delay: 0.3, ease: EASE }))
        },
    })
}

function playAgent() {
    const demo = document.querySelector("[data-agent-demo]")

    if (! demo) {
        return
    }

    const steps = [...demo.querySelectorAll("[data-agent-step]")]
    const task = demo.querySelector("[data-agent-task]")

    playOnEntry(demo, 0.6, {
        reset: () => hide([...steps, task]),
        cycle: async (step) => {
            await step(animate(steps, rise(6), { duration: BASE, delay: stagger(0.35, { startDelay: 0.3 }), ease: EASE }))
            await step(animate(task, rise(12), { duration: SLOW, delay: 0.2, ease: EASE }))
            settle([...steps, task])
        },
    })
}

function playBoard() {
    const board = document.querySelector("[data-board]")

    if (! board) {
        return
    }

    const card = board.querySelector("[data-board-card]")
    const slot = board.querySelector("[data-board-slot]")
    const counts = [...board.querySelectorAll("[data-board-count]")]
    const lifted = ["relative", "z-20", "shadow-lg"]

    const showCounts = (moved) => {
        counts.forEach((count) => {
            count.querySelector("[data-count-before]").classList.toggle("invisible", moved)
            count.querySelector("[data-count-after]").classList.toggle("invisible", ! moved)
        })
    }

    const travel = () => {
        card.style.transform = ""

        const from = card.getBoundingClientRect()
        const to = slot.getBoundingClientRect()

        return { x: to.left - from.left, y: to.top - from.top }
    }

    playOnEntry(board, 0.5, {
        target: board.closest(".feat-card"),
        reset: () => {
            settle([card])
            slot.style.height = `${card.offsetHeight}px`
            card.classList.remove(...lifted)
            hide([slot])
            showCounts(false)
        },
        cycle: async (step) => {
            const { x, y } = travel()
            const held = "scale(1.02) rotate(2.5deg)"

            card.classList.add(...lifted)

            await Promise.all([
                step(animate(card, { transform: ["none", held] }, { duration: FAST, delay: 0.9 })),
                step(animate(slot, { opacity: [0, 1] }, { duration: FAST, delay: 0.9 })),
            ])

            await step(animate(card, { transform: [held, `translate(${x}px, ${y}px) ${held}`] }, { duration: 0.8, ease: EASE }))

            card.classList.remove("shadow-lg")
            showCounts(true)

            await Promise.all([
                step(animate(card, { transform: [`translate(${x}px, ${y}px) ${held}`, `translate(${x}px, ${y}px)`] }, { duration: FAST })),
                step(animate(slot, { opacity: [1, 0] }, { duration: BASE })),
            ])
        },
    })
}

function playEmail() {
    document.querySelectorAll("[data-mail-demo]").forEach((demo) => {
        const intro = [...demo.querySelectorAll("[data-mail-caption]")]
        const rows = [...demo.querySelectorAll("[data-mail-row]")]
        const locks = [...demo.querySelectorAll("[data-mail-lock]")]

        playOnEntry(demo, 0.5, {
            target: demo.closest(".feat-card"),
            reset: () => hide([...intro, ...rows, ...locks]),
            cycle: async (step) => {
                if (intro.length) {
                    await step(animate(intro, rise(8), { duration: BASE, delay: 0.2, ease: EASE }))
                }

                await step(animate(rows, rise(8), { duration: BASE, delay: stagger(0.12, { startDelay: 0.2 }), ease: EASE }))
                await step(animate(locks, { opacity: [0, 1], transform: ["scale(0.9)", "none"] }, { duration: BASE, delay: stagger(0.15, { startDelay: 0.3 }), ease: EASE }))
                settle([...intro, ...rows, ...locks])
            },
        })
    })
}

function storyRecord(story) {
    const states = [...story.querySelectorAll("[data-story-state]")]
    const written = (actor) => [...story.querySelectorAll(`[data-story-field="${actor}"]`)]
    const showStage = (stage) => states.forEach((state) => state.classList.toggle("invisible", state.dataset.storyState !== stage))

    return {
        start(actors) {
            showStage("proposal")
            hide(actors.flatMap(written))
        },
        write(actor) {
            if (actor === "you") {
                showStage("negotiation")
            }

            if (actor === "rela") {
                showStage("won")
            }

            const fields = written(actor)
            const flashes = [...story.querySelectorAll(`[data-story-flash="${actor}"]`)]

            animate(fields, { opacity: [0, 1], transform: ["scale(0.96)", "none"] }, { duration: BASE, ease: EASE }).then(() => settle(fields))
            animate(flashes, { opacity: [0, 1] }, { duration: FAST, ease: EASE })
                .then(() => animate(flashes, { opacity: [1, 0] }, { duration: SLOW, delay: 0.2, ease: EASE }))
        },
    }
}

function travel(wire) {
    if (! wire || wire.offsetWidth === 0) {
        return Promise.resolve()
    }

    const pulse = wire.querySelector("[data-story-pulse]")
    const distance = wire.offsetWidth - pulse.offsetWidth
    const [from, to] = wire.dataset.storyTravel === "right" ? [0, distance] : [distance, 0]

    return Promise.all([
        animate(pulse, { transform: [`translateX(${from}px)`, `translateX(${to}px)`] }, { duration: SLOW, ease: EASE }),
        animate(pulse, { opacity: [0, 1, 1, 0] }, { duration: SLOW }),
    ])
}

function playStory() {
    const story = document.querySelector("[data-story]")

    if (! story) {
        return
    }

    const steps = [...story.querySelectorAll("[data-story-step]")]
    const record = storyRecord(story)

    record.start(steps.map((step) => step.dataset.storyActor))
    hide(steps)

    once(steps[0], 0.35, () => {
        steps.forEach((step, index) => {
            const actor = step.dataset.storyActor

            animate(step, rise(10), { duration: BASE, delay: 0.2 + index * 0.9, ease: EASE }).then(() => {
                settle(step)
                travel(story.querySelector(`[data-story-wire="${actor}"]`)).then(() => record.write(actor))
            })
        })
    })
}

if (! reducedMotion) {
    revealShowcases()
    playBoard()
    playApproval()
    playAgent()
    playStory()
    playEmail()
}
