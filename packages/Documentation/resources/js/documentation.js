/**
 * Behaviour for the documentation shell: client-side search over the
 * pre-built index, copy buttons on code blocks, and the on-this-page rail.
 *
 * Search is exposed on `window` because the shell drives it from Alpine —
 * the ranking stays here so the Blade template holds markup, not algorithms.
 */

const SNIPPET_RADIUS = 70;
const RESULT_LIMIT = 12;
const INDEX_VERSION = 2;

function terms(query) {
    return (query.toLowerCase().match(/[\p{L}\p{N}]+/gu) ?? []).filter((term) => term.length > 1);
}

function fieldScore(haystack, term, wordWeight, partWeight) {
    const at = haystack.indexOf(term);

    if (at === -1) {
        return 0;
    }

    const startsWord = at === 0 || !/[\p{L}\p{N}]/u.test(haystack[at - 1]);

    return startsWord ? wordWeight : partWeight;
}

function scoreRecord(record, queryTerms, phrase) {
    let score = 0;

    for (const term of queryTerms) {
        const hit =
            fieldScore(record.lowerTitle, term, 12, 6) +
            fieldScore(record.lowerSection, term, 8, 4) +
            fieldScore(record.lowerContent, term, 2, 1);

        // Every term has to land somewhere, or "invite stripe" would match a
        // page about invites that never mentions Stripe.
        if (hit === 0) {
            return 0;
        }

        score += hit;
    }

    if (phrase.length > 2) {
        if (record.lowerTitle.includes(phrase)) score += 30;
        if (record.lowerSection.includes(phrase)) score += 20;
        if (record.lowerContent.includes(phrase)) score += 8;
    }

    // A tie between a page's opening and one of its sections goes to the page.
    if (record.anchor === '') {
        score += 2;
    }

    return score;
}

function buildSnippet(content, queryTerms) {
    if (content === '') {
        return [{ text: '', mark: false }];
    }

    const pattern = queryTerms
        .map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('|');

    if (pattern === '') {
        return [{ text: content.slice(0, SNIPPET_RADIUS * 2), mark: false }];
    }

    const first = new RegExp(pattern, 'iu').exec(content);

    if (first === null) {
        return [{ text: content.slice(0, SNIPPET_RADIUS * 2), mark: false }];
    }

    const start = Math.max(0, first.index - SNIPPET_RADIUS);
    const end = Math.min(content.length, first.index + first[0].length + SNIPPET_RADIUS);
    const window = (start > 0 ? '…' : '') + content.slice(start, end) + (end < content.length ? '…' : '');

    const segments = [];
    let cursor = 0;

    for (const match of window.matchAll(new RegExp(pattern, 'giu'))) {
        if (match.index > cursor) {
            segments.push({ text: window.slice(cursor, match.index), mark: false });
        }

        segments.push({ text: match[0], mark: true });
        cursor = match.index + match[0].length;
    }

    if (cursor < window.length) {
        segments.push({ text: window.slice(cursor), mark: false });
    }

    return segments;
}

window.RelaticleDocs = {
    async loadIndex(url) {
        const payload = await fetch(url).then((response) => response.json());

        if (payload?.v !== INDEX_VERSION || !Array.isArray(payload.records)) {
            throw new Error('Unsupported documentation search index payload');
        }

        return payload.records.map((record, index) => ({
            ...record,
            id: `${index}:${record.path}#${record.anchor}`,
            lowerTitle: record.title.toLowerCase(),
            lowerSection: record.section.toLowerCase(),
            lowerContent: record.content.toLowerCase(),
        }));
    },

    search(records, query) {
        if (!Array.isArray(records)) {
            return [];
        }

        const phrase = query.trim().toLowerCase();

        // An empty box lists the pages themselves — somewhere to start rather
        // than an empty panel.
        if (phrase === '') {
            return records
                .filter((record) => record.anchor === '')
                .slice(0, RESULT_LIMIT)
                .map((record) => ({ ...record, snippet: [{ text: record.content.slice(0, 120), mark: false }] }));
        }

        const queryTerms = terms(phrase);

        if (queryTerms.length === 0) {
            return [];
        }

        return records
            .map((record) => ({ record, score: scoreRecord(record, queryTerms, phrase) }))
            .filter((scored) => scored.score > 0)
            .sort((a, b) => b.score - a.score)
            .slice(0, RESULT_LIMIT)
            .map(({ record }) => ({ ...record, snippet: buildSnippet(record.content, queryTerms) }));
    },
};

const COPY_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
const CHECK_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';

function addCopyButtons(root) {
    root.querySelectorAll('pre').forEach((pre) => {
        if (pre.parentElement?.classList.contains('code-block-wrapper')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'code-block-wrapper';
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(pre);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'code-copy';
        button.setAttribute('aria-label', 'Copy code to clipboard');
        button.innerHTML = COPY_ICON;

        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(pre.querySelector('code')?.innerText ?? pre.innerText);
                button.classList.add('copied');
                button.innerHTML = CHECK_ICON;
                setTimeout(() => {
                    button.classList.remove('copied');
                    button.innerHTML = COPY_ICON;
                }, 1500);
            } catch {
                // Clipboard denied (insecure context or blocked permission) —
                // the code is still selectable, so there is nothing to recover.
            }
        });

        wrapper.appendChild(button);
    });
}

function trackTableOfContents() {
    const toc = document.getElementById('docs-toc');

    if (!toc) {
        return;
    }

    const targets = [...toc.querySelectorAll('a[href^="#"]')]
        .map((link) => {
            const anchor = document.getElementById(decodeURIComponent(link.getAttribute('href').slice(1)));

            // The renderer puts the id on a permalink anchor inside the
            // heading, and that anchor is absolutely positioned — measure the
            // heading instead.
            return anchor ? { link, element: anchor.closest('h2, h3, h4') ?? anchor } : null;
        })
        .filter(Boolean);

    if (targets.length === 0) {
        return;
    }

    let active = null;

    const sync = () => {
        const threshold = 120;
        let current = targets[0];

        for (const target of targets) {
            if (target.element.getBoundingClientRect().top <= threshold) {
                current = target;
            }
        }

        if (current === active) {
            return;
        }

        active?.link.removeAttribute('data-active');
        current.link.setAttribute('data-active', '');
        active = current;
    };

    let queued = false;

    const onScroll = () => {
        if (queued) {
            return;
        }

        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            sync();
        });
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    sync();
}

document.addEventListener('DOMContentLoaded', () => {
    const content = document.getElementById('documentation-content');

    if (content) {
        addCopyButtons(content);
    }

    trackTableOfContents();
});
