import { animate } from "motion/mini"
import { inView, spring, stagger } from "motion"
import { initAgentNetwork } from "./agent-network"

const EASE = [0.22, 1, 0.36, 1]

function from(transform) {
    return { transform: [transform, "none"] }
}

function reveal(trigger, amount, play) {
    inView(trigger, () => { play() }, { amount })
}

window.animate = animate
window.spring = spring
window.dispatchEvent(new CustomEvent("motion-ready"))

document.querySelectorAll("[data-agent-network]").forEach(initAgentNetwork)

reveal("#features > div > .grid", 0.1, () => {
    animate(".feat-card", from("translateY(32px)"), { delay: stagger(0.07), duration: 0.6, ease: EASE })
})
reveal("#card-builtin-ai", 0.4, () => {
    animate("#card-builtin-ai .ai-fill", { width: ["0%", "100%"] }, { delay: stagger(0.18, { startDelay: 0.3 }), duration: 0.6, ease: EASE })
    animate("#ai-sparkle", { transform: ["scale(1)", "scale(1.2)", "none"] }, { duration: 0.5, delay: 0.2, ease: EASE })
})
reveal("#card-data", 0.4, () => {
    animate("#card-data .field-row", from("translateX(-16px)"), { delay: stagger(0.1, { startDelay: 0.3 }), duration: 0.4, ease: EASE })
})
reveal("#card-sales", 0.4, () => {
    animate(".pipe-seg", from("scaleX(0)"), { delay: stagger(0.12, { startDelay: 0.3 }), duration: 0.6, ease: EASE })
})
reveal("#card-tasks", 0.3, () => {
    animate(".task-row", from("translateX(20px)"), { delay: stagger(0.15, { startDelay: 0.2 }), duration: 0.45, ease: EASE })
})
reveal("#faq .divide-y", 0.15, () => {
    animate(".faq-item", from("translateY(20px)"), { delay: stagger(0.08), duration: 0.5, ease: EASE })
})
