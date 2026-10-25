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