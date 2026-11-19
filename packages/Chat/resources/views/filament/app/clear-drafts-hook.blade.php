<script>
    try {
        Object.keys(localStorage)
            .filter((key) => key.startsWith('chat.draft.') || key === 'chat:draft')
            .forEach((key) => localStorage.removeItem(key));
    } catch (_) { /* localStorage unavailable */ }
    try {
        sessionStorage.removeItem('chat:bootstrap');
    } catch (_) { /* sessionStorage unavailable */ }
</script>
