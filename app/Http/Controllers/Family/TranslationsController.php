<?php

namespace App\Http\Controllers\Family;

use App\Http\Requests\Family\TranslateContentRequest;
use App\Services\Translation\TranslationUnavailableException;
use App\Services\Translation\Translator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Translate to Arabic", over whatever a parent is looking at.
 *
 * Parents at Al-Razi do not all read English and their teachers write in it, so
 * a class story, a message from a teacher and a report-card comment are all
 * things a family may be holding in a language they cannot read. This endpoint
 * is the button over them.
 *
 * ---------------------------------------------------------------------------
 * IT TAKES TEXT, NOT RECORD IDS — AND THAT IS THE SECURITY DESIGN
 * ---------------------------------------------------------------------------
 *
 * The obvious API would be `POST /posts/{post_id}/translate`, and it would be
 * the wrong one. It would need its own audience gate: resolve the post, check
 * the group, check consent, check that this guardian's edge names a child in
 * that class — the same chain GroupPostsController, GroupThreadsController and
 * the ḥifẓ and behaviour controllers each implement, and a chain that is only
 * ever one forgotten `authorizeDisclosure()` away from handing one family
 * another family's child. `.claude/rules/groups.md` is largely a list of the
 * ways that has nearly happened.
 *
 * So this endpoint has no per-record gate, because it has no records. The parent
 * sends the TEXT THAT IS ALREADY ON THEIR SCREEN, which some other endpoint —
 * one that did run the full chain — already decided they were entitled to read.
 * There is nothing here to widen: a caller can only ask for a translation of
 * something they already possess, and the reply goes back to them alone. Passing
 * an id they should not have would be a disclosure; passing text they should not
 * have discloses it to nobody but themselves.
 *
 * ---------------------------------------------------------------------------
 * WHAT THAT TRADE COSTS, AND WHAT PAYS FOR IT
 * ---------------------------------------------------------------------------
 *
 * An endpoint with no per-record gate is an endpoint any authenticated parent
 * can point at any text at all, and every call that misses the cache SPENDS
 * MONEY at a paid provider. Disclosure stops being the risk and cost becomes
 * one. Both halves of the answer are deliberate and neither is optional:
 *
 *   - CAPPED, by TranslateContentRequest — items per request, characters per
 *     item, and characters per request as a sum, all from
 *     config/translation.php so an operator can tighten them without a deploy.
 *   - THROTTLED, by `throttle:family-translate` on the route — 20 requests a
 *     minute keyed on the CONTACT, on top of the realm's own 60/min. See
 *     AppServiceProvider.
 *
 * And the cache underneath (App\Services\Translation\AnthropicTranslator) means
 * the thirty families reading the same class story pay for it once, per school.
 *
 * ---------------------------------------------------------------------------
 * A FAILURE IS A 503, NEVER A HALF-TRANSLATED PAGE
 * ---------------------------------------------------------------------------
 *
 * Every way this can fail — switched off, no API key, the provider refusing,
 * the provider answering with something that is not a translation, nothing at
 * all bought within the request's call ceiling — arrives as one
 * TranslationUnavailableException and leaves as a 503 carrying a sentence a
 * parent can read. Not a 500, which would put "An error occurred while
 * processing your request." in front of somebody who cannot read the page they
 * are on.
 *
 * ---------------------------------------------------------------------------
 * AND A PARTIAL RESULT IS NEVER DRESSED AS A COMPLETE ONE
 * ---------------------------------------------------------------------------
 *
 * A 200 may carry FEWER translations than the request had items. The translator
 * spends against a hard ceiling on provider calls (`translation.max_provider_
 * calls`), and a request whose batch reply was unusable can run out of calls
 * with strings still untranslated; those strings come back untranslated rather
 * than costing the parent the whole screen.
 *
 * What makes that honest rather than the silent-partial-success this feature
 * spends its design avoiding is that an untranslated key is ABSENT from
 * `data.translations` — never present carrying its own English, which is the one
 * shape a reader who does not read English could not detect. `meta.requested`
 * and `meta.complete` say the same thing again in a form a client can branch on
 * without diffing key sets, so "some of this is still in the language it was
 * written in" is a notice the client can render from the response alone.
 */
class TranslationsController extends FamilyController
{
    public function store(TranslateContentRequest $request, $masjid_id, Translator $translator): JsonResponse
    {
        // An assertion, not a value we need — nothing below reads the contact.
        // It is here for the reason FamilyController::contact() exists: a
        // controller in this realm fails closed on its own evidence rather than
        // trusting its middleware stack to be mounted the way the route file
        // says it is. If `auth:family` were ever missing from this route the
        // damage would not be a null dereference; it would be an anonymous
        // caller spending money at the provider, and then a 500 from the cache
        // write, because with no principal there is no bound tenant and
        // `content_translations.masjid_id` is NOT NULL. A 401 is the right
        // answer to that, and this line is what produces it.
        $this->contact();

        $target = (string) $request->validated('target');

        /** @var array<int,array{key:string,text:string}> $items */
        $items = $request->validated('items');

        // The caller's keys, in the caller's order. Duplicate keys are already a
        // 422, so nothing collapses here.
        $texts = [];

        foreach ($items as $item) {
            $texts[(string) $item['key']] = (string) $item['text'];
        }

        try {
            $translations = $translator->translate($texts, $target);
        } catch (TranslationUnavailableException $e) {
            // The operator-facing message and the stack go to the log; the parent
            // gets the sentence the exception was built with. Errors::publicMessage
            // deliberately is not used — it would either leak the technical
            // message or replace it with the generic one.
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => $e->publicMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'target' => $target,
                // Cast to an object so the shape is a MAP whatever the keys are.
                // PHP turns the array key "0" into the integer 0, so a client
                // that keys its paragraphs by index would get back a JSON ARRAY
                // — `["…","…"]` where the contract says `{"0":"…","1":"…"}` —
                // and every other client would keep getting an object. A payload
                // whose type depends on what the caller happened to name their
                // keys is the kind of thing that works in every test and breaks
                // on one school's phone.
                'translations' => (object) $translations,
            ],
            // Deliberately thin. The model's name, the cache hit count and what
            // the call cost are operator facts, not parent facts, and putting
            // them here would publish this school's usage to every phone in it.
            //
            // These three are the client's own arithmetic and nothing else:
            // `items` is what came back, `requested` is what went out, and
            // `complete` is the comparison spelled out so a client never has to
            // infer "we got everything" from a length it computed itself. They
            // are how the portal knows to leave the "some of this is still in the
            // original" notice up.
            'meta' => [
                'items' => count($translations),
                'requested' => count($texts),
                'complete' => count($translations) === count($texts),
            ],
        ], Response::HTTP_OK);
    }
}
