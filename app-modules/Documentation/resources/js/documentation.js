// Copy button functionality for code blocks
function addCopyButtons() {
    const codeBlocks = document.querySelectorAll('pre code');

    codeBlocks.forEach((codeBlock) => {
        const pre = codeBlock.parentElement;

        // Skip if already wrapped
        if (pre.parentElement?.classList.contains('code-block-wrapper')) return;

        // Create wrapper div to hold pre and copy button
        const wrapper = document.createElement('div');
        wrapper.className = 'code-block-wrapper';

        // Insert wrapper before pre and move pre inside
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(pre);

        // Create copy button
        const copyButton = document.createElement('button');
        copyButton.className = 'copy-button';
        copyButton.setAttribute('aria-label', 'Copy code to clipboard');
        copyButton.innerHTML = `
            <svg class="copy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
            <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        `;

        // Add click handler
        copyButton.addEventListener('click', async () => {
            const text = codeBlock.textContent;

            try {
                await navigator.clipboard.writeText(text);

                // Show success state with subtle feedback
                copyButton.classList.add('copied');
                copyButton.querySelector('.copy-icon').style.display = 'none';
                copyButton.querySelector('.check-icon').style.display = 'block';

                // Reset after 1.5 seconds
                setTimeout(() => {
                    copyButton.classList.remove('copied');
                    copyButton.querySelector('.copy-icon').style.display = 'block';
                    copyButton.querySelector('.check-icon').style.display = 'none';
                }, 1500);
            } catch (err) {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.select();

                try {
                    document.execCommand('copy');
                    copyButton.classList.add('copied');
                    copyButton.querySelector('.copy-icon').style.display = 'none';
                    copyButton.querySelector('.check-icon').style.display = 'block';

                    setTimeout(() => {
                        copyButton.classList.remove('copied');
                        copyButton.querySelector('.copy-icon').style.display = 'block';
                        copyButton.querySelector('.check-icon').style.display = 'none';
                    }, 1500);
                } catch (err) {
                    console.error('Failed to copy code:', err);
                }

                document.body.removeChild(textArea);
            }
        });

        // Append button to wrapper (not pre), so it stays fixed during horizontal scroll
        wrapper.appendChild(copyButton);
    });
}

// Simple smooth scrolling for anchor links
document.addEventListener('DOMContentLoaded', function() {
    // Add copy buttons to code blocks
    addCopyButtons();

    // Watch for dynamic content changes
    const observer = new MutationObserver(() => {
        addCopyButtons();
    });

    const contentElement = document.getElementById('documentation-content');
    if (contentElement) {
        observer.observe(contentElement, {
            childList: true,
            subtree: true
        });
    }

    // Add smooth scrolling
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href^="#"]');
        if (link) {
            e.preventDefault();
            const targetId = link.getAttribute('href').substring(1);
            const targetElement = document.getElementById(targetId);

            if (targetElement) {
                // Calculate the element's position relative to the document
                const elementPosition = targetElement.getBoundingClientRect().top + window.scrollY;
                const offsetPosition = elementPosition - 80;

                // Scroll with offset for fixed header
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });

                // Update the URL hash without scrolling
                history.pushState(null, null, `#${targetId}`);
            }
        }
    });
});

// Ensure sidebars can properly scroll
function adjustSidebarHeights() {
    // Calculate available height for sidebars
    const viewportHeight = window.innerHeight;
    const topOffset = 96; // 24 (top-24 in TailwindCSS) = 6rem
    const sidebarMaxHeight = viewportHeight - topOffset;

    // Apply height to sidebar containers if needed
    document.querySelectorAll('.docs-sidebar > div, .toc').forEach(sidebar => {
        if (sidebar) {
            sidebar.style.maxHeight = `${sidebarMaxHeight}px`;
        }
    });
}

// Run on load and resize
window.addEventListener('load', adjustSidebarHeights);
window.addEventListener('resize', adjustSidebarHeights);
