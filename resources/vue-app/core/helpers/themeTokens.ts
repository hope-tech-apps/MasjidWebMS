/**
 * The Brand Studio's website fonts and header/footer style, as the theme `tokens` they
 * are saved in. Pure, so tests/theme-tokens.test.ts can prove each rule.
 *
 * THE SAVE REPLACES THE WHOLE TOKEN TREE (ThemeSettingsController@save), so every change
 * here starts from the saved tree and touches only what the admin changed:
 *   - typography is rebuilt only when a FONT choice changed; changing the header or
 *     footer style never touches it;
 *   - a font the list does not know (set elsewhere, e.g. by Studio) is kept as a choice,
 *     and while it is chosen its stylesheet is kept: the saved Google Fonts URL's
 *     families stay in the rebuilt URL beside any list font. The renderer loads ONLY
 *     typography.fontsUrl (layouts/default.vue), so dropping a family from it drops the
 *     face from every public page — the bug this file exists to prevent;
 *   - nothing is sent at all until one of these controls changes, so an admin who only
 *     edits colours posts exactly what this screen always posted.
 */

export type FontChoice = { key: string; label: string; stack: string | null; google: string | null };

export const FONTS: FontChoice[] = [
    { key: '', label: 'Site default', stack: null, google: null },
    { key: 'poppins', label: 'Poppins', stack: "'Poppins', sans-serif", google: 'Poppins:wght@400;500;600;700' },
    { key: 'inter', label: 'Inter', stack: "'Inter', sans-serif", google: 'Inter:wght@400;500;600;700' },
    { key: 'lato', label: 'Lato', stack: "'Lato', sans-serif", google: 'Lato:wght@400;700' },
    { key: 'montserrat', label: 'Montserrat', stack: "'Montserrat', sans-serif", google: 'Montserrat:wght@400;500;600;700' },
    { key: 'open-sans', label: 'Open Sans', stack: "'Open Sans', sans-serif", google: 'Open Sans:wght@400;600;700' },
    { key: 'merriweather', label: 'Merriweather', stack: "'Merriweather', serif", google: 'Merriweather:wght@400;700' },
    { key: 'playfair', label: 'Playfair Display', stack: "'Playfair Display', serif", google: 'Playfair Display:wght@400;600;700' },
    { key: 'amiri', label: 'Amiri (Arabic)', stack: "'Amiri', serif", google: 'Amiri:wght@400;700' },
    { key: 'noto-naskh', label: 'Noto Naskh Arabic', stack: "'Noto Naskh Arabic', serif", google: 'Noto Naskh Arabic:wght@400;600;700' },
    { key: 'cairo', label: 'Cairo (Arabic)', stack: "'Cairo', sans-serif", google: 'Cairo:wght@400;600;700' },
];

export const CURRENT = '__current';

export type Tokens = Record<string, any>;
export type Face = 'heading' | 'body';
export interface StyleModel { heading: string; body: string; header: string; footer: string }
export interface Touched { fonts: boolean; layout: boolean }

const familyKey = (face: Face) => (face === 'heading' ? 'headingFamily' : 'bodyFamily');

/** The list, plus the saved font as a choice when it is not one of ours. */
export const fontChoices = (saved: Tokens | null, face: Face): FontChoice[] => {
    const stack = saved?.typography?.[familyKey(face)];
    if (typeof stack === 'string' && stack !== 'system' && !FONTS.some((f) => f.stack === stack)) {
        return [...FONTS, { key: CURRENT, label: `Current: ${stack}`, stack, google: null }];
    }
    return FONTS;
};

export const styleFromTokens = (saved: Tokens | null): StyleModel => {
    const pick = (stack: unknown) => {
        if (typeof stack !== 'string' || stack === 'system') return '';
        return FONTS.find((f) => f.stack === stack)?.key ?? CURRENT;
    };
    return {
        heading: pick(saved?.typography?.headingFamily),
        body: pick(saved?.typography?.bodyFamily),
        header: saved?.layout?.header === 'overlay' ? 'overlay' : 'default',
        footer: saved?.layout?.footer === 'columns' ? 'columns' : 'default',
    };
};

/** The `family=` values of a Google Fonts css2 URL, or null when it is not one. */
export const googleCss2Families = (url: unknown): string[] | null => {
    if (typeof url !== 'string') return null;
    let parsed: URL;
    try {
        parsed = new URL(url);
    } catch {
        return null;
    }
    if (parsed.protocol !== 'https:' || parsed.hostname !== 'fonts.googleapis.com' || parsed.pathname !== '/css2') return null;
    return parsed.searchParams.getAll('family');
};

const familyName = (spec: string) => spec.split(':')[0]!.trim().toLowerCase();

export const buildTokens = (saved: Tokens | null, style: StyleModel, touched: Touched): Tokens => {
    const tree: Tokens = JSON.parse(JSON.stringify(saved ?? {}));

    if (touched.fonts) {
        const typography: Tokens = { ...(tree.typography ?? {}) };
        const chosen = (face: Face) => fontChoices(saved, face).find((f) => f.key === style[face]) ?? FONTS[0]!;
        const heading = chosen('heading');
        const body = chosen('body');

        for (const [face, font] of [['heading', heading], ['body', body]] as const) {
            if (font.stack) typography[familyKey(face)] = font.stack;
            else delete typography[familyKey(face)];
        }

        const listFamilies = [heading.google, body.google].filter((g): g is string => !!g);
        const keepsSaved = heading.key === CURRENT || body.key === CURRENT;
        const savedFamilies = keepsSaved ? googleCss2Families(typography.fontsUrl) : [];

        if (keepsSaved && savedFamilies === null) {
            // A saved stylesheet this screen cannot extend (not a css2 URL): keep it as it
            // is, so the saved face still loads. The list face then uses its fallback.
        } else {
            const families: string[] = [];
            for (const spec of [...(savedFamilies ?? []), ...listFamilies]) {
                if (!families.some((f) => familyName(f) === familyName(spec))) families.push(spec);
            }
            if (families.length) {
                typography.fontsUrl = 'https://fonts.googleapis.com/css2?'
                    + families.map((f) => `family=${f.replace(/ /g, '+')}`).join('&') + '&display=swap';
            } else {
                delete typography.fontsUrl;
            }
        }

        if (Object.keys(typography).length) tree.typography = typography;
        else delete tree.typography;
    }

    if (touched.layout) {
        const layout: Tokens = { ...(tree.layout ?? {}) };
        if (style.header === 'overlay') layout.header = 'overlay';
        else delete layout.header;
        if (style.footer === 'columns') layout.footer = 'columns';
        else delete layout.footer;
        if (Object.keys(layout).length) tree.layout = layout;
        else delete tree.layout;
    }

    return tree;
};

/** What Save posts: the colours, and `tokens` only once a font or layout choice changed. */
export const themePayload = <C extends Record<string, unknown>>(
    colours: C,
    saved: Tokens | null,
    style: StyleModel,
    touched: Touched,
): C & { tokens?: Tokens } => ({
    ...colours,
    ...(touched.fonts || touched.layout ? { tokens: buildTokens(saved, style, touched) } : {}),
});
