<script>
document.addEventListener('livewire:init', () => {
    Alpine.data('previewTable', (config) => ({
        sessionId: config.sessionId,
        totalRows: config.totalRows,
        columns: config.columns,
        showCompanyMatch: config.showCompanyMatch,
        isProcessing: config.isProcessing,
        isReady: config.isReady,
        creates: config.creates,
        updates: config.updates,
        processed: config.processed,
        rows: config.rows,
        currentRowCount: config.rows.length,
        loadingMore: false,
        pollInterval: null,

        init() {
            if (this.isProcessing) {
                this.startPolling();
            }
            this.$nextTick(() => this.setupScroll());
        },

        destroy() {
            this.stopPolling();
        },

        startPolling() {
            this.pollInterval = setInterval(() => this.pollStatus(), 500);
        },

        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        async pollStatus() {
            try {
                const res = await fetch(`/app/import/${this.sessionId}/status`);
                const data = await res.json();

                this.creates = data.progress.creates;
                this.updates = data.progress.updates;
                this.processed = data.progress.processed;

                if (data.status === 'ready') {
                    this.isProcessing = false;
                    this.isReady = true;
                    this.stopPolling();
                    await this.reloadRows();
                }
            } catch {
                // Silently ignore polling errors
            }
        },

        async reloadRows() {
            try {
                const res = await fetch(`/app/import/${this.sessionId}/rows?start=0&limit=100`);
                const data = await res.json();

                if (data.rows && data.rows.length > 0) {
                    this.rows = data.rows;
                    this.currentRowCount = data.rows.length;
                }
            } catch {
                // Silently ignore reload errors
            }
        },

        async loadMore() {
            if (this.loadingMore || this.currentRowCount >= this.totalRows) return;
            this.loadingMore = true;

            try {
                const res = await fetch(`/app/import/${this.sessionId}/rows?start=${this.currentRowCount}&limit=100`);
                const data = await res.json();

                if (data.rows && data.rows.length > 0) {
                    this.rows = [...this.rows, ...data.rows];
                    this.currentRowCount = this.rows.length;
                }
            } catch {
                // Silently ignore load errors
            } finally {
                this.loadingMore = false;
            }
        },

        setupScroll() {
            const container = this.$refs.scrollContainer;
            if (container) {
                container.addEventListener('scroll', () => {
                    const threshold = 200;
                    if (container.scrollHeight - container.scrollTop - container.clientHeight < threshold) {
                        this.loadMore();
                    }
                });
            }
        },

        getActionIcon(action) {
            if (action === 'create') {
                return `<svg class='h-5 w-5 text-success-500' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='currentColor'><path fill-rule='evenodd' d='M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v2.5h-2.5a.75.75 0 000 1.5h2.5v2.5a.75.75 0 001.5 0v-2.5h2.5a.75.75 0 000-1.5h-2.5v-2.5z' clip-rule='evenodd'/></svg>`;
            } else if (action === 'update') {
                return `<svg class='h-5 w-5 text-info-500' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='currentColor'><path fill-rule='evenodd' d='M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z' clip-rule='evenodd'/></svg>`;
            }
            return '';
        },

        getMatchBadge(matchType) {
            if (matchType === 'id') {
                return `<span class='inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium bg-purple-50 text-purple-600 ring-1 ring-inset ring-purple-600/10 dark:bg-purple-400/10 dark:text-purple-400 dark:ring-purple-400/30'>ID</span>`;
            } else if (matchType === 'domain') {
                return `<span class='inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium bg-green-50 text-green-600 ring-1 ring-inset ring-green-600/10 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30'>Domain</span>`;
            } else if (matchType === 'new') {
                return `<span class='inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30'>New</span>`;
            }
            return `<span class='text-xs text-gray-400'>-</span>`;
        }
    }));
});
</script>
