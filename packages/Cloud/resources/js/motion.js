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
    elements.forEach((element) => {
        element.style.opacity = "0"
    })
}

function once(target, amount, play) {
    inView(target, (element) => {
        play(element)
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
    const card = demo.querySelector("[data-approval-card]")
    const pending = demo.querySelector("[data-approval-pending]")
    const done = demo.querySelector("[data-approval-done]")
    const button = demo.querySelector("[data-approval-button]")
    const cursor = demo.querySelector("[data-approval-cursor]")

    hide([ask, card])

    once(demo, 0.6, async () => {
        await animate(ask, rise(8), { duration: BASE, ease: EASE })
        await animate(card, rise(12), { duration: SLOW, ease: EASE })
        settle([ask, card])

        const demoBox = demo.getBoundingClientRect()
        const target = button.getBoundingClientRect()
        const x = target.left - demoBox.left + target.width / 2
        const y = target.top - demoBox.top + target.height / 2

        await animate(cursor, {
            opacity: [0, 1, 1],
            transform: [`translate(${x + 90}px, ${y + 70}px)`, `translate(${x + 40}px, ${y + 30}px)`, `translate(${x}px, ${y}px)`],
        }, { duration: 0.7, delay: 0.5, ease: EASE })

        await animate(button, { transform: ["none", "translateY(1px)", "none"] }, { duration: FAST })

        done.classList.remove("invisible")
        done.removeAttribute("aria-hidden")
        pending.setAttribute("aria-hidden", "true")

        animate(pending, { opacity: [1, 0] }, { duration: FAST }).then(() => pending.classList.add("invisible"))
        animate(done, { opacity: [0, 1], transform: ["translateY(4px)", "none"] }, { duration: BASE, delay: 0.1, ease: EASE })
        animate(cursor, { opacity: [1, 0] }, { duration: BASE, delay: 0.4 })
    })
}

function playAgent() {
    const demo = document.querySelector("[data-agent-demo]")

    if (! demo) {
        return
    }

    const steps = [...demo.querySelectorAll("[data-agent-step]")]
    const task = demo.querySelector("[data-agent-task]")

    hide([...steps, task])

    once(demo, 0.6, async () => {
        await animate(steps, rise(6), { duration: BASE, delay: stagger(0.35, { startDelay: 0.3 }), ease: EASE })
        await animate(task, rise(12), { duration: SLOW, delay: 0.2, ease: EASE })
        settle([...steps, task])
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
    playApproval()
    playAgent()
    playStory()
    playMailRows()
}
