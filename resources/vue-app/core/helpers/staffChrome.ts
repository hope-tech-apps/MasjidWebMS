import { onBeforeUnmount } from 'vue';

/**
 * Switch on the staff theme (resources/css/custom/theme.css) for as long as a
 * staff layout is mounted: the organisation dashboard, the teacher realm and
 * the lunch realm. The class lives on <body> rather than on the layout because
 * modals, dropdowns and SweetAlert dialogs render outside the layout's element.
 *
 * Counted, not toggled: when one staff layout replaces another, the incoming
 * one's setup runs before the outgoing one unmounts, and a plain remove would
 * take the theme away from the page that is arriving.
 */
let mounted = 0;

export function useStaffChrome(): void {
    mounted += 1;
    document.body.classList.add('mn-app');

    onBeforeUnmount(() => {
        mounted = Math.max(0, mounted - 1);
        if (mounted === 0) {
            document.body.classList.remove('mn-app');
        }
    });
}
