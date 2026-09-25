import { animate, scroll, inView, stagger, hover, press } from "motion"
import { initAgentNetwork } from "./agent-network"

window.animate = animate
window.scroll = scroll
window.inView = inView
window.stagger = stagger
window.hover = hover
window.press = press
window.dispatchEvent(new CustomEvent("motion-ready"))

document.querySelectorAll("[data-agent-network]").forEach(initAgentNetwork)
