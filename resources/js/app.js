import { animate, scroll, inView, stagger, hover, press } from "motion"
import Alpine from "alpinejs"
import collapse from "@alpinejs/collapse"

window.animate = animate
window.scroll = scroll
window.inView = inView
window.stagger = stagger
window.hover = hover
window.press = press

Alpine.plugin(collapse)
window.Alpine = Alpine
Alpine.start()
