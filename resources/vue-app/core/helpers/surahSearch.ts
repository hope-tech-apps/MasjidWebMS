/**
 * Type-to-find a surah, by its number or by part of its name.
 *
 * The teacher's Hifdh form chose the surah from a plain list of 114, in mushaf
 * order: a Qur'an teacher logging a recitation scrolled for it every time. The
 * list itself still comes from the server (`GET .../quran-surahs`), so the
 * screen carries no copy of the names or the ayah counts; this file only decides
 * which of the server's rows a few typed characters mean. No Vue and no HTTP in
 * it, so `npm run test:spa` covers it.
 */

export interface Surah {
    number: number;
    name: string;
    ayahs: number;
}

/** A surah as the list has always written it: "36 · Ya-Sin (83)". */
export const surahLabel = (s: Surah): string => `${s.number} · ${s.name} (${s.ayahs})`;

/**
 * One spelling to compare by: lower case, no accents, no hyphen, apostrophe or
 * space, and the doubled vowels people type for a long one folded to the single
 * letter the list uses ("yaseen" finds Ya-Sin, "imraan" finds Ali 'Imran). Both
 * the typed text and the name go through it, so it can only make them meet.
 */
export function foldSurahText(text: string): string {
    return text
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '')
        .replace(/ee/g, 'i').replace(/oo/g, 'u').replace(/ou/g, 'u').replace(/aa/g, 'a');
}

/** The name without its article ("An-Naba" is found by "naba"). "Ali 'Imran" has none. */
const bareName = (name: string): string => foldSurahText(name.replace(/^A[a-z]{1,2}-/, ''));

/**
 * The surahs a typed text means, best first.
 *
 *  - Nothing typed: every surah, in order (the list as it always was).
 *  - Digits are the NUMBER, read from its first digit: "3" is 3, then 30 to 39;
 *    "36" is 36. The exact number comes first. Leading zeros are ignored.
 *  - Letters are part of the NAME, anywhere in it; a name that STARTS with them
 *    (with or without its article) comes before one that only contains them.
 *  - Both together ("2 baq") must both hold.
 *
 * Within a rank the mushaf order is kept.
 */
export function matchSurahs(surahs: Surah[], query: string): Surah[] {
    const typedDigits = (query.match(/\d/g) ?? []).join('');
    const digits = typedDigits.replace(/^0+/, '');
    const letters = foldSurahText(query.replace(/\d/g, ''));

    if (!typedDigits && !letters) return surahs.slice();
    // Only zeros: a number no surah has.
    if (typedDigits && !digits) return [];

    const ranked: { surah: Surah; rank: number }[] = [];

    for (const surah of surahs) {
        let rank = 0;

        if (digits) {
            const number = String(surah.number);
            if (!number.startsWith(digits)) continue;
            if (number !== digits) rank += 1;
        }

        if (letters) {
            const full = foldSurahText(surah.name);
            if (!full.includes(letters)) continue;
            if (!full.startsWith(letters) && !bareName(surah.name).startsWith(letters)) rank += 2;
        }

        ranked.push({ surah, rank });
    }

    return ranked.sort((a, b) => a.rank - b.rank || a.surah.number - b.surah.number).map((r) => r.surah);
}

/**
 * What typed text chooses when the teacher moves on without picking from the
 * list (Tab to the ayah boxes): the surah whose NUMBER is exactly what was typed,
 * or the only surah the text matches. Anything less certain chooses nothing, and
 * the box goes back to the surah it held: a guess here would log a recitation
 * against the wrong surah.
 */
export function surahOnLeave(surahs: Surah[], query: string): Surah | null {
    const text = query.trim();
    if (!text) return null;

    if (/^\d+$/.test(text)) {
        const number = Number(text);
        return surahs.find((s) => s.number === number) ?? null;
    }

    const matches = matchSurahs(surahs, text);
    return matches.length === 1 ? matches[0] : null;
}
