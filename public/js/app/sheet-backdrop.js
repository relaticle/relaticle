// On a phone a dropdown opens as a bottom sheet over a dimmed page. A tap on the dim only closes
// the sheet, as a native sheet does. WebKit sends no click after a cancelled touch, so it closes on pointerdown.
const openSheet = () => {
    if (!window.matchMedia('(width < 40rem)').matches) {
        return null
    }

    return [...document.querySelectorAll(".fi-dropdown-panel[x-ref='panel']")].find(
        (panel) => panel.style.display === 'block' && !panel.closest('.fi-sidebar, .fi-modal'),
    )
}

let tappedBackdrop = false

document.addEventListener(
    'pointerdown',
    (event) => {
        const sheet = openSheet()

        tappedBackdrop = Boolean(sheet) && !sheet.contains(event.target)

        if (tappedBackdrop) {
            event.preventDefault()
            event.stopPropagation()
            window.Alpine.$data(sheet).close()
        }
    },
    true,
)

document.addEventListener(
    'click',
    (event) => {
        if (!tappedBackdrop) {
            return
        }

        tappedBackdrop = false
        event.preventDefault()
        event.stopPropagation()
    },
    true,
)
