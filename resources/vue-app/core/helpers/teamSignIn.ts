/**
 * What Team & Access says in the "Last sign-in" column.
 *
 * The server sends `last_sign_in_at: null` for two different facts: the person has
 * never signed in, or the sign-in is withheld because they are a teacher at another
 * school too (a token is minted at any school, so the newest one is not a fact about
 * THIS school). It says which with `shared`. Reading both as "Not signed in yet"
 * told an office a daily user had never arrived, and invited a pointless resend.
 *
 * No imports: this runs under `npm run test:spa`.
 */
export type SignInColumn =
    | { kind: 'date'; at: string }
    | { kind: 'shared' }
    | { kind: 'never' };

export function signInColumn(person: { last_sign_in_at?: string | null; shared?: boolean }): SignInColumn {
    if (person.last_sign_in_at) return { kind: 'date', at: person.last_sign_in_at };
    if (person.shared === true) return { kind: 'shared' };

    return { kind: 'never' };
}
