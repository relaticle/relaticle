<script>
document.addEventListener('livewire:init', () => {
    Alpine.data('valueReviewer', (config) => ({
        // Config from Blade
        sessionId: config.sessionId,
        csvColumn: config.csvColumn,
        fieldName: config.fieldName,
        perPage: config.perPage,
        isDateField: config.isDateField,
        isChoiceField: config.isChoiceField,
        isMultiChoice: config.isMultiChoice,
        choiceOptions: config.choiceOptions,
        dateFormat: config.dateFormat,
        valuesUrl: config.valuesUrl,
        correctionsStoreUrl: config.correctionsStoreUrl,
        correctionsDestroyUrl: config.correctionsDestroyUrl,

        // State
        values: [],
        page: 1,
        hasMore: false,
        total: config.uniqueCount,
        showing: 0,
        loading: true,
        loadingMore: false,
        errorsOnly: false,
        loadRequestInFlight: false,

        async loadValues(forceReload = false) {
            // Prevent race condition if another load is already in progress
            // Unless forceReload is true (e.g., after format change)
            if (this.loadRequestInFlight && !forceReload) return;

            this.loadRequestInFlight = true;
            this.loading = true;
            this.page = 1;
            try {
                const response = await fetch(this.valuesUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        csv_column: this.csvColumn,
                        field_name: this.fieldName,
                        page: this.page,
                        per_page: this.perPage,
                        errors_only: this.errorsOnly,
                        date_format: this.dateFormat,
                    }),
                });
                const result = await response.json();
                this.values = result.values;
                this.hasMore = result.hasMore;
                this.total = result.total;
                this.showing = result.showing;
            } catch (e) {
                console.error('Failed to load values:', e);
            } finally {
                this.loading = false;
                this.loadRequestInFlight = false;
            }
        },

        async loadMore() {
            if (this.loadingMore || !this.hasMore) return;
            this.loadingMore = true;
            this.page++;
            try {
                const response = await fetch(this.valuesUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        csv_column: this.csvColumn,
                        field_name: this.fieldName,
                        page: this.page,
                        per_page: this.perPage,
                        errors_only: this.errorsOnly,
                        date_format: this.dateFormat,
                    }),
                });
                const result = await response.json();
                this.values = [...this.values, ...result.values];
                this.hasMore = result.hasMore;
                this.showing = result.showing;
            } catch (e) {
                console.error('Failed to load more values:', e);
                this.page--;
            } finally {
                this.loadingMore = false;
            }
        },

        async toggleErrorsOnly() {
            this.errorsOnly = !this.errorsOnly;
            await this.loadValues();
        },

        onScroll(event) {
            const el = event.target;
            if (!this.loadingMore && this.hasMore && el.scrollTop + el.clientHeight >= el.scrollHeight - 100) {
                this.loadMore();
            }
        },

        async correctValue(oldValue, newValue) {
            // Update local state immediately for responsiveness
            // Use array replacement to ensure Alpine reactivity
            const index = this.values.findIndex(v => v.value === oldValue);

            // Store correction via API and get validation result
            try {
                const response = await fetch(this.correctionsStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        field_name: this.fieldName,
                        old_value: oldValue,
                        new_value: newValue,
                        csv_column: this.csvColumn,
                    }),
                });
                const result = await response.json();

                // Update local state with server validation result
                if (index !== -1) {
                    this.values[index] = {
                        ...this.values[index],
                        correctedValue: newValue,
                        issue: result.issue || null,
                        isSkipped: result.isSkipped || false,
                    };
                }
            } catch (e) {
                console.error('Failed to save correction:', e);
                // Still update local state on error
                if (index !== -1) {
                    this.values[index] = { ...this.values[index], correctedValue: newValue };
                }
            }
        },

        async skipValue(value) {
            // Update local state immediately for responsiveness
            // Use array replacement to ensure Alpine reactivity
            const index = this.values.findIndex(v => v.value === value);
            if (index === -1) return;

            const item = this.values[index];
            const wasSkipped = item.isSkipped;
            const newIsSkipped = !wasSkipped;

            if (newIsSkipped) {
                this.values[index] = { ...item, isSkipped: true, correctedValue: '' };
                // Store skip via API
                try {
                    await fetch(this.correctionsStoreUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            session_id: this.sessionId,
                            field_name: this.fieldName,
                            old_value: value,
                            new_value: '',
                        }),
                    });
                } catch (e) {
                    console.error('Failed to save skip:', e);
                }
            } else {
                this.values[index] = { ...item, isSkipped: false, correctedValue: null };
                // Remove correction via API
                try {
                    await fetch(this.correctionsDestroyUrl, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            session_id: this.sessionId,
                            field_name: this.fieldName,
                            old_value: value,
                        }),
                    });
                } catch (e) {
                    console.error('Failed to remove correction:', e);
                }
            }
        },
    }));
});
</script>
