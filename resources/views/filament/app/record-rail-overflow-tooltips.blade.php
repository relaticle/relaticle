<script data-navigate-once>
    const remeasurePackedValues = () => {
        queueMicrotask(() => {
            document.querySelectorAll('.fi-multi-value-entry').forEach((node) => {
                const data = window.Alpine?.$data(node);

                if (typeof data?.measure === 'function') {
                    data.measure();
                }
            });

            document.querySelectorAll('.fi-record-details-rail').forEach((node) => {
                const data = window.Alpine?.$data(node);

                if (typeof data?.applyOverflow === 'function') {
                    data.applyOverflow();
                }
            });
        });
    };

    const bindPackedValueRemeasure = () => {
        if (! window.Livewire || bindPackedValueRemeasure.bound) {
            return;
        }

        bindPackedValueRemeasure.bound = true;
        window.Livewire.hook('morphed', remeasurePackedValues);
    };

    document.addEventListener('livewire:init', bindPackedValueRemeasure);

    document.addEventListener('alpine:init', () => {
        bindPackedValueRemeasure();

        window.Alpine.data('multiValueOverflow', (copiedMessage = '') => {
            let resizeObserver = null;
            let mutationObserver = null;
            let measuring = false;

            return {
                hiddenCount: 0,
                copiedIndex: null,
                init() {
                    this.$nextTick(() => this.measure());
                    resizeObserver = new ResizeObserver(() => this.measure());
                    resizeObserver.observe(this.$el);
                    mutationObserver = new MutationObserver(() => this.measure());
                    mutationObserver.observe(this.$el, { childList: true });
                },
                destroy() {
                    resizeObserver?.disconnect();
                    mutationObserver?.disconnect();
                },
                copyToClipboard(text, index, event) {
                    event.preventDefault();
                    event.stopPropagation();
                    window.navigator.clipboard.writeText(text);
                    this.copiedIndex = index;
                    this.announceToScreenReader(copiedMessage.replaceAll(':value', text));
                    window.setTimeout(() => {
                        this.copiedIndex = null;
                    }, 2000);
                },
                announceToScreenReader(message) {
                    if (this.$refs.announcer) {
                        this.$refs.announcer.textContent = message;
                    }
                },
                measure() {
                    if (measuring) {
                        return;
                    }

                    measuring = true;

                    const items = [...this.$el.querySelectorAll(':scope > .fi-multi-value-item')];
                    const more = this.$refs.more;

                    items.forEach((item) => {
                        item.hidden = false;
                        item.style.flex = '0 0 auto';
                        item.style.minWidth = 'max-content';
                        item.style.maxWidth = 'none';
                    });

                    this.hiddenCount = 0;

                    if (more) {
                        more.hidden = true;
                    }

                    const fits = () => this.$el.scrollWidth <= this.$el.clientWidth + 1;

                    if (items.length < 2 || fits()) {
                        items.forEach((item) => {
                            item.style.minWidth = '';
                            item.style.maxWidth = '';
                        });
                        measuring = false;

                        return;
                    }

                    if (more) {
                        more.hidden = false;
                    }

                    for (let i = items.length - 1; i >= 1; i--) {
                        if (fits()) {
                            break;
                        }

                        items[i].hidden = true;
                        this.hiddenCount++;
                    }

                    items.forEach((item) => {
                        if (item.hidden) {
                            return;
                        }

                        item.style.minWidth = '';
                        item.style.maxWidth = '';
                    });

                    const visible = items.find((item) => ! item.hidden);

                    if (visible && ! fits()) {
                        visible.style.flex = '1 1 auto';
                        visible.style.minWidth = '0';
                    }

                    measuring = false;
                },
            };
        });

        window.Alpine.data('recordRailOverflowTooltips', () => {
            const bound = new WeakSet();

            return {
                hasOverflow: false,
                expandingForEdit: false,
                overflowFrame: null,
                init() {
                    this.$nextTick(() => {
                        this.applyOverflow();
                        this.bindOverflowTooltips();
                    });
                    this.observer = new MutationObserver(() => {
                        if (this.overflowFrame) {
                            return;
                        }

                        this.overflowFrame = window.requestAnimationFrame(() => {
                            this.overflowFrame = null;
                            this.applyOverflow();
                            this.bindOverflowTooltips();
                        });
                    });
                    this.observer.observe(this.$el, {
                        childList: true,
                        subtree: true,
                        attributes: true,
                        attributeFilter: ['data-details-expanded', 'data-inline-editing'],
                    });
                    this.resizeObserver = new ResizeObserver(() => this.bindOverflowTooltips());
                    this.resizeObserver.observe(this.$el);
                    this.onThemeChanged = () => this.bindOverflowTooltips();
                    window.addEventListener('theme-changed', this.onThemeChanged);
                },
                destroy() {
                    if (this.overflowFrame) {
                        window.cancelAnimationFrame(this.overflowFrame);
                    }

                    this.observer?.disconnect();
                    this.resizeObserver?.disconnect();

                    if (this.onThemeChanged) {
                        window.removeEventListener('theme-changed', this.onThemeChanged);
                    }
                },
                fieldEntries() {
                    return [...this.$el.querySelectorAll('.fi-section-content .fi-in-entry')];
                },
                visibleLimit() {
                    const limit = Number(this.$el.dataset.detailsVisibleLimit);

                    return Number.isFinite(limit) && limit > 0 ? limit : 8;
                },
                applyOverflow() {
                    const entries = this.fieldEntries();
                    const limit = this.visibleLimit();
                    const editingOverflow = entries.some((entry, index) => (
                        index >= limit && entry.getAttribute('data-inline-editing') === 'true'
                    ));
                    let expanded = this.$el.dataset.detailsExpanded === 'true';

                    if (editingOverflow && ! expanded && ! this.expandingForEdit && this.$wire && typeof this.$wire.set === 'function') {
                        this.expandingForEdit = true;
                        this.$wire.set('recordDetailsExpanded', true);
                        expanded = true;
                    }

                    if (expanded) {
                        this.expandingForEdit = false;
                    }

                    this.hasOverflow = entries.length > limit;
                    this.$el.classList.toggle('fi-record-details-has-overflow', this.hasOverflow);
                    this.$el.classList.toggle('fi-record-details-expanded', expanded);

                    entries.forEach((entry, index) => {
                        const overflow = index >= limit;
                        entry.classList.toggle('fi-record-details-overflow', overflow);
                        entry.closest('.fi-grid-col')?.classList.toggle('fi-record-details-overflow', overflow);
                    });
                },
                bindOverflowTooltips() {
                    this.$el.querySelectorAll('.fi-in-entry-label').forEach((node) => {
                        if (node.closest('[data-inline-field="name"]') || node.classList.contains('fi-sr-only')) {
                            return;
                        }

                        const text = (node.innerText || '').replace(/\s+/g, ' ').trim();
                        const truncated = text !== '' && node.scrollWidth > node.clientWidth + 1;

                        node.removeAttribute('title');

                        const tippy = node._tippy ?? node.__x_tippy;

                        if (! truncated) {
                            tippy?.disable();

                            return;
                        }

                        if (tippy) {
                            tippy.enable();
                            tippy.setContent(text);
                            tippy.setProps({ theme: this.$store.theme });

                            return;
                        }

                        if (bound.has(node)) {
                            return;
                        }

                        bound.add(node);

                        window.Alpine.bind(node, () => ({
                            'x-tooltip'() {
                                return {
                                    content: text,
                                    theme: window.Alpine.store('theme'),
                                    delay: [500, 0],
                                    placement: 'top',
                                };
                            },
                        }));
                    });
                },
            };
        });
    });
</script>
