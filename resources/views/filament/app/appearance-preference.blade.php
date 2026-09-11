<script>
    const loadAccent = () => {
        const accent = localStorage.getItem('accent')

        if (accent) {
            document.documentElement.dataset.accent = accent
        } else {
            delete document.documentElement.dataset.accent
        }
    }

    loadAccent()

    document.addEventListener('livewire:navigated', loadAccent)
</script>
