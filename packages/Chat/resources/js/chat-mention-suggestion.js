const SUGGESTION_DEBOUNCE_MS = 150;
const MIN_QUERY_LENGTH = 2;

export function createMentionSuggestion() {
    // Token-based supersede: each fetchResults call increments fetchToken; only
    // the call whose token still matches at await-time renders. Avoids
    // AbortController pattern where aborted promises occasionally surface as
    // unhandled rejections in a TipTap mutation-observer flush.
    let fetchToken = 0;
    let popupEl = null;
    let activeIndex = 0;
    let items = [];
    let onSelect = null;
    let debounceTimer = null;

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function typeLabel(t) {
        return ({ company: 'Company', people: 'Person', opportunity: 'Deal', task: 'Task', note: 'Note' })[t] || t;
    }

    function renderPopup({ query, fetching, error, results, activeIdx, onPick }) {
        if (!popupEl) {
            popupEl = document.createElement('div');
            popupEl.setAttribute('role', 'listbox');
            popupEl.setAttribute('aria-label', 'Mention suggestions');
            popupEl.className = 'absolute z-50 mb-2 max-h-64 overflow-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800';
            popupEl.style.minWidth = '14rem';
        }

        let html = '';
        if (fetching && results.length === 0) {
            html = `<div class="px-3 py-3 text-center text-xs text-gray-500 dark:text-gray-400"><span class="inline-flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full bg-primary-500"></span>Searching…</span></div>`;
        } else if (error) {
            html = `<div class="px-3 py-3 text-center text-xs text-red-600 dark:text-red-400" role="alert">Couldn't load suggestions.</div>`;
        } else if (results.length === 0) {
            html = `<div class="px-3 py-3 text-center text-xs text-gray-500 dark:text-gray-400">No matches for "${escapeHtml(query)}".</div>`;
        } else {
            html = results.map((item, idx) => {
                const active = idx === activeIdx ? 'bg-primary-50 dark:bg-primary-900/30' : '';
                return `<button type="button" role="option" data-idx="${idx}"
                    class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700 ${active}">
                    <span class="truncate">${escapeHtml(item.label)}</span>
                    <span class="text-xs uppercase text-gray-400">${escapeHtml(typeLabel(item.type))}</span>
                </button>`;
            }).join('');
        }

        popupEl.innerHTML = html;
        popupEl.querySelectorAll('button[role="option"]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const idx = Number(btn.getAttribute('data-idx'));
                onPick?.(results[idx]);
            });
        });

        return popupEl;
    }

    async function fetchResults(query) {
        const myToken = ++fetchToken;

        try {
            const res = await fetch('/chat/mentions?q=' + encodeURIComponent(query), {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (myToken !== fetchToken) return null; // superseded by a newer query
            if (!res.ok) return { results: [], error: true };
            const body = await res.json();
            if (myToken !== fetchToken) return null;
            return {
                results: (body.data || []).map((item) => ({ type: item.type, id: item.id, label: item.name, url: item.url ?? null })),
                error: false,
            };
        } catch (_e) {
            if (myToken !== fetchToken) return null;
            return { results: [], error: true };
        }
    }

    function debouncedFetch(query, render) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            // Use .then so any unexpected rejection is caught here and not
            // surfaced as "Uncaught (in promise)" inside ProseMirror's flush.
            fetchResults(query)
                .then((result) => {
                    if (result === null) return;
                    render(result);
                })
                .catch(() => {
                    render({ results: [], error: true });
                });
        }, SUGGESTION_DEBOUNCE_MS);
    }

    function positionPopup(rect) {
        if (!popupEl || !rect) return;
        const popupHeight = popupEl.offsetHeight || 64;
        const popupWidth = popupEl.offsetWidth || 224;
        const gap = 8;
        const viewportH = window.innerHeight;
        const viewportW = window.innerWidth;

        // Prefer above cursor; flip below when insufficient room above.
        const roomAbove = rect.top - gap;
        const placeBelow = roomAbove < popupHeight && (viewportH - rect.bottom) > roomAbove;

        const rawTop = placeBelow
            ? window.scrollY + rect.bottom + gap
            : window.scrollY + rect.top - popupHeight - gap;

        // Clamp top: above-cursor placement with many results can overflow
        // the viewport top (e.g. 10 items ≈ 256px tall, cursor at y=260
        // gives rawTop ≈ -4). Keep popup at least gap below scrollY.
        const top = Math.max(window.scrollY + gap, rawTop);

        // Clamp left so the popup doesn't overflow narrow viewports.
        // The Math.max around the upper bound prevents a negative right-edge
        // anchor when viewportW < popupWidth + 2 * gap (very narrow viewports).
        const rawLeft = window.scrollX + rect.left;
        const left = Math.min(
            Math.max(window.scrollX + gap, rawLeft),
            Math.max(window.scrollX + gap, window.scrollX + viewportW - popupWidth - gap),
        );

        popupEl.style.position = 'absolute';
        popupEl.style.left = `${left}px`;
        popupEl.style.top = `${top}px`;
    }

    return {
        char: '@',
        allowSpaces: true,
        items: () => items,
        render: () => {
            let clientRect;

            return {
                onStart: (props) => {
                    clientRect = props.clientRect;
                    if (!props.query || props.query.length < MIN_QUERY_LENGTH) return;

                    activeIndex = 0;
                    items = [];
                    onSelect = (item) => props.command({ id: item.id, type: item.type, label: item.label, url: item.url ?? null });

                    document.body.appendChild(renderPopup({
                        query: props.query, fetching: true, error: false,
                        results: [], activeIdx: activeIndex, onPick: onSelect,
                    }));
                    positionPopup(clientRect());

                    debouncedFetch(props.query, ({ results, error }) => {
                        items = results;
                        renderPopup({ query: props.query, fetching: false, error, results, activeIdx: activeIndex, onPick: onSelect });
                        positionPopup(clientRect()); // re-position after content height changes
                    });
                },

                onUpdate: (props) => {
                    clientRect = props.clientRect;
                    onSelect = (item) => props.command({ id: item.id, type: item.type, label: item.label, url: item.url ?? null });

                    if (!props.query || props.query.length < MIN_QUERY_LENGTH) {
                        if (popupEl?.parentNode) popupEl.parentNode.removeChild(popupEl);
                        return;
                    }

                    // popupEl may not exist yet if onStart bailed early (query too short),
                    // so always go through renderPopup which lazily creates it.
                    const el = renderPopup({
                        query: props.query,
                        fetching: items.length === 0,
                        error: false,
                        results: items,
                        activeIdx: activeIndex,
                        onPick: onSelect,
                    });

                    if (!el.parentNode) document.body.appendChild(el);
                    positionPopup(clientRect());

                    debouncedFetch(props.query, ({ results, error }) => {
                        items = results;
                        renderPopup({ query: props.query, fetching: false, error, results, activeIdx: activeIndex, onPick: onSelect });
                        positionPopup(clientRect());
                    });
                },

                onKeyDown: (props) => {
                    if (props.event.key === 'Escape') {
                        if (popupEl?.parentNode) popupEl.parentNode.removeChild(popupEl);
                        return true;
                    }
                    if (props.event.key === 'ArrowDown' && items.length > 0) {
                        activeIndex = (activeIndex + 1) % items.length;
                        renderPopup({ query: '', fetching: false, error: false, results: items, activeIdx: activeIndex, onPick: onSelect });
                        return true;
                    }
                    if (props.event.key === 'ArrowUp' && items.length > 0) {
                        activeIndex = (activeIndex - 1 + items.length) % items.length;
                        renderPopup({ query: '', fetching: false, error: false, results: items, activeIdx: activeIndex, onPick: onSelect });
                        return true;
                    }
                    // Always swallow Enter and Tab while the suggestion is active.
                    // If we have a selectable item, pick it; otherwise just block the
                    // event so the editor's submit-on-Enter handler doesn't fire
                    // mid-mention. Without this, typing "Hello @" + Enter (query below
                    // MIN_QUERY_LENGTH, fetch in flight, or no matches) submits the
                    // message instead of giving the user a chance to finish typing.
                    if (props.event.key === 'Enter' || props.event.key === 'Tab') {
                        if (items.length > 0) {
                            onSelect?.(items[activeIndex]);
                        }
                        return true;
                    }
                    return false;
                },

                onExit: () => {
                    if (popupEl?.parentNode) popupEl.parentNode.removeChild(popupEl);
                },
            };
        },
    };
}
