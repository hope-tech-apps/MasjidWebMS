import { computed, ref } from "vue";
import type { ComputedRef, Ref } from "vue";
import FamilyApiService from "@/core/services/FamilyApiService";
import type { FamilyMessage } from "@/views/family/familyI18n";

/**
 * "Translate to Arabic", over the words the TEACHERS wrote.
 *
 * familyI18n.ts translates the CHROME — the tabs, the buttons, the sentence
 * that explains why a class story is hidden — and deliberately never touches a
 * post, a message or a report-card comment, because a portal that quietly
 * rewrites what a school said is a portal that puts words in a teacher's mouth.
 * This composable is the other half of that argument: it does translate those,
 * but only when a parent asks for it, only into a copy that sits beside the
 * original, and never as the thing the screen shows by default.
 *
 * ---------------------------------------------------------------------------
 * NOTHING FIRES ON ITS OWN
 * ---------------------------------------------------------------------------
 *
 * There is no watcher here that translates a screen because the chrome happens
 * to be in Arabic. Reading the portal in Arabic and asking a paid model to
 * rewrite a teacher's paragraph are two different acts: the first is free and
 * reversible, the second costs the school money on every cache miss and takes a
 * second or two of a parent's time. A parent who reads both languages — most of
 * the ones at this school — would be billing the masjid for a translation they
 * did not want on every page they opened. So the entry point is a tap, always,
 * and `translate()` is never called from module scope, from onMounted, or from
 * a language watcher.
 *
 * The one thing that DOES extend without a second tap is content that arrives
 * after the tap: a parent who translates the class story and then opens a
 * conversation must not be handed an English thread under a button that says
 * "Show original". Honouring a request the parent already made is not the same
 * as making it for them. The view arranges that by watching what it renders and
 * calling `translate()` again with the newcomers; everything already in the map
 * is skipped, so the extension costs one request for what is genuinely new.
 *
 * ---------------------------------------------------------------------------
 * A HALF-TRANSLATED SCREEN IS THE FAILURE THIS GUARDS AGAINST
 * ---------------------------------------------------------------------------
 *
 * The reader is, by definition, somebody who cannot read the original. They
 * cannot tell which paragraphs came back in Arabic and which were left in
 * English — both are just text on a card — so a partial result presented as a
 * complete one is worse than no translation at all: it invites a parent to
 * believe they have read the whole of what the school sent them. Every path
 * below therefore keeps the ORIGINAL for anything that did not come back
 * (`tx()` falls through to it) and records the key in `unresolved`, which the
 * view renders as a plain statement that some of the page is still in the
 * language it was written in. That statement is about the page in FRONT of the
 * parent, so every call prunes `unresolved` down to the keys the caller is
 * currently rendering: a report card that failed does not leave its warning
 * standing over the class story the parent went back to.
 *
 * ---------------------------------------------------------------------------
 * A FAILURE IS REMEMBERED, BECAUSE EVERY ATTEMPT IS PAID FOR
 * ---------------------------------------------------------------------------
 *
 * Failing is not free. A cache miss costs the school a provider call whether or
 * not the answer is usable, so a key that failed and is simply re-sent is a
 * second bill for the same paragraph — and this composable is re-called
 * constantly: the view watches what it renders, and the button itself is a
 * two-tap loop ("Show original", then "Translate"). Two things stop that.
 * `attempts` caps how often one key may be bought (see MAX_ATTEMPTS), and a
 * hard failure — a 503, a 429, anything unrecognised — parks every request for
 * a cooldown, so a provider that is down or an allowance that is spent is asked
 * once and not once per tap. Neither of them ever hides the outcome: a key that
 * is given up on stays in `unresolved` and the notice keeps saying so.
 *
 * Nothing here is persisted. A reload re-asks, and the server's cache
 * (content_translations, keyed on a hash of the source) means the second ask
 * costs nothing but the round trip.
 */

/** One string to translate, and the caller's stable handle for it. */
export type TranslatableItem = { key: string; text: string };

/**
 * The request ceilings, mirrored from config/translation.php.
 *
 * The SERVER is authoritative — TranslateContentRequest refuses anything over
 * these and an operator can tighten them during an incident without a deploy,
 * at which point these numbers are simply wrong and the honest-failure path
 * below is what covers the difference. They are duplicated here for one reason:
 * a request that breaks a cap is refused WHOLE, so a single 7000-character
 * teacher comment posted in a batch of twenty would leave the other nineteen
 * paragraphs in English. Chunking to the caps client-side keeps one oversized
 * string from taking the rest of the screen down with it.
 */
const MAX_ITEMS = 20;
const MAX_CHARS_PER_ITEM = 6000;
const MAX_CHARS_PER_REQUEST = 20000;

/**
 * How many paid attempts one string gets before this stops asking for it.
 *
 * Two, because the first failure is usually the transient one — a provider
 * blip, a reply the server could not parse — and the second is the string
 * itself. A third attempt buys nothing a parent can read and is charged to the
 * school exactly like the first two.
 */
const MAX_ATTEMPTS = 2;

/**
 * How long a hard failure stands for, in milliseconds.
 *
 * Long enough that a stuck screen is not re-asking every time a parent opens a
 * tab, short enough that a genuine blip clears itself inside one reading. Used
 * for a 503 and for anything unrecognised; a 429 prefers the server's own
 * `Retry-After`.
 */
const HARD_FAILURE_COOLDOWN_MS = 60_000;

/**
 * How long a throttled request asks us to wait, in milliseconds.
 *
 * Laravel's throttle middleware answers a 429 with `Retry-After` in seconds,
 * and that number is the limiter's, not a guess — so it is preferred whenever
 * it arrives. A missing or nonsensical header is not permission to retry now,
 * so the fallback is the same cooldown a 503 gets; the clamp is there because a
 * header this file did not write should not be able to disable translation for
 * the rest of the visit.
 */
function retryAfterMs(e: any): number {
    const seconds = Number(e?.response?.headers?.["retry-after"]);

    return Number.isFinite(seconds) && seconds > 0
        ? Math.min(seconds, 300) * 1000
        : HARD_FAILURE_COOLDOWN_MS;
}

/**
 * The only language on offer, matching `config('translation.languages')`. It is
 * an option rather than a constant so a second language is a call-site change
 * and not a rewrite, but the button says "Arabic" because that is what this
 * school's families asked for.
 */
const DEFAULT_TARGET = "ar";

export type ContentTranslationOptions = {
    target?: string;

    /**
     * The view's own `fail(e)` — the helper that sends an expired session back
     * to the sign-in screen and answers true when it did.
     *
     * Routing is not this composable's job, but a 401 must not be reported to a
     * parent as "translation is unavailable": their session ended, the whole
     * page is about to be replaced, and an error banner on the way out is noise
     * at best and a misdiagnosis at worst. When the hook answers true this
     * stops silently and leaves the navigation to the caller.
     */
    onAuthFailure?: (e: any) => boolean;
};

/**
 * `masjidId` is taken as a ref rather than a string because every view in this
 * realm derives it from the route, and a route param can change under a mounted
 * component — the portal's own class links are same-component navigations.
 */
export function useContentTranslation(
    masjidId: Ref<string> | ComputedRef<string>,
    options: ContentTranslationOptions = {},
) {
    const target = options.target ?? DEFAULT_TARGET;

    /**
     * key → the translated string. A Map rather than a plain object because
     * keys carry colons and are built from server ids; Vue 3's reactive
     * collection handling makes `.set()` and `.get()` track like any other
     * state.
     */
    const translations = ref(new Map<string, string>());

    /**
     * Keys this composable asked for and did not get back — an oversized
     * paragraph it declined to send, a chunk the provider refused, a chunk that
     * was never sent because an earlier one was throttled. This is the set that
     * makes the difference between "translated" and "mostly translated", and it
     * empties itself: a key that succeeds on a retry is removed.
     */
    const unresolved = ref(new Set<string>());

    const loading = ref(false);
    const error = ref<FamilyMessage | null>(null);

    /**
     * Keys a request is carrying RIGHT NOW.
     *
     * Not reactive and never rendered — this exists to stop the same paragraph
     * being paid for twice. The view calls `translate()` again whenever new
     * content lands, and content can perfectly well land while an earlier run
     * is still in flight (a parent taps translate, then opens a conversation
     * before it returns). Keys already translated are skipped by the map; keys
     * still on the wire are not in the map yet, so without this the second run
     * would re-send them, and both would miss the server's cache because
     * neither has finished writing it.
     */
    const inFlight = new Set<string>();

    /**
     * How many paid attempts each key has already cost, for the life of the page.
     *
     * Not reactive and never rendered — like `inFlight`, this exists so that no
     * paragraph is paid for twice, and it covers the half `inFlight` cannot: a
     * key that FAILED is neither in `translations` nor on the wire, so the skip
     * test in `translate()` would happily send it again. And it would be asked
     * to: this view re-calls `translate()` every time its item list changes, so
     * a failed set is re-sent when the parent opens a conversation, a handout
     * list or another report card, and "Show original" → "Translate" is two taps
     * that ask for the whole failed set again. A string the provider has already
     * refused twice is not going to come back the third time; it stays in
     * `unresolved`, the notice keeps saying so, and nobody pays for it again.
     *
     * A 429 is deliberately NOT counted here. Nothing was sent to a provider —
     * the request never left this realm's rate limiter — so charging the keys
     * for it would burn a large screen's whole allowance on a throttle that
     * clears in a minute.
     */
    const attempts = new Map<string, number>();

    /**
     * The moment a hard failure stops standing, as an epoch millisecond.
     *
     * A 503 and a 429 are facts about the next minute as much as about this
     * request: the provider is down, or the allowance is spent, and every re-ask
     * in the meantime is another round trip for an answer that is already known.
     * The obvious gate — "block until the parent explicitly retries" — is not a
     * gate on this screen, because an explicit retry is always two taps away and
     * the view's own watcher re-calls this for every tab the parent opens. So
     * the gate is a clock: nothing goes out until it passes, whoever is asking,
     * and the banner already on screen ("please try again in a minute") is a
     * true description of what the clock is doing.
     */
    let blockedUntil = 0;

    /** The parent's "show me what the teacher actually wrote" switch. */
    const showOriginal = ref(false);

    /**
     * Does this deployment translate at all?
     *
     * Starts FALSE and is set from `meta.translation_available`, which every
     * family response carries. False-until-told is the safe direction: a
     * deployment with no ANTHROPIC_API_KEY, or one whose operator switched
     * translation off, must show no button rather than a button that can only
     * answer 503 — and a screen that forgets to call `setAvailable()` shows no
     * button either, which is a missing feature rather than a broken one.
     */
    const available = ref(false);

    function setAvailable(flag: unknown): void {
        available.value = flag === true;
    }

    const hasTranslations = computed(() => translations.value.size > 0);

    /** True when the screen is currently displaying translated text. */
    const showing = computed(() => hasTranslations.value && !showOriginal.value);

    /** True when something on screen is still in the language it was written in. */
    const incomplete = computed(() => unresolved.value.size > 0);

    /**
     * What to render for one string.
     *
     * The fallback is the whole safety property: an untranslated key returns
     * the original rather than an empty string, so every failure mode above —
     * a refused chunk, a throttle, a key the model dropped — degrades to the
     * English a parent already had, and never to a blank card.
     */
    function tx(key: string, original: string | null | undefined): string {
        const text = original ?? "";

        if (showOriginal.value) {
            return text;
        }

        return translations.value.get(key) ?? text;
    }

    /**
     * Split the work into requests the endpoint will accept.
     *
     * Greedy rather than balanced: filling each request to the caps sends the
     * fewest of them, which matters because `throttle:family-translate` allows
     * 20 a minute per contact and a report card plus a term of behaviour notes
     * can be a hundred strings. The two ceilings are checked together because
     * either one alone lets the other through — twenty items is fine until they
     * are six thousand characters each.
     */
    function chunk(items: TranslatableItem[]): TranslatableItem[][] {
        const batches: TranslatableItem[][] = [];

        let current: TranslatableItem[] = [];
        let chars = 0;

        for (const item of items) {
            const length = item.text.length;

            if (current.length >= MAX_ITEMS || (current.length > 0 && chars + length > MAX_CHARS_PER_REQUEST)) {
                batches.push(current);
                current = [];
                chars = 0;
            }

            current.push(item);
            chars += length;
        }

        if (current.length > 0) {
            batches.push(current);
        }

        return batches;
    }

    /**
     * Translate everything in `items` that is not already translated.
     *
     * Four kinds of item never reach the wire:
     *
     *   - anything already in the map, which is what makes calling this again
     *     after a tab opens cost only the new strings;
     *   - blanks, because an empty `note` or a post with no title is not text a
     *     parent is waiting on and would only spend an item slot;
     *   - anything longer than the per-item cap, which is recorded as unresolved
     *     instead. Sending it would 422 the request it travelled in and lose the
     *     other nineteen paragraphs with it;
     *   - anything already bought MAX_ATTEMPTS times without an answer, which
     *     stays unresolved rather than being paid for a third time.
     *
     * And no item at all reaches the wire while a hard failure is still inside
     * its cooldown.
     *
     * Identical texts are collapsed to one item. A behaviour list is mostly the
     * same dozen skill labels over and over — "Excellent participation" thirty
     * times — and each repeat would otherwise burn one of the twenty slots in a
     * request to fetch a string the response already contains. The translation
     * is written back to every key that shared the text.
     *
     * `items` is also taken as the statement of what is ON SCREEN, which is what
     * lets the "some of this is still in the original" notice go away again:
     * anything unresolved that the caller is no longer rendering is dropped
     * before anything else happens, so a report card that failed to translate
     * cannot leave its warning hanging over the class story the parent went back
     * to.
     */
    async function translate(items: TranslatableItem[]): Promise<void> {
        // Prune first, and unconditionally — before the cooldown check, before
        // the skip tests, before any early return. `unresolved` is rendered as a
        // sentence about the page in front of the parent, and a key that has
        // left the page cannot make that sentence true.
        const onScreen = new Set(items.map((item) => item.key));

        for (const key of [...unresolved.value]) {
            if (!onScreen.has(key)) {
                unresolved.value.delete(key);
            }
        }

        if (Date.now() < blockedUntil) {
            // Still inside a refusal we have already been given and already
            // reported. `error` is left exactly as it was — it is the sentence
            // that explains this silence.
            return;
        }

        /** text → the keys wanting it, in the order the caller listed them. */
        const wanted = new Map<string, string[]>();

        for (const item of items) {
            const text = (item.text ?? "").trim();

            if (text === "" || translations.value.has(item.key) || inFlight.has(item.key)) {
                continue;
            }

            if ((attempts.get(item.key) ?? 0) >= MAX_ATTEMPTS) {
                // Asked for twice and refused twice. Re-added rather than
                // assumed present, because the prune above drops keys while
                // they are off screen and this one has just come back.
                unresolved.value.add(item.key);
                continue;
            }

            if (text.length > MAX_CHARS_PER_ITEM) {
                unresolved.value.add(item.key);
                continue;
            }

            const keys = wanted.get(text);

            if (keys) {
                keys.push(item.key);
            } else {
                wanted.set(text, [item.key]);
            }
        }

        if (wanted.size === 0) {
            // Nothing new. Returning before touching `loading` is what lets the
            // view re-call this on every render of its item list without the
            // button flickering into a spinner.
            return;
        }

        // Pessimistic on purpose: every key is unresolved until its translation
        // actually arrives, so a request that never happens (an aborted run, a
        // network error, a browser that closed the tab) leaves the same honest
        // record as one that failed out loud.
        for (const keys of wanted.values()) {
            for (const key of keys) {
                unresolved.value.add(key);
                inFlight.add(key);
            }
        }

        const batches = chunk(
            [...wanted.entries()].map(([text, keys]) => ({ key: keys[0], text })),
        );

        /** Every key that shares a sent item's text, the sent one included. */
        const sharing = (item: TranslatableItem): string[] => wanted.get(item.text) ?? [item.key];

        /**
         * Charge one attempt to everything in a batch that did not come back.
         *
         * Called after a batch lands as well as after one fails, because a reply
         * that is missing a key was paid for exactly like a reply that never
         * arrived, and a key the model keeps dropping must not be re-sent for
         * ever. Keys that DID come back are in the map by now and are skipped.
         */
        const charge = (batch: TranslatableItem[]): void => {
            for (const item of batch) {
                for (const key of sharing(item)) {
                    if (!translations.value.has(key)) {
                        attempts.set(key, (attempts.get(key) ?? 0) + 1);
                    }
                }
            }
        };

        loading.value = true;
        error.value = null;

        try {
            for (const batch of batches) {
                let served: Record<string, unknown> = {};

                try {
                    const res = await FamilyApiService.post(
                        `/api/family/masjids/${masjidId.value}/translations`,
                        { target, items: batch },
                    );

                    served = res.data?.data?.translations ?? {};
                } catch (e: any) {
                    if (options.onAuthFailure?.(e)) {
                        return;
                    }

                    const status = e?.response?.status;

                    // A throttle or an unavailable provider is a fact about the
                    // NEXT request as much as this one: the remaining batches
                    // would draw down the same refused allowance, or reach the
                    // same provider that is down, and each attempt is another
                    // second of a spinner in front of a parent whose answer is
                    // already known. Stop, and let what did arrive stand beside
                    // the originals of what did not.
                    if (status === 429) {
                        // Not charged as an attempt: the limiter refused this
                        // before a provider ever saw it, so the strings are
                        // untried and will translate perfectly well once the
                        // window has passed. The window is what is recorded.
                        error.value = { key: "tr_busy" };
                        blockedUntil = Date.now() + retryAfterMs(e);
                        return;
                    }

                    if (status === 503) {
                        charge(batch);
                        error.value = { key: "tr_unavailable" };
                        blockedUntil = Date.now() + HARD_FAILURE_COOLDOWN_MS;
                        return;
                    }

                    // A 422 is OUR mistake, not the parent's — a cap an operator
                    // tightened under us, or a batch this file shaped wrongly.
                    // Telling a parent their text was rejected would be blaming
                    // them for a rule they cannot see and did not break, so this
                    // batch simply stays in English and the "some of this is
                    // still in the original" notice carries it. Later batches
                    // may well be fine, so the loop continues.
                    if (status === 422) {
                        charge(batch);
                        continue;
                    }

                    charge(batch);
                    error.value = { key: "tr_failed" };
                    blockedUntil = Date.now() + HARD_FAILURE_COOLDOWN_MS;
                    return;
                }

                for (const [key, value] of Object.entries(served)) {
                    if (typeof value !== "string" || value === "") {
                        continue;
                    }

                    // Written back to every key that shared this text, including
                    // the representative that was actually sent.
                    const sent = batch.find((item) => item.key === key);
                    const keys = sent === undefined ? [key] : sharing(sent);

                    for (const shared of keys) {
                        translations.value.set(shared, value);
                        unresolved.value.delete(shared);
                        attempts.delete(shared);
                    }
                }

                // Whatever this batch was paid for and did not answer with. A
                // reply that quietly drops one of twenty keys costs the same as
                // one that answers all twenty, and the dropped key must not be
                // re-sent for ever on the strength of having no record.
                charge(batch);
            }
        } finally {
            // Released whether the run finished, aborted on a throttle or threw:
            // a key left marked in-flight would be a paragraph this composable
            // refuses to try again for the life of the page.
            for (const keys of wanted.values()) {
                for (const key of keys) {
                    inFlight.delete(key);
                }
            }

            // Only the run that is actually last standing turns the spinner off.
            if (inFlight.size === 0) {
                loading.value = false;
            }
        }
    }

    /** Back to the originals, with nothing remembered — the ledger included. */
    function clear(): void {
        translations.value.clear();
        unresolved.value.clear();
        attempts.clear();
        blockedUntil = 0;
        error.value = null;
        showOriginal.value = false;
    }

    return {
        translations,
        loading,
        error,
        incomplete,
        showOriginal,
        hasTranslations,
        showing,
        available,
        setAvailable,
        translate,
        tx,
        clear,
    };
}
