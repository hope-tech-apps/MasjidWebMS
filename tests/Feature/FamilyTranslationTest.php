<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContentTranslation;
use App\Models\Masjid;
use App\Services\Translation\AnthropicTranslator;
use App\Services\Translation\Translator;
use App\Support\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * "Translate to Arabic" in the parent portal — the guarantees, not the wording.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE REACHES THE NETWORK, AND THE SEAM IS DELIBERATELY NARROW
 * ---------------------------------------------------------------------------
 *
 * The obvious fake would implement `Translator` and be done with it. It would
 * also test nothing: the cache, the hashing and the tenant scope all live inside
 * `AnthropicTranslator`, so a fake that replaced the whole service would take
 * every behaviour under test with it and leave a suite that asserted a stub
 * returns what a stub returns.
 *
 * So `CountingTranslator` at the bottom of this file SUBCLASSES the real service
 * and overrides exactly one method — `call()`, the single line that touches the
 * network. Everything else runs for real: the same hashes, the same
 * `content_translations` rows, the same BelongsToMasjid scope, the same batch
 * JSON contract, the same per-item fallback. What the fake adds is a COUNTER,
 * because most of what this feature promises is a statement about how many times
 * the provider was called — a second identical request must call it zero times,
 * a second organisation must call it again, and a request that ran out of budget
 * must stop calling it at all.
 *
 * The fake also answers with a RAW PROVIDER REPLY rather than a finished result,
 * because the second half of what this feature promises is a statement about
 * what the service does with a reply it cannot trust: a fenced array, a sentence
 * in front of the JSON, an empty string, a socket that dies on the eighteenth
 * retry. Those are the ordinary weather of talking to a model, and the branches
 * that handle them are the most fragile code here.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE IS FOR, BEYOND THE FEATURE
 * ---------------------------------------------------------------------------
 *
 * It is also `content_translations`' mandatory cross-tenant test
 * (.claude/rules/tenant-scoping.md, enforced by TenantScopingCoverageTest). That
 * one matters more here than the rule's usual case: a cache lookup names no
 * organisation — it has a hash and a language and asks "have we seen this?" — so
 * a lost global scope would answer across every school on the platform and read
 * perfectly reasonable to anyone looking at the query.
 */
class FamilyTranslationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Contact $parent;
    private CountingTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // A key must LOOK present or AnthropicTranslator::assertAvailable()
        // refuses before it reaches the fake — which is a real guarantee, tested
        // on its own below, and would otherwise silently be the only thing every
        // other case in this file measured.
        config(['services.anthropic.key' => 'test-key-not-used']);
        config(['translation.enabled' => true]);

        $this->masjid = $this->makeMasjid();
        $this->parent = $this->makeParent($this->masjid);

        $this->translator = new CountingTranslator();
        $this->app->instance(Translator::class, $this->translator);
    }

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ], $overrides));
    }

    /** `forceFill` because the login_* columns are deliberately not fillable. */
    private function makeParent(Masjid $masjid): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $contact->forceFill([
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    /**
     * Authenticate as a parent for the NEXT request, with a real bearer token.
     *
     * The guards and the tenant are dropped first for the reason FamilyPortalTest
     * gives: `RequestGuard::user()` memoizes and `TenantContext` is a scoped
     * binding nothing clears mid-process, so a second call inside one test would
     * otherwise be answered out of the first call's state — which on a cache test
     * would look exactly like a cache hit.
     */
    private function as(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
    }

    private function url(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/translations";
    }

    /** @param array<int,array{key:string,text:string}> $items */
    private function payload(array $items, string $target = 'ar'): array
    {
        return ['target' => $target, 'items' => $items];
    }

    // ------------------------------------------------------------- the happy path

    #[Test]
    public function a_parent_gets_one_translation_per_item_keyed_the_way_they_sent_it(): void
    {
        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'post-9-title', 'text' => 'Trip to the museum'],
                ['key' => 'post-9-body', 'text' => 'We leave at nine on Friday.'],
            ]))
            ->assertOk();

        $response->assertJsonPath('status', 'success')
            ->assertJsonPath('data.target', 'ar')
            ->assertJsonPath('data.translations.post-9-title', '[ar] Trip to the museum')
            ->assertJsonPath('data.translations.post-9-body', '[ar] We leave at nine on Friday.')
            ->assertJsonPath('meta.items', 2);

        // ONE provider call for two strings. The batch path is the normal case;
        // if this ever reads 2 the JSON contract has started failing and every
        // request is quietly taking the expensive per-item fallback.
        $this->assertSame(1, $this->translator->calls);
    }

    #[Test]
    public function a_repeated_paragraph_in_one_request_is_translated_once(): void
    {
        $repeated = 'Please send a water bottle.';

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'note-a', 'text' => $repeated],
                ['key' => 'note-b', 'text' => $repeated],
            ]))
            ->assertOk()
            ->assertJsonPath('data.translations.note-a', '[ar] ' . $repeated)
            ->assertJsonPath('data.translations.note-b', '[ar] ' . $repeated);

        // THE GUARANTEE IS ABOUT WHAT WENT OUT, NOT ABOUT WHAT CAME TO REST.
        //
        // The row count alone proves nothing here: `remember()` writes with
        // updateOrCreate keyed on (source_hash, target_lang), so the same
        // sentence sent as two batch items and PAID FOR TWICE still collapses
        // into exactly one row — and both items travel in one call, so the call
        // count is 1 either way. Send `$texts` instead of the hash-keyed
        // `$misses` to fetchTranslations() and a behaviour list of thirty
        // identical skill labels burns thirty of the twenty item slots and is
        // billed thirty times, with this test — the one named for that very
        // guarantee — still green.
        //
        // So the assertion is the batch payload: one item on the wire.
        $this->assertSame(1, $this->translator->calls);
        $this->assertCount(1, $this->translator->batches[0], 'the repeated sentence was sent twice');

        // And stored once afterwards. Two rows for one sentence would be the
        // unique index's problem eventually, and a wasted call immediately.
        $this->assertCount(1, ContentTranslation::withoutMasjidScope()->get());
    }

    // ----------------------------------------------------------------- the cache

    #[Test]
    public function a_second_identical_request_is_served_from_the_cache_and_costs_nothing(): void
    {
        $items = [['key' => 'story', 'text' => 'The class finished Surah An-Naba today.']];

        $first = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertOk();

        $this->assertSame(1, $this->translator->calls);

        // Aged deliberately, so the assertion below is about the HIT stamping it
        // and not about the write that created it. The sweep deletes by disuse,
        // so a cache hit that forgets to stamp `last_used_at` is a translation
        // that expires while families are still reading it every week — a bug
        // that would not surface for six months.
        $row = ContentTranslation::withoutMasjidScope()->firstOrFail();
        $row->forceFill(['last_used_at' => now()->subDays(90)])->save();

        $second = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertOk();

        // The whole economic case for this feature: thirty families tap the same
        // button on the same class story and the school pays once.
        $this->assertSame(1, $this->translator->calls);
        $this->assertSame($first->json('data.translations'), $second->json('data.translations'));

        $this->assertTrue($row->fresh()->last_used_at->greaterThan(now()->subMinute()));
    }

    #[Test]
    public function a_request_that_is_half_cached_comes_back_whole(): void
    {
        // The shape every other cache test misses. The happy-path cases are
        // all-miss and the case above is all-hit, so `translate()`'s final
        // rebuild loop — the one that reads a key out of `$cached` OR out of
        // `$fresh` — has never once run with both sides populated. A merge that
        // returned only the fresh keys, or that looked `$fresh` up by the
        // caller's key instead of by hash, would answer this request with half
        // the screen: exactly the half-Arabic page the controller docblock, the
        // Translator contract and useContentTranslation.ts are all built to
        // prevent, and nothing in the suite would fail.
        $story = 'The class finished Surah An-Naba today.';
        $notice = 'Please send a water bottle on Thursday.';

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'story', 'text' => $story],
            ]))
            ->assertOk();

        $this->assertSame(1, $this->translator->calls);

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'story', 'text' => $story],
                ['key' => 'notice', 'text' => $notice],
            ]))
            ->assertOk();

        // Both keys, one of them from the cache and one of them just bought.
        $response->assertJsonPath('data.translations.story', '[ar] ' . $story)
            ->assertJsonPath('data.translations.notice', '[ar] ' . $notice)
            ->assertJsonPath('meta.items', 2)
            ->assertJsonPath('meta.requested', 2)
            ->assertJsonPath('meta.complete', true);

        // Exactly one more call, carrying exactly the miss. A second call, or a
        // batch of two, would mean the cached paragraph was re-bought.
        $this->assertSame(2, $this->translator->calls);
        $this->assertCount(1, $this->translator->batches[1]);
        $this->assertSame($notice, $this->translator->batches[1][0]['source']);
    }

    #[Test]
    public function two_schools_with_identical_text_keep_separate_cache_rows(): void
    {
        $otherMasjid = $this->makeMasjid();
        $otherParent = $this->makeParent($otherMasjid);

        $items = [['key' => 'notice', 'text' => 'School is closed on Monday.']];

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertOk();

        $this->as($otherParent)
            ->postJson($this->url($otherMasjid), $this->payload($items))
            ->assertOk();

        // The second school pays again, on purpose. A shared cache would be one
        // lookup answering across tenants — a row written by one organisation
        // served to another — through a query that names no organisation at all.
        $this->assertSame(2, $this->translator->calls);

        $rows = ContentTranslation::withoutMasjidScope()->get();
        $this->assertCount(2, $rows);
        $this->assertCount(1, $rows->pluck('source_hash')->unique(), 'identical text hashes identically');
        $this->assertCount(2, $rows->pluck('masjid_id')->unique(), 'and is stored once per school');

        // And the boundary holds from inside: bound to the second school, the
        // first school's ContentTranslation row does not exist.
        app(TenantContext::class)->set($otherMasjid->id);

        $this->assertSame(0, ContentTranslation::query()->where('masjid_id', $this->masjid->id)->count());
        $this->assertCount(1, ContentTranslation::query()->get());
    }

    // ------------------------------------------------------------------ the gates

    #[Test]
    public function an_unauthenticated_caller_is_refused(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $this->postJson($this->url($this->masjid), $this->payload([
            ['key' => 'x', 'text' => 'Anything at all.'],
        ]))->assertStatus(401);

        $this->assertSame(0, $this->translator->calls);
    }

    #[Test]
    public function a_child_holding_the_phone_cannot_spend_the_schools_money(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        // A hand-off token carries only `student:{membership}`, so `family.parent`
        // refuses it here exactly as it does on every other parent surface. The
        // membership id need not exist: the ability check runs first.
        $token = $this->parent->createStudentHandoffToken(1234)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'x', 'text' => 'Anything at all.'],
            ]))
            ->assertStatus(403);

        $this->assertSame(0, $this->translator->calls);
    }

    // ------------------------------------------------------------- the ceilings

    #[Test]
    public function more_items_than_the_limit_is_refused(): void
    {
        $max = (int) config('translation.max_items');

        $items = [];

        for ($i = 0; $i <= $max; $i++) {
            $items[] = ['key' => "k{$i}", 'text' => 'Short.'];
        }

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(0, $this->translator->calls);
    }

    #[Test]
    public function one_passage_longer_than_the_limit_is_refused(): void
    {
        $tooLong = str_repeat('a', (int) config('translation.max_chars_per_item') + 1);

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'essay', 'text' => $tooLong],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, $this->translator->calls);
    }

    #[Test]
    public function a_request_whose_passages_are_each_legal_but_together_are_not_is_refused(): void
    {
        // The ceiling the other two cannot express. Every item here is inside
        // `max_chars_per_item` and the count is inside `max_items`; it is their
        // PRODUCT that would be a real bill from one tap.
        $perItem = (int) config('translation.max_chars_per_item');
        $needed = (int) ceil(((int) config('translation.max_chars_per_request') + 1) / $perItem);

        $items = [];

        for ($i = 0; $i < $needed; $i++) {
            $items[] = ['key' => "part{$i}", 'text' => str_repeat('b', $perItem)];
        }

        // Asserted against `data`, not the framework's `errors` key: this
        // application renders every 422 as {status:"failed", data:{field:[...]}}
        // (BaseFormRequest), so assertJsonValidationErrors would look somewhere
        // that is always empty and pass for the wrong reason.
        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['items']]);

        $this->assertSame(0, $this->translator->calls);
    }

    #[Test]
    public function a_language_nobody_configured_is_refused(): void
    {
        // Not pedantry: an unlisted tag would be pasted into the system prompt,
        // which is the caller writing instructions for the model.
        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'x', 'text' => 'Good morning.'],
            ], target: 'fr'))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['target']]);

        $this->assertSame(0, $this->translator->calls);
    }

    // ------------------------------------------- a reply the parser cannot trust
    //
    // Everything in this section exists because the fake used to be incapable of
    // producing one. A model that fences its JSON, wraps it in a sentence or
    // returns nothing at all is the ordinary weather of this feature, and until
    // the fake could imitate it the batch-rejection branches, the whole per-item
    // fallback and the fence stripping were dead code under a green suite.

    /**
     * The defect this pins cost real money on the day translation was switched
     * on: the live model answered {"i":0,"text":"<the Arabic>"} — mirroring the
     * key the payload used — and the decoder threw a perfectly good batch away,
     * so every single request paid for a wasted batch call and then one call
     * per string on top.
     */
    #[Test]
    public function a_batch_reply_that_mirrors_our_own_key_is_still_one_call(): void
    {
        $this->translator->batchReply = function (string $honest): string {
            $rows = json_decode($honest, true);

            foreach ($rows as $i => $row) {
                // "source" — the key the payload itself uses — is the mirror
                // most likely to come back now that the input is keyed that
                // way, and it is the one the identity check has to clear.
                $rows[$i] = ['i' => $row['i'], 'source' => $row['translation']];
            }

            return (string) json_encode($rows, JSON_UNESCAPED_UNICODE);
        };

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'post-1-title', 'text' => 'Trip to the museum'],
                ['key' => 'post-1-body', 'text' => 'We leave at nine on Friday.'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.translations.post-1-title', '[ar] Trip to the museum')
            ->assertJsonPath('data.translations.post-1-body', '[ar] We leave at nine on Friday.');

        $this->assertSame(1, $this->translator->calls);
        $this->assertSame(0, $this->translator->itemCalls);
    }

    /**
     * The other half of the same tolerance: taking a mirrored key must never
     * mean taking the SOURCE back as its own translation. That is the one wrong
     * answer this path can give — English cached and served as the Arabic, with
     * a parent told it was translated.
     */
    #[Test]
    public function a_batch_reply_that_echoes_the_source_is_not_taken_as_a_translation(): void
    {
        $this->translator->batchReply = function (string $honest, array $sent): string {
            $rows = [];

            foreach ($sent as $item) {
                $rows[] = ['i' => $item['i'], 'text' => $item['source']];
            }

            return (string) json_encode($rows, JSON_UNESCAPED_UNICODE);
        };

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'post-2-title', 'text' => 'Trip to the museum'],
            ]))
            ->assertOk();

        // The batch was refused, so the per-item path ran and its answer is what
        // the parent gets — never the English that came back keyed "text".
        $response->assertJsonPath('data.translations.post-2-title', '[ar] Trip to the museum');
        $this->assertSame(1, $this->translator->batchCalls);
        $this->assertSame(1, $this->translator->itemCalls);

        $this->assertDatabaseMissing('content_translations', ['translated_text' => 'Trip to the museum']);
    }

    #[Test]
    public function a_fenced_batch_reply_is_still_one_call_and_still_every_key(): void
    {
        // ```json … ``` is the single most common way a model disobeys "no code
        // fences", and discarding the batch over it would mean paying for one
        // call per string to punish punctuation. The fence is stripped and the
        // batch stands.
        $this->translator->batchReply = fn (string $honest): string => "```json\n{$honest}\n```";

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'a', 'text' => 'First paragraph.'],
                ['key' => 'b', 'text' => 'Second paragraph.'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.translations.a', '[ar] First paragraph.')
            ->assertJsonPath('data.translations.b', '[ar] Second paragraph.')
            ->assertJsonPath('meta.complete', true);

        $this->assertSame(1, $this->translator->calls, 'a fenced reply took the expensive path');
        $this->assertSame(0, $this->translator->itemCalls);
        $this->assertCount(2, ContentTranslation::withoutMasjidScope()->get());
    }

    #[Test]
    public function an_unusable_batch_reply_is_retried_one_string_at_a_time_and_loses_nothing(): void
    {
        // Prose in front of the array: `decodeJsonArray()` cannot parse it, so
        // the ENVELOPE is what gets dropped — one call per string, bare text in
        // and bare text out, because a single string has no alignment left to
        // get wrong. The parent must not be able to tell.
        $this->translator->batchReply = fn (): string => 'Sure! Here are your translations.';

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'a', 'text' => 'First paragraph.'],
                ['key' => 'b', 'text' => 'Second paragraph.'],
                ['key' => 'c', 'text' => 'Third paragraph.'],
            ]))
            ->assertOk();

        $response->assertJsonPath('data.translations.a', '[ar] First paragraph.')
            ->assertJsonPath('data.translations.b', '[ar] Second paragraph.')
            ->assertJsonPath('data.translations.c', '[ar] Third paragraph.')
            ->assertJsonPath('meta.items', 3)
            ->assertJsonPath('meta.complete', true);

        // One rejected batch plus one call per string — and this is the shape
        // `translation.max_provider_calls` exists to bound, which is why it is
        // asserted as a split and not only as a total.
        $this->assertSame(1, $this->translator->batchCalls);
        $this->assertSame(3, $this->translator->itemCalls);
        $this->assertSame(4, $this->translator->calls);

        // Each key still holds ITS OWN translation. The fallback indexes by
        // position; index it by hash instead and a teacher's note about one
        // child appears under another child's paragraph, in Arabic, to a reader
        // who cannot check it against the English.
        $this->assertCount(3, ContentTranslation::withoutMasjidScope()->get());
    }

    #[Test]
    public function a_per_item_retry_that_comes_back_empty_is_a_503_and_caches_nothing(): void
    {
        // The last stop: the envelope failed AND the bare-text retry answered
        // with nothing. There is no third thing to try, and an empty string is
        // not a translation — storing it would poison the cache with silence
        // that every later reader would be served instead of their text.
        $this->translator->batchReply = fn (): string => 'I am afraid I cannot help with that.';
        $this->translator->itemReply = fn (): string => "   \n ";

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'a', 'text' => 'First paragraph.'],
                ['key' => 'b', 'text' => 'Second paragraph.'],
            ]))
            ->assertStatus(503);

        $response->assertJsonPath('status', 'error');
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertCount(0, ContentTranslation::withoutMasjidScope()->get());
    }

    // ----------------------------------------------------------------- failure

    #[Test]
    public function a_provider_failure_is_a_clean_503_carrying_no_partial_translations(): void
    {
        // BARE, and that is the whole point of the case. This exception is what
        // an SDK error or a socket timeout looks like at the seam; the type the
        // controller needs is produced by production code above it. Pre-wrapping
        // it here — as this test used to — proved only that the fake could
        // construct the exception the fake had just constructed, and left the
        // named guarantee ("never a 500 in front of somebody who cannot read the
        // page") resting on a method the fake replaces.
        $this->translator->failWith = new RuntimeException('the provider exploded');

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'a', 'text' => 'First paragraph.'],
                ['key' => 'b', 'text' => 'Second paragraph.'],
            ]))
            ->assertStatus(503);

        // NOT a 500 with "An error occurred while processing your request." in
        // front of somebody who cannot read the page they are on.
        $response->assertJsonPath('status', 'error');
        $this->assertNotEmpty($response->json('message'));

        // And above all not a 200 with some strings done and some not: a reader
        // who does not read English cannot tell which is which, so a partial
        // result presented as a complete one is the worst of the three outcomes.
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertCount(0, ContentTranslation::withoutMasjidScope()->get());
    }

    #[Test]
    public function a_failure_half_way_through_the_retries_keeps_what_was_already_bought(): void
    {
        // THE MONEY CASE. The batch reply is unusable, so five strings go one
        // per call; the provider dies on the third. Two translations have been
        // bought and billed by then, and the request still fails — correctly,
        // because a parent must not be handed a page that is two-fifths Arabic
        // with no way to tell which fifths.
        //
        // What must NOT happen is those two being thrown away. The cache write
        // used to sit after the loop, so the exception unwound past it and the
        // school paid for two translations that no row and no log ever recorded;
        // the parent's retry then re-bought all five. Nothing anywhere would
        // have shown it.
        $texts = [
            'a' => 'First paragraph.',
            'b' => 'Second paragraph.',
            'c' => 'Third paragraph.',
            'd' => 'Fourth paragraph.',
            'e' => 'Fifth paragraph.',
        ];

        $items = [];

        foreach ($texts as $key => $text) {
            $items[] = ['key' => $key, 'text' => $text];
        }

        $this->translator->batchReply = fn (): string => 'Certainly, here you go.';
        $this->translator->failWith = new RuntimeException('the provider went away');
        $this->translator->failOnItemCall = 3;

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertStatus(503);

        // Exactly the two that landed, identified by hash so this cannot pass on
        // two rows that happen to be the wrong two.
        $rows = ContentTranslation::withoutMasjidScope()->get();

        $this->assertCount(2, $rows, 'a mid-loop failure discarded translations the school had paid for');
        $this->assertEqualsCanonicalizing(
            [ContentTranslation::hashFor($texts['a']), ContentTranslation::hashFor($texts['b'])],
            $rows->pluck('source_hash')->all()
        );

        // 1 rejected batch + 3 retries, the third of which threw.
        $this->assertSame(4, $this->translator->calls);

        // AND THEY ARE NEVER RE-PAID. The provider recovers, the parent taps
        // again, and the request costs ONE call: a batch of the three that were
        // never bought. Two calls' worth of the school's money stayed bought.
        $this->translator->failWith = null;
        $this->translator->batchReply = null;

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload($items))
            ->assertOk()
            ->assertJsonPath('data.translations.a', '[ar] ' . $texts['a'])
            ->assertJsonPath('data.translations.e', '[ar] ' . $texts['e'])
            ->assertJsonPath('meta.items', 5)
            ->assertJsonPath('meta.complete', true);

        $this->assertSame(5, $this->translator->calls);
        $this->assertSame(3, $this->translator->itemCalls, 'the two cached translations were bought again');
        $this->assertCount(3, $this->translator->batches[1], 'the retry re-sent strings it already had');
        $this->assertCount(5, ContentTranslation::withoutMasjidScope()->get());
    }

    // ------------------------------- what the portal is told before it asks

    /**
     * The button must not exist where it could only fail.
     *
     * Production ran for a while with the code deployed and no
     * ANTHROPIC_API_KEY, which is exactly the state this asserts: every family
     * response carries the answer, so the portal can decline to offer a control
     * whose one possible outcome is a 503 a parent can do nothing about
     * (.claude/rules/environments.md — an unconfigured integration must no-op,
     * not throw, and must say so through `isConfigured()`).
     */
    #[Test]
    public function a_deployment_with_no_api_key_tells_the_portal_not_to_offer_the_button(): void
    {
        config(['services.anthropic.key' => '']);

        $this->as($this->parent)
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups")
            ->assertOk()
            ->assertJsonPath('meta.translation_available', false);

        $this->assertSame(0, $this->translator->calls);
    }

    /** The operator's off switch reaches the button the same way the key does. */
    #[Test]
    public function translation_switched_off_tells_the_portal_not_to_offer_the_button(): void
    {
        config(['translation.enabled' => false]);

        $this->as($this->parent)
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups")
            ->assertOk()
            ->assertJsonPath('meta.translation_available', false);
    }

    /** And a configured deployment says so, or the button never appears at all. */
    #[Test]
    public function a_configured_deployment_tells_the_portal_the_button_is_worth_showing(): void
    {
        $this->as($this->parent)
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups")
            ->assertOk()
            ->assertJsonPath('meta.translation_available', true);
    }

    #[Test]
    public function translation_switched_off_refuses_before_anything_is_spent(): void
    {
        config(['translation.enabled' => false]);

        $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'x', 'text' => 'Anything at all.'],
            ]))
            ->assertStatus(503);

        $this->assertSame(0, $this->translator->calls);
    }

    #[Test]
    public function a_deployment_with_no_api_key_refuses_before_anything_is_spent(): void
    {
        // The case setUp has always CLAIMED was tested here and never was, which
        // is the shape TenantScopingCoverageTest::docblocks_do_not_claim_a_test_
        // that_does_not_exist exists to forbid: a false claim of coverage is read
        // as satisfied by everyone downstream.
        //
        // Delete `assertAvailable()`'s key branch and nothing in this file would
        // have failed — the fake answers whether or not a Client could ever have
        // been built. In production that branch is the difference between a
        // parent being told "not available on this site yet" and being told
        // "try again in a moment" forever, while the operator's log blames the
        // provider for a deployment that was simply never finished.
        config(['services.anthropic.key' => '']);

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'x', 'text' => 'Anything at all.'],
            ]))
            ->assertStatus(503);

        $this->assertSame(0, $this->translator->calls);

        // The sentence, because the sentence is the whole difference between
        // this refusal and the transient one above it. An unfinished deployment
        // that invites a retry is a parent tapping a button that will never work.
        $this->assertSame('Translation is not available on this site yet.', $response->json('message'));
    }

    // ------------------------------------------------------------ the call budget

    #[Test]
    public function the_call_ceiling_leaves_the_rest_untranslated_rather_than_fanning_out(): void
    {
        // `throttle:family-translate` counts HTTP REQUESTS, and one request is
        // not one call: twenty misses whose batch reply is unusable used to cost
        // 1 + 20, making a limiter written as "20 a minute" a ceiling of 420
        // provider calls a minute per contact. Point TRANSLATION_MODEL at a model
        // that habitually wraps its JSON and that is the STEADY STATE, at
        // twenty-one times list price, signalled by nothing but a log line.
        config(['translation.max_provider_calls' => 3]);

        $this->translator->batchReply = fn (): string => 'Here you are!';

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'a', 'text' => 'First paragraph.'],
                ['key' => 'b', 'text' => 'Second paragraph.'],
                ['key' => 'c', 'text' => 'Third paragraph.'],
                ['key' => 'd', 'text' => 'Fourth paragraph.'],
                ['key' => 'e', 'text' => 'Fifth paragraph.'],
            ]))
            ->assertOk();

        // Three calls, not six: one batch and two retries. The ceiling is spent
        // and the request stops spending.
        $this->assertSame(3, $this->translator->calls);

        $translations = $response->json('data.translations');

        $this->assertCount(2, $translations);
        $this->assertSame('[ar] First paragraph.', $translations['a']);
        $this->assertSame('[ar] Second paragraph.', $translations['b']);

        // AND THE THREE THAT WERE NOT BOUGHT ARE ABSENT — never present holding
        // their own English, which is the one shape a reader who does not read
        // English could not detect. Absence is how this contract says "still in
        // the original", and the client leaves its notice up on the strength of
        // it.
        foreach (['c', 'd', 'e'] as $key) {
            $this->assertArrayNotHasKey($key, $translations);
        }

        // Said again in a form the client can branch on without diffing keys.
        $response->assertJsonPath('meta.items', 2)
            ->assertJsonPath('meta.requested', 5)
            ->assertJsonPath('meta.complete', false);
    }

    #[Test]
    public function a_ceiling_that_buys_nothing_at_all_is_a_503_rather_than_an_empty_success(): void
    {
        // One call, spent on a batch that could not be parsed, and no budget
        // left to retry a single string with. A 200 carrying an empty map is a
        // success response for a request that achieved nothing: the button stops
        // spinning, the page does not change, and the parent taps it again.
        config(['translation.max_provider_calls' => 1]);

        $this->translator->batchReply = fn (): string => 'Here you are!';

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'a', 'text' => 'First paragraph.'],
                ['key' => 'b', 'text' => 'Second paragraph.'],
            ]))
            ->assertStatus(503);

        $this->assertSame(1, $this->translator->calls);
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertCount(0, ContentTranslation::withoutMasjidScope()->get());
    }

    // --------------------------------------------------------------- the throttle

    #[Test]
    public function the_throttle_on_the_one_endpoint_that_spends_money_is_actually_mounted(): void
    {
        // `throttle:family-translate` is documented in three places — the route
        // file, the controller and the limiter itself — as the cost control for
        // the only endpoint in this realm that spends money, and was asserted
        // nowhere. Drop it in a route refactor and the endpoint silently falls
        // back to the realm's `throttle:family` at 60/min: three times the
        // intended spend from a stolen token or a client stuck in a retry loop,
        // with nothing failing and no other signal, because the limiter
        // definition in AppServiceProvider keeps compiling fine unused.
        $limit = 20;

        for ($i = 0; $i < $limit; $i++) {
            // Distinct text per request, so each one is a real cache miss and a
            // real paid call rather than a free ride on the previous request.
            $this->as($this->parent)
                ->postJson($this->url($this->masjid), $this->payload([
                    ['key' => "k{$i}", 'text' => "Paragraph number {$i}."],
                ]))
                ->assertOk();
        }

        $response = $this->as($this->parent)
            ->postJson($this->url($this->masjid), $this->payload([
                ['key' => 'one-too-many', 'text' => 'Paragraph number twenty.'],
            ]))
            ->assertStatus(429);

        // The limiter's own answer, not the framework's bare 429: this realm
        // renders every refusal as {status:"error", message} and a parent gets a
        // sentence rather than an empty body.
        $response->assertJsonPath('status', 'error');
        $this->assertNotEmpty($response->json('message'));

        // Refused BEFORE the controller, so the twenty-first tap costs nothing.
        $this->assertSame($limit, $this->translator->calls);
    }

    // ----------------------------------------------------------------- retention

    #[Test]
    public function the_purge_removes_translations_nobody_has_read_and_keeps_the_ones_they_have(): void
    {
        $days = (int) config('translation.cache_days');

        $stale = $this->seedCachedTranslation('long forgotten', now()->subDays($days + 10));
        $fresh = $this->seedCachedTranslation('read last week', now()->subDays(7));

        // The COALESCE branch: written once, never served again, so `last_used_at`
        // is NULL and only `updated_at` says how old it is. Without the fallback
        // the sweep would keep exactly the rows nobody ever came back for.
        $neverRead = $this->seedCachedTranslation('never re-opened', null);
        $neverRead->forceFill(['updated_at' => now()->subDays($days + 10)])->save();

        Artisan::call('translations:purge');

        $this->assertNull(ContentTranslation::withoutMasjidScope()->find($stale->id));
        $this->assertNull(ContentTranslation::withoutMasjidScope()->find($neverRead->id));
        $this->assertNotNull(ContentTranslation::withoutMasjidScope()->find($fresh->id));
    }

    #[Test]
    public function the_purge_is_actually_on_the_schedule(): void
    {
        // RetentionScheduleTest exists because three sweeps in this codebase were
        // written, configured and never run — "the cadence is an operator
        // decision" turned out to mean no cron invoked them at all. A retention
        // policy nothing executes is not a policy, so the schedule entry is
        // asserted rather than assumed.
        Artisan::call('list', ['--raw' => true]);

        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'translations:purge'));

        $this->assertNotNull($scheduled, 'translations:purge is not scheduled, so cache_days is never enforced.');
        $this->assertTrue($scheduled->withoutOverlapping, 'a slow sweep may stack up on itself.');
    }

    #[Test]
    public function a_dry_run_of_the_purge_deletes_nothing(): void
    {
        $days = (int) config('translation.cache_days');
        $stale = $this->seedCachedTranslation('long forgotten', now()->subDays($days + 10));

        Artisan::call('translations:purge', ['--dry-run' => true]);

        $this->assertNotNull(ContentTranslation::withoutMasjidScope()->find($stale->id));
    }

    #[Test]
    public function the_purge_narrowed_to_one_school_leaves_every_other_school_alone(): void
    {
        // THE UNBOUND DELETE. This command runs inside `runWithout()`, so the
        // tenant scope adds no constraint and the only thing standing between
        // `--masjid=14` and every school on the platform is one `when()`. Both
        // purge tests above seed a single masjid, so replacing that narrowing
        // with a truthiness check — or dropping it — deletes the whole table
        // and they still pass.
        $days = (int) config('translation.cache_days');
        $otherMasjid = $this->makeMasjid();

        $mine = $this->seedCachedTranslation('mine, long forgotten', now()->subDays($days + 10));
        $theirs = $this->seedCachedTranslation('theirs, long forgotten', now()->subDays($days + 10), $otherMasjid);

        // A string, the way the shell hands it over — the command's narrowing
        // has to be right about the type it actually receives.
        Artisan::call('translations:purge', ['--masjid' => (string) $this->masjid->id]);

        $this->assertNull(ContentTranslation::withoutMasjidScope()->find($mine->id));
        $this->assertNotNull(
            ContentTranslation::withoutMasjidScope()->find($theirs->id),
            'a sweep aimed at one school deleted another school\'s rows'
        );
    }

    #[Test]
    public function the_purge_treats_masjid_zero_as_a_narrowing_and_not_as_no_narrowing(): void
    {
        // The falsy trap the command's own docblock names, and the one
        // `groups:purge-feed` learned the hard way (it is pinned at
        // AdversarialFindingsRegressionTest with the same argument). `--masjid=0`
        // arrives as the STRING "0": a truthiness test reads that as "no masjid
        // given" and silently widens a deleting command to every tenant, which
        // is the worst possible reading of the most cautious possible input.
        //
        // Masjid 0 exists nowhere, so the correct answer is that nothing is
        // deleted at all.
        $days = (int) config('translation.cache_days');
        $otherMasjid = $this->makeMasjid();

        $mine = $this->seedCachedTranslation('mine, long forgotten', now()->subDays($days + 10));
        $theirs = $this->seedCachedTranslation('theirs, long forgotten', now()->subDays($days + 10), $otherMasjid);

        $exit = Artisan::call('translations:purge', ['--masjid' => '0']);

        $this->assertSame(0, $exit);
        $this->assertNotNull(ContentTranslation::withoutMasjidScope()->find($mine->id));
        $this->assertNotNull(ContentTranslation::withoutMasjidScope()->find($theirs->id));
    }

    /**
     * A cache row as the translator would have written it, aged to order.
     *
     * Takes a masjid because the cross-tenant cases above need a SECOND one:
     * a fixture that can only describe one organisation cannot test a delete
     * whose only tenant guard is an argument.
     */
    private function seedCachedTranslation(string $source, $lastUsedAt, ?Masjid $masjid = null): ContentTranslation
    {
        return ContentTranslation::create([
            'masjid_id' => ($masjid ?? $this->masjid)->id,
            'source_hash' => ContentTranslation::hashFor($source),
            'target_lang' => 'ar',
            'model' => 'claude-sonnet-5',
            'translated_text' => '[ar] ' . $source,
            'last_used_at' => $lastUsedAt,
        ]);
    }
}

/**
 * The real translator with its one network call replaced by a counter.
 *
 * Everything above `call()` — the hashing, the cache read and write, the tenant
 * scope, the batch JSON contract and its per-item fallback — is the production
 * code path, unmodified. That is the point: a fake bound over the `Translator`
 * interface would be a stub asserting what a stub returns, because every
 * behaviour this suite pins lives inside AnthropicTranslator rather than in front
 * of it.
 *
 * By default the reply it fabricates honours the real contract, so the real
 * parser is the thing being exercised: a batch (a JSON array of `{i, text}`)
 * comes back as a JSON array of `{i, translation}`, and the per-item fallback's
 * bare-text request comes back as bare text.
 *
 * ---------------------------------------------------------------------------
 * IT HANDS BACK A RAW PROVIDER REPLY, NOT A FINISHED RESULT
 * ---------------------------------------------------------------------------
 *
 * `$batchReply` and `$itemReply` are what a test uses to answer the way a real
 * model does on a bad day: a fenced array, a sentence in front of the JSON, an
 * empty string. The fake previously COULD NOT produce any of those — it always
 * composed a well-formed batch — so `decodeBatch()`'s five rejection branches,
 * the whole per-item fallback, `decodeJsonArray()`'s fence stripping and
 * `incompleteResult()` were dead code in a green suite. Parsing a model's reply
 * is the most fragile thing this feature does, and the fake is where that
 * fragility becomes testable.
 *
 * Both closures receive what the honest fake WOULD have said, so a test that
 * only wants to wrap or mangle the real reply does not have to rebuild it.
 *
 * ---------------------------------------------------------------------------
 * AND WHEN IT FAILS, IT FAILS THE WAY THE NETWORK DOES
 * ---------------------------------------------------------------------------
 *
 * `$failWith` is thrown BARE — a plain RuntimeException, the shape an SDK error
 * or a socket timeout actually has at this seam. It used to be pre-wrapped in
 * `TranslationUnavailableException::providerFailed()` here, inside the one
 * method whose job is to do that wrapping, which made the "a provider failure is
 * a clean 503" test an assertion about the fake. Thrown bare, the conversion has
 * to happen in production code (`AnthropicTranslator::translate()`) or the test
 * sees the 500 a parent would have seen.
 *
 * `$failOnItemCall` narrows the failure to the Nth PER-ITEM call, so a test can
 * let a few translations land and be paid for before the provider dies — which
 * is the only way to prove that what was bought is kept.
 */
final class CountingTranslator extends AnthropicTranslator
{
    /** How many times the provider would have been paid, batch and item alike. */
    public int $calls = 0;

    /**
     * The same total split by shape. `calls` alone cannot tell "one batch of
     * twenty" from "one batch and nineteen retries", and the difference between
     * those two is the difference between one bill and twenty.
     */
    public int $batchCalls = 0;

    public int $itemCalls = 0;

    /**
     * Every batch payload the service actually sent, decoded — the JSON array of
     * `{i, source}` as it went out.
     *
     * Row counts cannot see deduplication: `remember()` writes with
     * updateOrCreate keyed on (hash, language), so a request that sent the same
     * paragraph thirty times and paid for it thirty times still leaves ONE row.
     * What went out on the wire is the only place that guarantee is visible.
     *
     * @var array<int,array<int,array{i:int,source:string}>>
     */
    public array $batches = [];

    /**
     * Thrown from `call()` BARE, the way a transport failure arrives. Never
     * pre-wrapped: see the class docblock.
     */
    public ?RuntimeException $failWith = null;

    /** null: the first call fails. N: only the Nth per-item call fails. */
    public ?int $failOnItemCall = null;

    /**
     * Answer a batch with this instead of the honest reply.
     *
     * @var (Closure(string $honestReply, array<int,array{i:int,source:string}> $sent): string)|null
     */
    public ?Closure $batchReply = null;

    /**
     * Answer a per-item retry with this instead of the honest reply.
     *
     * @var (Closure(string $sourceText): string)|null
     */
    public ?Closure $itemReply = null;

    protected function call(string $system, string $user): string
    {
        $this->calls++;

        $payload = json_decode($user, true);
        $isBatch = is_array($payload) && array_is_list($payload) && isset($payload[0]['i']);

        if ($isBatch) {
            $this->batchCalls++;
            $this->batches[] = $payload;
        } else {
            $this->itemCalls++;
        }

        if ($this->failWith !== null && $this->failsOn($isBatch)) {
            throw $this->failWith;
        }

        if (! $isBatch) {
            // The per-item fallback: bare text in, bare text out.
            return $this->itemReply === null ? '[ar] ' . $user : ($this->itemReply)($user);
        }

        $out = [];

        // Answered out of order on purpose: the parser matches on `i` rather
        // than on position, and a fake that always replied in order would let
        // that regress unnoticed.
        foreach (array_reverse($payload) as $item) {
            // `source` is the key the service sends; `text` is tolerated so a
            // hand-built payload in a future test still answers sensibly.
            $out[] = ['i' => $item['i'], 'translation' => '[ar] ' . ($item['source'] ?? $item['text'])];
        }

        $honest = (string) json_encode($out, JSON_UNESCAPED_UNICODE);

        return $this->batchReply === null ? $honest : ($this->batchReply)($honest, $payload);
    }

    /** A batch call is never the "Nth per-item call", however the counters read. */
    private function failsOn(bool $isBatch): bool
    {
        if ($this->failOnItemCall === null) {
            return true;
        }

        return ! $isBatch && $this->itemCalls === $this->failOnItemCall;
    }
}
