<?php

return [

    /*
    |--------------------------------------------------------------------------
    | On-demand translation of staff-written text (Al-Razi, 2026-09-12)
    |--------------------------------------------------------------------------
    |
    | Parents at Al-Razi do not all read English, and their teachers write in
    | it. This is the "Translate to Arabic" button over a class story, a
    | teacher's message, a report-card comment: the parent already has the
    | English on their screen, and asks for the same words in a language they
    | read.
    |
    | Every number here is a SPENDING CEILING as much as a validation rule. The
    | endpoint calls a paid model on behalf of an authenticated parent, so
    | "how much can one request cost" has to be answerable from this file
    | rather than inferred from a controller — the same reason config/family.php
    | holds the sign-in windows instead of the service that enforces them.
    |
    */

    /*
    | The master switch. FALSE makes every call to the endpoint a clean 503
    | with a sentence a parent can read, rather than a route that 404s or a
    | button that spins forever. An operator who needs to stop the spend during
    | an incident sets one env var; nothing else in the portal changes.
    */
    'enabled' => (bool) env('TRANSLATION_ENABLED', true),

    /*
    | Which model does the translating. Defaults to whatever the Masjid
    | Assistant is on (`services.anthropic.model`, Sonnet 5) because the two
    | workloads are the same shape — short text, no tools, no long-horizon
    | reasoning — but it is separately settable so translation quality can be
    | tuned, or moved to a cheaper tier, without touching the assistant.
    |
    | NOTE ON THE CACHE: the cached row is keyed on (masjid, source, target) and
    | NOT on the model, so changing this does not invalidate anything already
    | translated. That is deliberate — a model change must not silently re-spend
    | a term's worth of cached text — but it means a deployment that wants the
    | new model's wording everywhere has to clear the table on purpose. Each row
    | records the model that produced it, so "what is stale?" is a query.
    */
    'model' => env('TRANSLATION_MODEL', config('services.anthropic.model')),

    /*
    | The languages a parent may ask for. One today; the request refuses
    | anything not in this list with a 422 rather than passing an arbitrary
    | string through to the model, because "translate this into <whatever the
    | client sent>" is a prompt the caller would be writing.
    */
    'languages' => ['ar'],

    /*
    | How many strings one request may carry. A screen's worth — a feed post
    | and its comments, a report card's remarks — not a whole term. Twenty keeps
    | the single upstream call comfortably inside one response, which is what
    | makes the batch path (rather than the per-item fallback) the normal case.
    */
    'max_items' => (int) env('TRANSLATION_MAX_ITEMS', 20),

    /*
    | Ceiling on ONE string. 6000 characters is longer than any post, message or
    | teacher comment this platform accepts (config/groups.php caps those at
    | 5000), so a value above this is not a parent translating what they can
    | see — it is somebody using the endpoint as a general-purpose translator.
    */
    'max_chars_per_item' => (int) env('TRANSLATION_MAX_CHARS_PER_ITEM', 6000),

    /*
    | Ceiling on the whole request, checked as a SUM. Without it, `max_items` and
    | `max_chars_per_item` multiply: twenty items of six thousand characters is
    | 120,000 characters in one call, which is a real bill from one tap. The two
    | per-item limits bound the shape of a request; this one bounds its cost.
    |
    | Raising it does NOT quietly start truncating replies: the translator sizes
    | each call's output cap from the characters it is actually sending (see
    | AnthropicTranslator's constants) and splits a batch whose reply came back
    | cut off, rather than pinning one cap that a raised limit here would silently
    | outgrow. Past roughly 28,000 characters a batch will start arriving as two
    | calls instead of one, which is a cost worth knowing about before tuning this
    | up: `max_provider_calls` below is what stops that becoming unbounded.
    */
    'max_chars_per_request' => (int) env('TRANSLATION_MAX_CHARS_PER_REQUEST', 20000),

    /*
    | THE HARD CEILING ON PAID MODEL CALLS IN ONE REQUEST, and the only number
    | here that bounds SPEND directly rather than by bounding the request.
    |
    | The other ceilings describe the shape of what a parent may send;
    | `throttle:family-translate` counts HTTP REQUESTS. Neither counts calls, and
    | one request is not one call: the normal path is a single batch, but a batch
    | reply the parser cannot trust is retried one string at a time, so twenty
    | misses used to be able to cost 1 + 20 = 21 calls — making a limiter written
    | as "20 a minute" a ceiling of 420 provider calls a minute per contact. That
    | is not a rare shape either: point TRANSLATION_MODEL at a model that
    | habitually wraps its JSON and EVERY request takes the expensive path,
    | permanently, at twenty-one times list price.
    |
    | Six is one batch plus a handful of retries: enough that a one-off malformed
    | reply still gets most of a screen translated, and far short of a fan-out.
    | When the ceiling is reached the strings that were not bought come back
    | UNTRANSLATED — the parent's screen keeps the English for those and says so —
    | rather than the whole request failing, because half a screen in Arabic with
    | an honest notice beats none of it.
    |
    | Raising it raises the worst-case bill per tap in direct proportion. A value
    | of 0 is read as 1: `enabled` above is the off switch, and an off switch that
    | looks like a broken feature is not one.
    */
    'max_provider_calls' => (int) env('TRANSLATION_MAX_PROVIDER_CALLS', 6),

    /*
    | How long a cached translation is kept after it was last SERVED, not after
    | it was created. Six months carries a class through a school year of
    | re-reading the same posts without re-paying for them, and the sweep
    | (`translations:purge`, scheduled in routes/console.php) removes what has
    | gone quiet. The rows hold staff-written text about children; see
    | App\Console\Commands\PurgeContentTranslations for why a cache of that
    | needs a sweeper at all.
    */
    'cache_days' => (int) env('TRANSLATION_CACHE_DAYS', 180),

];
