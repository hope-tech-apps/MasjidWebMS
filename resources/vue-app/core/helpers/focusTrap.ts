/**
 * Keep Tab and Shift+Tab inside one open dialog.
 *
 * aria-modal tells a screen reader that the page behind a dialog is inert. It does not
 * stop the Tab key from walking into it. Without this, Shift+Tab from a dialog reaches the
 * controls under its backdrop: another dialog's Close button, or the page's form picker.
 *
 * Call it from the dialog root's keydown handler. It acts only on Tab, and moves focus
 * only when the key would otherwise take it out of `root`.
 */
const FOCUSABLE = [
    'a[href]',
    'area[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'iframe',
    '[contenteditable="true"]',
    '[tabindex]:not([tabindex="-1"])'
].join(',');

/** The elements Tab can reach inside `root`, in document order, skipping hidden ones. */
export function focusableIn(root: HTMLElement): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE))
        .filter(element => !element.closest('[inert]') && element.getClientRects().length > 0);
}

export function trapTab(event: KeyboardEvent, root: HTMLElement | null): void {
    if (event.key !== 'Tab' || !root) return;

    const focusable = focusableIn(root);

    if (!focusable.length) {
        event.preventDefault();
        root.focus();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    const outside = !active || !root.contains(active);

    if (event.shiftKey && (outside || active === first || active === root)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (outside || active === last)) {
        event.preventDefault();
        first.focus();
    }
}
