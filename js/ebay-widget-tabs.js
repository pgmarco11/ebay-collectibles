(() => {
    function activate(tab) {
        const section = tab.closest('.tcs-ebay-section');

        section.querySelectorAll('[role="tab"]').forEach(button => {
            const active = button === tab;
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
        });

        section.querySelectorAll('[role="tabpanel"]').forEach(panel => {
            panel.hidden = panel.id !== tab.getAttribute('aria-controls');
        });
    }

    document.addEventListener('click', event => {
        const tab = event.target.closest('.tcs-ebay-tab');

        if (!tab || !tab.closest('.tcs-ebay-page')) return;

        activate(tab);
    });

    document.addEventListener('keydown', event => {
        const tab = event.target.closest('.tcs-ebay-tab');

        if (!tab || !tab.closest('.tcs-ebay-page')) return;

        const tabs = Array.from(
            tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]')
        );

        let index = tabs.indexOf(tab);

        if (event.key === 'ArrowRight') {
            index = (index + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            index = (index - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            index = 0;
        } else if (event.key === 'End') {
            index = tabs.length - 1;
        } else {
            return;
        }

        event.preventDefault();
        activate(tabs[index]);
        tabs[index].focus();
    });
})();

document.addEventListener('click', function (event) {
    const button = event.target.closest(
        '.ebay-top-widget .ebay-tab'
    );

    if (!button) return;

    const widget = button.closest('.ebay-top-widget');

    const panels = Array.from(widget.querySelectorAll(
        '.ebay-tab-content[data-tab-panel]'
    ));

    const target = panels.find(
        panel => panel.dataset.tabPanel === button.dataset.tab
    );

    if (!target) return;

    event.preventDefault();

    widget.querySelectorAll('.ebay-tab').forEach(tab => {
        const active = tab === button;

        tab.classList.toggle('active', active);
        tab.setAttribute('aria-pressed', String(active));
    });

    panels.forEach(panel => {
        const active = panel === target;

        panel.hidden = !active;
        panel.classList.toggle('active', active);
    });
});

document.querySelectorAll('[data-tcs-ebay-auction-pending]').forEach(placeholder => {
    const { category, url, nonce } = placeholder.dataset;
    const loadingMarkup = placeholder.innerHTML;
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

    async function poll() {
        for (let attempt = 0; attempt < 20; attempt++) {
            await delay(3000);

            if (!placeholder.isConnected) return;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout(5000),
                    body: new URLSearchParams({
                        action: 'tcs_poll_ebay_auction_widget',
                        category,
                        nonce
                    })
                });

                if (response.status === 403) {
                    placeholder.textContent = 'The auction loading session expired.';
                    return;
                }

                if (!response.ok) continue;

                const result = await response.json();

                if (result.success && result.data.pending === false) {
                    placeholder.outerHTML = result.data.html;
                    return;
                }
            } catch {
                // Retry a timed-out or interrupted cache check.
            }
        }

        if (!placeholder.isConnected) return;

        placeholder.textContent = 'eBay listings are taking longer than expected. ';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Check again';

        retry.addEventListener('click', () => {
            placeholder.innerHTML = loadingMarkup;
            poll();
        }, { once: true });

        placeholder.append(retry);
    }

    poll();
});

document.querySelectorAll('[data-tcs-ebay-top-items-pending]').forEach(placeholder => {
    const { postId, limit, type, url, nonce } = placeholder.dataset;
    const loadingMarkup = placeholder.innerHTML;
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

    async function poll() {
        for (let attempt = 0; attempt < 20; attempt++) {
            await delay(3000);
            if (!placeholder.isConnected) return;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout(5000),
                    body: new URLSearchParams({
                        action: 'tcs_poll_ebay_top_items',
                        post_id: postId,
                        limit,
                        type,
                        nonce
                    })
                });

                if (response.status === 403) {
                    placeholder.textContent = 'The auction loading session expired.';
                    return;
                }

                if (!response.ok) continue;

                const result = await response.json();

                if (result.success && result.data.pending === false) {
                    placeholder.outerHTML = result.data.html;
                    return;
                }
            } catch {
                // Continue checking after a connection error or timeout.
            }
        }

        if (!placeholder.isConnected) return;

        placeholder.textContent = 'eBay listings are taking longer than expected. ';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Check again';

        retry.addEventListener('click', () => {
            placeholder.innerHTML = loadingMarkup;
            poll();
        }, { once: true });

        placeholder.append(retry);
    }

    poll();
});