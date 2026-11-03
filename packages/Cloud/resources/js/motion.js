import { animate } from "motion/mini"
import { inView, spring, stagger } from "motion"

const EASE = [0.22, 1, 0.36, 1]
const FAST = 0.2
const BASE = 0.3
const SLOW = 0.45
const HOLD = 2.5

window.animate = animate
window.spring = spring
window.dispatchEvent(new CustomEvent("motion-ready"))

const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches

class LoopCancelled extends Error {}

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

async function wait(seconds, signal) {
    await Promise.race([new Promise((resolve) => setTimeout(resolve, seconds * 1000)), aborted(signal)])

    if (signal.aborted) {
        throw new LoopCancelled()
    }
}

function swapToggle(toggle, paused) {
    toggle.setAttribute("aria-label", paused ? toggle.dataset.playLabel : toggle.dataset.pauseLabel)
    toggle.querySelector("[data-loop-label]").textContent = paused ? toggle.dataset.playText : toggle.dataset.pauseText
    toggle.querySelector('[data-loop-icon="pause"]').toggleAttribute("hidden", paused)
    toggle.querySelector('[data-loop-icon="play"]').toggleAttribute("hidden", ! paused)
}

function loop(root, amount, { cycle, reset, finish }) {
    const toggle = root.closest(".feat-card")?.querySelector("[data-loop-toggle]")
    const running = new Set()
    let controller = null
    let paused = false
    let visible = false

    const setState = (state) => {
        root.dataset.loopState = state
    }

    const stop = () => {
        controller?.abort()
        controller = null
        running.forEach((controls) => controls.stop())
        running.clear()
    }

    const play = async () => {
        stop()
        root.style.opacity = ""

        const { signal } = controller = new AbortController()

        const step = async (controls) => {
            running.add(controls)
            await Promise.race([controls, aborted(signal)])
            running.delete(controls)

            if (signal.aborted) {
                throw new LoopCancelled()
            }
        }

        setState("playing")

        try {
            while (true) {
                reset()
                await cycle(step)
                await wait(HOLD, signal)
                await step(animate(root, { opacity: [1, 0] }, { duration: FAST }))
                reset()
                await step(animate(root, { opacity: [0, 1] }, { duration: FAST }))
            }
        } catch (error) {
            if (! (error instanceof LoopCancelled)) {
                throw error
            }
        }
    }

    reset()
    setState("idle")

    inView(root, () => {
        visible = true

        if (! paused) {
            play()
        }

        return () => {
            visible = false
            stop()

            if (paused) {
                return
            }

            root.style.opacity = ""
            reset()
            setState("idle")
        }
    }, { amount })

    if (! toggle) {
        return
    }

    toggle.hidden = false
    toggle.addEventListener("click", () => {
        paused = ! paused
        swapToggle(toggle, paused)

        if (paused) {
            stop()
            root.style.opacity = ""
            finish()
            setState("paused")

            return
        }

        visible ? play() : setState("idle")
    })
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

function loopApproval() {
    const demo = document.querySelector("[data-approval]")

    if (! demo) {
        return
    }

    const ask = demo.querySelector("[data-approval-ask]")
    const card = demo.querySelector("[data-approval-card]")
    const pending = demo.querySelector("[data-approval-pending]")
    const done = demo.querySelector("[data-approval-done]")
    const button = demo.querySelector("[data-approval-button]")
    const cursor = demo.querySelector("[data-approval-cursor]")

    const showDone = () => {
        done.classList.remove("invisible")
        done.removeAttribute("aria-hidden")
        pending.classList.add("invisible")
        pending.setAttribute("aria-hidden", "true")
    }

    loop(demo, 0.6, {
        reset: () => {
            hide([ask, card, cursor])
            settle([button, pending, done])
            done.classList.add("invisible")
            done.setAttribute("aria-hidden", "true")
            pending.classList.remove("invisible")
            pending.removeAttribute("aria-hidden")
        },
        finish: () => {
            settle([ask, card, button, pending, done])
            hide([cursor])
            showDone()
        },
        cycle: async (step) => {
            await step(animate(ask, rise(8), { duration: BASE, ease: EASE }))
            await step(animate(card, rise(12), { duration: SLOW, ease: EASE }))
            settle([ask, card])

            const demoBox = demo.getBoundingClientRect()
            const target = button.getBoundingClientRect()
            const x = target.left - demoBox.left + target.width / 2
            const y = target.top - demoBox.top + target.height / 2

            await step(animate(cursor, {
                opacity: [0, 1, 1],
                transform: [`translate(${x + 90}px, ${y + 70}px)`, `translate(${x + 40}px, ${y + 30}px)`, `translate(${x}px, ${y}px)`],
            }, { duration: 0.7, delay: 0.5, ease: EASE }))

            await step(animate(button, { transform: ["none", "translateY(1px)", "none"] }, { duration: FAST }))

            done.classList.remove("invisible")
            done.removeAttribute("aria-hidden")
            pending.setAttribute("aria-hidden", "true")

            await Promise.all([
                step(animate(pending, { opacity: [1, 0] }, { duration: FAST })),
                step(animate(done, { opacity: [0, 1], transform: ["translateY(4px)", "none"] }, { duration: BASE, delay: 0.1, ease: EASE })),
                step(animate(cursor, { opacity: [1, 0] }, { duration: BASE, delay: 0.4 })),
            ])

            pending.classList.add("invisible")
        },
    })
}

function loopAgent() {
    const demo = document.querySelector("[data-agent-demo]")

    if (! demo) {
        return
    }

    const steps = [...demo.querySelectorAll("[data-agent-step]")]
    const task = demo.querySelector("[data-agent-task]")

    loop(demo, 0.6, {
        reset: () => hide([...steps, task]),
        finish: () => settle([...steps, task]),
        cycle: async (step) => {
            await step(animate(steps, rise(6), { duration: BASE, delay: stagger(0.35, { startDelay: 0.3 }), ease: EASE }))
            await step(animate(task, rise(12), { duration: SLOW, delay: 0.2, ease: EASE }))
            settle([...steps, task])
        },
    })
}

function playStory() {
    const story = document.querySelector("[data-story]")

    if (! story) {
        return
    }

    const steps = [...story.querySelectorAll("[data-story-step]")]

    hide(steps)

    once(story.querySelector("ol"), 0.4, () => {
        animate(steps, rise(10), { duration: BASE, delay: stagger(0.15), ease: EASE }).then(() => settle(steps))
    })
}

function playMailRows() {
    new Set([...document.querySelectorAll("[data-mail-row]")].map((row) => row.parentElement)).forEach((list) => {
        const rows = [...list.querySelectorAll("[data-mail-row]")]

        hide(rows)

        once(list, 0.1, () => {
            animate(rows, rise(8), { duration: BASE, delay: stagger(0.08), ease: EASE }).then(() => settle(rows))
        })
    })
}

if (! reducedMotion) {
    revealShowcases()
    loopApproval()
    loopAgent()
    playStory()
    playMailRows()
}
