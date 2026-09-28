/**
 * SweetAlert2 writes `title`, `html`, `footer`, the three button captions and the icon, close
 * and loader HTML into the page AS HTML (setInnerHtml through DOMParser), and the admin CSP
 * allows inline handlers. So a dialog that interpolates data into any of them — an
 * organisation's name, a contact, a tag, a domain — runs whatever markup that data carries:
 * a MasjidAdmin could name their organisation `<img src=x onerror=…>` and read a SuperAdmin's
 * token out of localStorage the moment the SuperAdmin opened a confirm about it.
 *
 * Every dialog goes through Swal.fire (a mixin's fire is the same static, inherited), so the
 * one door is cleaned here, once, at start-up: each of those options is passed through
 * DOMPurify, which keeps the markup the app writes on purpose (an icon in a button, a <b>)
 * and drops scripts and event handlers. A dialog written tomorrow is safe without anyone
 * remembering to write `titleText`.
 */

/** The SweetAlert2 options it renders as HTML (sweetalert2 11: setInnerHtml / parseHtmlToContainer). */
export const SWAL_HTML_KEYS = [
    'title',
    'html',
    'footer',
    'confirmButtonText',
    'cancelButtonText',
    'denyButtonText',
    'iconHtml',
    'closeButtonHtml',
    'loaderHtml',
] as const;

export type Purify = (dirty: string) => string;

/** A copy of `options` with every HTML-rendered string option cleaned. Anything else is untouched. */
export function sanitizeSwalOptions<T>(options: T, purify: Purify): T {
    if (!options || typeof options !== 'object' || isElement(options)) return options;

    const out: Record<string, unknown> = { ...(options as Record<string, unknown>) };
    for (const key of SWAL_HTML_KEYS) {
        if (typeof out[key] === 'string') out[key] = purify(out[key] as string);
    }

    return out as T;
}

/**
 * The arguments to fire(): one options object, or the short form fire(title, html, icon),
 * whose first two are HTML (the icon is a name, not markup).
 */
export function sanitizeSwalArgs(args: unknown[], purify: Purify): unknown[] {
    if (args.length === 0) return args;

    const [first] = args;
    if (first && typeof first === 'object' && !isElement(first)) {
        return [sanitizeSwalOptions(first, purify), ...args.slice(1)];
    }

    return args.map((arg, i) => (i < 2 && typeof arg === 'string' ? purify(arg) : arg));
}

type SwalLike = {
    fire: (...args: unknown[]) => unknown;
    mixin: (params: unknown) => unknown;
    prototype?: { update?: (params: unknown) => unknown };
    __manaraSanitized?: boolean;
};

/**
 * Wrap the static fire and mixin, and the instance update, of the SweetAlert2 constructor.
 * `this` is kept, so a mixin subclass's inherited fire still builds that subclass. Idempotent.
 */
export function installSwalSanitizer(swal: unknown, purify: Purify): void {
    const Swal = swal as SwalLike;
    if (Swal.__manaraSanitized) return;

    const fire = Swal.fire;
    Swal.fire = function (this: unknown, ...args: unknown[]) {
        return fire.apply(this, sanitizeSwalArgs(args, purify));
    };

    const mixin = Swal.mixin;
    Swal.mixin = function (this: unknown, params: unknown) {
        return mixin.call(this, sanitizeSwalOptions(params, purify));
    };

    const update = Swal.prototype?.update;
    if (Swal.prototype && typeof update === 'function') {
        Swal.prototype.update = function (this: unknown, params: unknown) {
            return update.call(this, sanitizeSwalOptions(params, purify));
        };
    }

    Swal.__manaraSanitized = true;
}

function isElement(value: unknown): boolean {
    return typeof HTMLElement !== 'undefined' && value instanceof HTMLElement;
}
