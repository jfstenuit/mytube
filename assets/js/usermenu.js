//<![CDATA[
// User menu dropdown in the site header
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.querySelector('.user-menu-toggle');
    if (!toggle) return;
    const menu = document.getElementById(toggle.getAttribute('aria-controls'));
    const items = () => Array.from(menu.querySelectorAll('[role="menuitem"]'));

    function open(focusFirst) {
        menu.removeAttribute('hidden');
        toggle.setAttribute('aria-expanded', 'true');
        if (focusFirst) items()[0]?.focus();
    }

    function close(restoreFocus) {
        menu.setAttribute('hidden', '');
        toggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus) toggle.focus();
    }

    const isOpen = () => !menu.hasAttribute('hidden');

    toggle.addEventListener('click', () => isOpen() ? close(false) : open(false));

    toggle.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            open(true);
        }
    });

    menu.addEventListener('keydown', (e) => {
        const list = items();
        const i = list.indexOf(document.activeElement);
        if (e.key === 'Escape') {
            close(true);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            list[(i + 1) % list.length].focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            list[(i - 1 + list.length) % list.length].focus();
        }
    });

    // Close when clicking outside or when focus leaves the menu
    document.addEventListener('click', (e) => {
        if (isOpen() && !toggle.contains(e.target) && !menu.contains(e.target)) close(false);
    });
    menu.addEventListener('focusout', (e) => {
        if (e.relatedTarget && !menu.contains(e.relatedTarget) && e.relatedTarget !== toggle) close(false);
    });
});
//]]>
