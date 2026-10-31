<script>
    (() => {
        const reveal = () => document.querySelectorAll('.fi-tabs').forEach((bar) => {
            const tab = bar.querySelector('.fi-tabs-item.fi-active')

            if (! tab || bar.scrollWidth <= bar.clientWidth) return

            const barBox = bar.getBoundingClientRect()
            const tabBox = tab.getBoundingClientRect()

            bar.scrollLeft += tabBox.left - barBox.left - (barBox.width - tabBox.width) / 2
        })

        document.addEventListener('DOMContentLoaded', reveal)
        document.addEventListener('livewire:navigated', reveal)
    })()
</script>
