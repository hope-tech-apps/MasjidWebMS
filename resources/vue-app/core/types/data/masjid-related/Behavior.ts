/**
 * Behaviour / recognition — the Classroom module's points layer.
 *
 * TWO SHAPES IN THIS FILE ARE DESIGN CONSTRAINTS, NOT OVERSIGHTS:
 *
 * 1. There is NO leaderboard type, no rank field and no cross-student
 *    comparison payload, because the API serves none. A child's record reaches
 *    the group's leaders, the student, and THAT student's own guardians —
 *    never another guardian in the same group, and never a class-wide ranking.
 *    Public behaviour tallies are the loudest documented harm of the product
 *    this module answers; being the opposite is the differentiator. Do not add
 *    a ranking type here and do not derive one in a component.
 * 2. Nothing is paywalled: no `plan`, `tier` or `is_premium` exists on either
 *    side of the wire.
 *
 * See .claude/rules/groups.md.
 */

import { GroupContact } from "./Group";

/** Mirrors `BehaviorSkill::POLARITIES` — whether a skill recognises or corrects. */
export type BehaviorPolarity = 'positive' | 'negative';

/** The per-tenant vocabulary. Holds no data about any child, so it is not group-scoped. */
export type BehaviorSkill = {
    id: number;
    masjid_id: number;
    label: string;
    polarity: BehaviorPolarity;
    default_points: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

/** Shape submitted by the skill create/edit form. */
export type BehaviorSkillPayload = {
    label: string;
    polarity: BehaviorPolarity;
    default_points: number;
    is_active: boolean;
};

/** The student a record concerns, as the membership edge that names them. */
export type BehaviorStudent = {
    membership_id: number;
    contact: GroupContact | null;
};

/**
 * One skill given to ONE student.
 *
 * `skill_label`, `polarity` and `points` are SNAPSHOTS taken when the award was
 * given: renaming or re-weighting a skill describes next term, it does not
 * restate what a child was told in October. `behavior_skill_id` is provenance
 * only and nulls when the vocabulary entry is deleted — never re-read the skill
 * for a label or a weight.
 */
export type BehaviorAward = {
    id: number;
    group_id: number;
    student: BehaviorStudent | null;
    skill_label: string;
    polarity: BehaviorPolarity;
    points: number;
    note: string | null;
    awarded_at: string | null;
    awarded_by: { id: number; name: string } | null;
    behavior_skill_id: number | null;
    /** `deleted_at` IS the revocation clock; there is no parallel flag. */
    revoked_at: string | null;
    retained_until: string | null;
    created_at: string | null;
};

/** Shape submitted when awarding a skill. `points` omitted uses the skill's default. */
export type BehaviorAwardPayload = {
    membership_id: number;
    behavior_skill_id: number;
    points: number | null;
    note: string;
};

/** One row of a student's own totals, per snapshotted skill label. */
export type BehaviorSkillTotal = {
    skill_label: string;
    polarity: BehaviorPolarity;
    awards: number;
    points: number;
};

export type BehaviorPolarityTotal = {
    awards: number;
    points: number;
};

/**
 * Totals for ONE student — the report a teacher reads before a parents' evening.
 *
 * Deliberately per student and never per class. `totals.points` is a NET of
 * snapshotted values, not a score, and nothing in this module ever puts two
 * students' numbers side by side.
 */
export type BehaviorAwardSummary = {
    student: BehaviorStudent;
    range: { from: string | null; to: string | null };
    totals: { awards: number; points: number };
    by_polarity: Record<BehaviorPolarity, BehaviorPolarityTotal>;
    by_skill: BehaviorSkillTotal[];
};

/** `meta` on the behaviour endpoints. */
export type BehaviorMeta = {
    group_label: string;
    polarities: BehaviorPolarity[];
    max_points: number;
    max_note_length?: number;
};
