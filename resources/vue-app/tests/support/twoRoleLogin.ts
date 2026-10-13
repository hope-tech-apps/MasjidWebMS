/**
 * The people of the one-login tests, as the server describes them.
 *
 * One person can be Friday-lunch staff at one organisation and a teacher at
 * another. The server answers every `/user` in ONE area's voice (`type`, `masjid`
 * and `memberships` are that area's) and lists every place the login works in
 * `areas`, which is ABSENT for a login with one kind of role. The PHP side pins
 * these shapes in OneLoginTwoRolesRealmTest and StaffLoginPayloadPinTest.
 */

export const BURLINGTON = { id: 1, name: 'Burlington Masjid', org_type: 'masjid' };
export const ALRAZI = { id: 14, name: 'Al-Razi School', org_type: 'school' };
export const SECOND_SCHOOL = { id: 20, name: 'Second School', org_type: 'school' };

/** What the server lists for her, the same in both voices and at sign-in. */
export const HER_AREAS = [
    { area: 'lunch', masjid_id: 1, masjid: BURLINGTON },
    { area: 'teacher', masjid_id: 14, masjid: ALRAZI },
];

export const membership = (masjid: typeof ALRAZI, role: string, isDefault: boolean) =>
    ({ masjid_id: masjid.id, role, is_default: isDefault, masjid });

/** Sister Shamina as /api/lunch/user describes her: lunch staff of Burlington, and nothing of the school but its entry in `areas`. */
export const inLunchVoice = () => ({
    id: 7, name: 'Shamina', type: 'LunchStaff',
    memberships: [membership(BURLINGTON, 'lunch-staff', true)],
    areas: HER_AREAS, masjid: BURLINGTON,
});

/** The same login as /api/teacher/user describes her: a teacher of Al-Razi. */
export const inTeacherVoice = () => ({
    id: 7, name: 'Shamina', type: 'Teacher',
    memberships: [membership(ALRAZI, 'teacher', true)],
    areas: HER_AREAS, masjid: ALRAZI,
});

/** A lunch login with one role: no `areas` key at all, as the server pins it. */
export const lunchOnly = () => ({ id: 8, name: 'Lunch only', type: 'LunchStaff', memberships: [membership(BURLINGTON, 'lunch-staff', true)], masjid: BURLINGTON });
/** A teacher with one school. */
export const teacherOnly = () => ({ id: 9, name: 'Teacher only', type: 'Teacher', memberships: [membership(ALRAZI, 'teacher', true)], masjid: ALRAZI });
/** A teacher with two schools and nothing else: today's switcher, and no `areas`. */
export const twoSchoolTeacher = () => ({ id: 10, name: 'Two schools', type: 'Teacher', memberships: [membership(ALRAZI, 'teacher', true), membership(SECOND_SCHOOL, 'teacher', false)], masjid: ALRAZI });
export const administrator = () => ({ id: 11, name: 'Office', type: 'MasjidAdmin', memberships: [membership(BURLINGTON, 'masjid-admin', true)], masjid: BURLINGTON });
