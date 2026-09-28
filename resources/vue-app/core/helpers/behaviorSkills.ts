/**
 * The order a behaviour-skill picker reads in, and which skill it opens on.
 *
 * The server already returns the vocabulary in this order (BehaviorSkill::
 * scopeInPickerOrder: positives, then negatives, then anything else, by label).
 * These helpers exist because the teacher's screen ALSO builds the list itself:
 * a skill a teacher adds is appended locally, and an old cached payload may
 * predate the server ordering. Either would otherwise leave "Disruption" above
 * "Kindness" or open the picker on a negative skill, which is one tap from
 * awarding the wrong thing.
 *
 * Kept as plain functions with no Vue in them so `npm run test:spa` covers them.
 */

export interface PickerSkill {
    id: string | number;
    label?: string | null;
    polarity?: string | null;
}

/** 0 positive, 1 negative, 2 anything unrecognised: the server's CASE, spelled the same way. */
export function polarityRank(polarity: string | null | undefined): number {
    if (polarity === 'positive') return 0;
    if (polarity === 'negative') return 1;
    return 2;
}

/** A copy of the list in picker order (never mutates the argument). */
export function inPickerOrder<T extends PickerSkill>(skills: readonly T[]): T[] {
    return [...skills].sort((a, b) => {
        const byPolarity = polarityRank(a.polarity) - polarityRank(b.polarity);
        if (byPolarity !== 0) return byPolarity;
        return String(a.label ?? '').localeCompare(String(b.label ?? ''), undefined, { sensitivity: 'base' });
    });
}

/** Add a skill and keep the list in picker order. */
export function withSkillInserted<T extends PickerSkill>(skills: readonly T[], created: T): T[] {
    return inPickerOrder([...skills.filter((s) => String(s.id) !== String(created.id)), created]);
}

/**
 * The skill the picker opens on: the first POSITIVE one. Falls back to the first
 * skill of any kind only when the school has no positive skill at all, so the
 * picker is never empty because of this rule.
 */
export function defaultSkillId<T extends PickerSkill>(skills: readonly T[]): string | number | '' {
    const ordered = inPickerOrder(skills);
    const positive = ordered.find((s) => s.polarity === 'positive');
    return (positive ?? ordered[0])?.id ?? '';
}

/**
 * What the teacher's screen does with a vocabulary it has just loaded: the list
 * in picker order, and the skill the picker sits on. A skill the teacher has
 * already chosen (`currentId`) is kept; only an empty choice takes the default.
 */
export function pickerFrom<T extends PickerSkill>(
    skills: readonly T[], currentId: string | number | '' | null | undefined,
): { skills: T[]; selectedId: string | number | '' } {
    return {
        skills: inPickerOrder(skills),
        selectedId: currentId ? currentId : defaultSkillId(skills),
    };
}

/**
 * What one award moved a child's total by, for the award LOG (B1).
 *
 * A negative-polarity award is stored as its magnitude (`points = 1`), and every
 * total subtracts it (`BehaviorAward::signedPointsSql()`), so the log row must
 * read "-1" beside a total that went down, not "+1". Any other polarity reads as
 * stored, which is what the server sums: a positive skill given with an override
 * of -3 is -3 in the total and -3 here.
 */
export function signedAwardPoints(award: { polarity?: string | null; points?: number | string | null }): number {
    const points = Number(award?.points ?? 0);

    return award?.polarity === 'negative' ? -Math.abs(points) : points;
}

/** "+2", "-1", "0": the log badge's text for `signedAwardPoints`. */
export function awardPointsLabel(award: { polarity?: string | null; points?: number | string | null }): string {
    const points = signedAwardPoints(award);

    return `${points > 0 ? '+' : ''}${points}`;
}
