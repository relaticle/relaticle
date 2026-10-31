<script data-navigate-once>
    (() => {
        const reveal = () => document.querySelectorAll('.fi-tabs, [data-reveal-active-tab]').forEach((bar) => {
            const tab = bar.querySelector('.fi-tabs-item.fi-active, [aria-current="true"]')

            if (! tab || bar.scrollWidth <= bar.clientWidth) return

            const barBox = bar.getBoundingClientRect()
            const tabBox = tab.getBoundingClientRect()

            bar.scrollLeft += tabBox.left - barBox.left - (barBox.width - tabBox.width) / 2
        })

        reveal()
        document.addEventListener('livewire:navigated', reveal)
    })()
</script>
