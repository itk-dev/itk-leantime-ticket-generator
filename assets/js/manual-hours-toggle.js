/**
 * Toggle visibility of the manual hours input based on the planned hours select.
 *
 * Finds all elements with [data-manual-toggle] and attaches change listeners
 * to show/hide the #manual-hours-wrapper when "Manual" is selected.
 */
document.querySelectorAll('[data-manual-toggle]').forEach(function (select) {
    const wrapper = document.getElementById('manual-hours-wrapper');

    if (!select || !wrapper) {
        return;
    }

    function toggle() {
        const selected = select.options[select.selectedIndex];
        const isManual = selected && selected.textContent.trim().toLowerCase().startsWith('manual');
        wrapper.style.display = isManual ? '' : 'none';
    }

    toggle();
    select.addEventListener('change', toggle);
});
