<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ConfirmsRosterClaims;
use Tests\TestCase;

/**
 * A SERVER MUST NOT READ A STATE IT REFUSES TO WRITE.
 *
 * `GroupConsentController::update()` has always answered 422 on a pending claim
 * — "consent has to be recorded against a relationship this organisation has
 * stood behind". Every READ path served the state anyway. Measured on a row left
 * in that state by a merge, before this round:
 *
 *     GET  …/consent -> 200 {"scope":"media","covers_feed":true,"covers_media":true}
 *     PUT  …/consent -> 422 "still an unconfirmed claim … Confirm it on the roster first"
 *     CONFIRM        -> {"confirmed":1,"skipped":0}
 *     edge after     -> {"provenance":"confirmed","consent_scope":"media"}
 *     family feed    -> 200 [{"title":"Class photograph", …}]
 *
 * Two halves are needed and NEITHER IS SUFFICIENT, which is the argument the
 * backfill migration makes and which
 * `the_read_gate_alone_still_lets_one_confirm_click_re_arm_stale_consent` below
 * MEASURES rather than asserts:
 *
 *  - `GroupMembership::hasConsent()` consults provenance, so every read path
 *    (this controller, `consentCovers()`, `App\Support\GroupAudience`) refuses
 *    the state. That holds for rows a future writer forgets about, and it does
 *    NOT stop the stale grant re-arming itself the moment somebody confirms the
 *    CLAIM — a decision about a relationship, made on a roster screen that says
 *    nothing about photographs.
 *  - `…_clear_consent_on_unconfirmed_group_memberships` erases the bytes on the
 *    rows already on disk. That closes the re-arm and reaches nothing written
 *    afterwards.
 *
 * HOW THE FIXTURE IS BUILT MATTERS. The pre-fix state is planted with a raw
 * `DB::table()->update()`, deliberately: `unconfirm()` now clears the columns and
 * `update()` refuses to write them, so there is NO LONGER ANY WAY through the
 * application to produce the row this is about. That is the point — the rows
 * exist on disk at tenants that ran a merge, and nothing in the code can make
 * one any more.
 */
class UnconfirmedConsentTest extends TestCase
{
    use RefreshDatabase;
    use ConfirmsRosterClaims;

    private Masjid $masjid;

    private User $admin;

    private Group $grade3;

    private Contact $child;

    private Contact $parent;

    private GroupMembership $childsRow;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->grade3 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->child = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Salma',
            'last_name' => 'Other',
            'email' => null,
        ]);

        $this->childsRow = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $this->child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $this->childsRow->confirmedByStaff($this->admin)->save();

        $this->parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Bilal',
            'last_name' => 'Claimant',
            'email' => 'bilal@evil.test',
        ]);
    }

    // ============================================== the read side of the gate

    #[Test]
    public function an_unconfirmed_claim_carrying_a_consent_record_reads_as_granting_nothing(): void
    {
        $edge = $this->plantPreFixRow();

        Sanctum::actingAs($this->admin);

        $shown = $this->getJson($this->consentUrl($edge))->assertStatus(200);

        // What the endpoint used to serve, and what a disclosure read from it.
        $shown->assertJsonPath('data.scope', null);
        $shown->assertJsonPath('data.covers_feed', false);
        $shown->assertJsonPath('data.covers_media', false);
        $shown->assertJsonPath('data.granted_at', null);

        // AND IT IS NOT A BLANK. The office is told a record exists, that it
        // grants nothing, and what has to happen first.
        $shown->assertJsonPath('data.withheld_pending_confirmation', true);
        $this->assertStringContainsString(
            'unconfirmed claim',
            (string) $shown->json('data.withheld_reason'),
        );

        // The write half has always said this; now the two agree.
        $this->putJson($this->consentUrl($edge), ['scope' => GroupMembership::CONSENT_MEDIA])
            ->assertStatus(422);

        // And the model itself, which is where the gate lives.
        $this->assertFalse($edge->fresh()->hasConsent());
        $this->assertFalse($edge->fresh()->consentCovers(GroupMembership::CONSENT_MEDIA));
        $this->assertFalse($edge->fresh()->consentCovers(GroupMembership::CONSENT_FEED));
    }

    #[Test]
    public function a_confirmed_edge_with_real_consent_is_untouched_by_the_gate(): void
    {
        // WHAT THIS GUARD MUST NOT REFUSE. The ordinary case is a confirmed edge
        // with consent an office recorded through the endpoint, and it has to go
        // on granting exactly what it granted.
        $edge = $this->seedGuardianEdge(confirmed: true);

        Sanctum::actingAs($this->admin);

        $this->putJson($this->consentUrl($edge), ['scope' => GroupMembership::CONSENT_MEDIA])
            ->assertStatus(200);

        $this->getJson($this->consentUrl($edge))
            ->assertStatus(200)
            ->assertJsonPath('data.scope', GroupMembership::CONSENT_MEDIA)
            ->assertJsonPath('data.covers_feed', true)
            ->assertJsonPath('data.covers_media', true)
            ->assertJsonPath('data.withheld_pending_confirmation', false);

        $this->assertNotNull($this->getJson($this->consentUrl($edge))->json('data.granted_at'));
        $this->assertTrue($edge->fresh()->hasConsent());

        // The query-level definition agrees with the row-by-row one.
        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()->whereKey($edge->id)->consented()->count(),
        );
    }

    #[Test]
    public function the_query_scope_and_the_method_refuse_the_same_rows(): void
    {
        // `scopeConsented()` and `hasConsent()` are one definition, per the
        // model's own docblock. A row the method refuses must not be a row the
        // scope counts — a disclosure decided by which of the two a caller
        // happened to reach for is the shape this round is named after.
        $stale = $this->plantPreFixRow();

        $this->assertFalse($stale->fresh()->hasConsent());
        $this->assertSame(
            0,
            GroupMembership::withoutMasjidScope()->whereKey($stale->id)->consented()->count(),
        );
    }

    // ============================================ why the read gate is not enough

    #[Test]
    public function the_read_gate_alone_still_lets_one_confirm_click_re_arm_stale_consent(): void
    {
        // THE MEASUREMENT THE BACKFILL'S DOCBLOCK RESTS ON, and the reason a
        // read gate is not the whole fix. This row is planted AFTER the
        // migrations have run, so it is exactly a row the backfill did not
        // reach — which is the state a tenant is in between the merge that
        // wrote it and the deploy that cleans it.
        $edge = $this->plantPreFixRow();
        $this->seedPhotographPost();

        Sanctum::actingAs($this->admin);

        // A live credential, issued while the row is a claim: `enable()` refuses
        // that, so the office confirms first in the real sequence. Here the
        // credential is written directly, because what is under test is the
        // DISCLOSURE and not the door.
        $this->parent->forceFill([
            'login_email' => 'bilal@evil.test',
            'login_enabled_at' => now(),
        ])->save();

        // The gate holds — the consent screen reports nothing in force…
        $this->getJson($this->consentUrl($edge))
            ->assertStatus(200)
            ->assertJsonPath('data.scope', null);

        // …and the feed is shut, because an unconfirmed edge grants no standing.
        $this->asFamily($this->parent->fresh())
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups/{$this->grade3->id}/posts")
            ->assertStatus(403);

        // Then ONE ordinary roster act, about a RELATIONSHIP, on a screen that
        // says nothing about photographs.
        Sanctum::actingAs($this->admin);
        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->confirmBody([$edge->id]),
        )->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertTrue(
            $edge->fresh()->hasConsent(),
            'the premise of the backfill no longer holds — re-read the migration docblock',
        );
        $this->getJson($this->consentUrl($edge))
            ->assertJsonPath('data.covers_media', true);

        // And the photograph bytes are open, on a permission nobody granted
        // about this pair and nobody decided a second time. THIS is what the
        // backfill exists to remove; the read gate cannot reach it.
        $this->asFamily($this->parent->fresh())
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups/{$this->grade3->id}/posts")
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.title', 'Class photograph')
            ->assertJsonPath('data.data.0.media_withheld', false);
    }

    #[Test]
    public function the_backfill_erases_the_record_so_the_same_confirm_click_opens_nothing(): void
    {
        $edge = $this->plantPreFixRow();
        $this->seedPhotographPost();

        $this->parent->forceFill([
            'login_email' => 'bilal@evil.test',
            'login_enabled_at' => now(),
        ])->save();

        // The deploy runs. (`RefreshDatabase` has already applied this
        // migration to an empty table, so it is re-run here against the row it
        // is actually aimed at.)
        $this->runTheBackfill();

        $this->assertNull($edge->fresh()->consent_granted_at);
        $this->assertNull($edge->fresh()->consent_scope);

        Sanctum::actingAs($this->admin);

        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->confirmBody([$edge->id]),
        )->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        // Confirmed — the relationship now stands. Consent is a SECOND decision
        // and is back to the state "never given", which is what withdrawal
        // means here and what makes "absence means no consent" true.
        $this->assertTrue($edge->fresh()->isConfirmed());
        $this->assertFalse($edge->fresh()->hasConsent());

        $this->getJson($this->consentUrl($edge))
            ->assertStatus(200)
            ->assertJsonPath('data.scope', null)
            ->assertJsonPath('data.covers_media', false)
            // Nothing on record at all now, so there is nothing to explain.
            ->assertJsonPath('data.withheld_pending_confirmation', false);

        // The same credential, the same confirm click, the same feed — and the
        // photographs stay shut, because the second decision has not been made.
        // This is the assertion that fails without the backfill: measured above
        // in `the_read_gate_alone_still_lets_one_confirm_click_re_arm…`, the
        // identical sequence answers 200 with `media_withheld: false`.
        $this->asFamily($this->parent->fresh())
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups/{$this->grade3->id}/posts")
            ->assertStatus(403);
    }

    #[Test]
    public function the_backfill_leaves_every_confirmed_edges_consent_alone(): void
    {
        // WHAT THE BACKFILL MUST NOT ERASE. A school's whole consent register
        // hangs on this: the rows an office recorded against confirmed edges are
        // the ordinary state and must survive the deploy untouched.
        //
        // A SECOND CHILD, because `group_memberships_edge_unique` is
        // (group, contact, role, ward) — one parent cannot hold two guardian
        // edges over one child, which is the index doing its job.
        $sibling = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Yusuf',
            'last_name' => 'Other',
            'email' => null,
        ]);

        $siblingsRow = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $sibling->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $siblingsRow->confirmedByStaff($this->admin)->save();

        $keep = $this->seedGuardianEdge(confirmed: true, ward: $sibling);
        DB::table('group_memberships')->where('id', $keep->id)->update([
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        $erase = $this->plantPreFixRow();

        $this->runTheBackfill();

        $this->assertNotNull($keep->fresh()->consent_granted_at);
        $this->assertSame(GroupMembership::CONSENT_MEDIA, $keep->fresh()->consent_scope);
        $this->assertTrue($keep->fresh()->hasConsent());

        $this->assertNull($erase->fresh()->consent_granted_at);
        $this->assertNull($erase->fresh()->consent_scope);
    }

    #[Test]
    public function a_null_provenance_is_unreachable_today_which_is_what_makes_the_backfills_null_clause_belt_and_braces(): void
    {
        // `scopePendingClaims()` spells NULL out in SQL because SQL will not:
        // `provenance != 'confirmed'` is UNKNOWN for a NULL and the row drops
        // out of the result — a NULL would read as pending to every human and be
        // invisible to the one statement meant to clean it. The backfill carries
        // the same `orWhereNull`.
        //
        // MEASURED RATHER THAN ASSUMED, in both directions. The clause cannot be
        // exercised, because the column is NOT NULL — so this pins the PREMISE
        // instead: the day that constraint is relaxed (one nullable column or one
        // import away, per the model's own docblock), this test fails and the
        // `orWhereNull` stops being decoration.
        $edge = $this->plantPreFixRow();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('group_memberships')->where('id', $edge->id)->update(['provenance' => null]);
    }

    #[Test]
    public function the_backfill_reaches_a_participant_row_that_should_never_have_carried_consent(): void
    {
        // Only a guardian row can carry meaningful consent, so a participant row
        // with the columns set is data that should not exist. It is cleared too
        // rather than left for a future `role` change to activate.
        DB::table('group_memberships')->where('id', $this->childsRow->id)->update([
            'provenance' => GroupMembership::PROVENANCE_SELF_ASSERTED,
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        $this->runTheBackfill();

        $this->assertNull($this->childsRow->fresh()->consent_granted_at);
        $this->assertNull($this->childsRow->fresh()->consent_scope);
    }

    // ===================================================== the disclosure itself

    #[Test]
    public function a_stale_consent_record_does_not_open_the_photographs(): void
    {
        // The far end of the chain, on the wire. The parent holds a live
        // credential and a guardian edge that IS confirmed — so the feed is
        // reachable — while the consent record on it is the corrupt kind. The
        // photographs must be withheld.
        $edge = $this->seedGuardianEdge(confirmed: true);

        $this->seedPhotographPost();

        // The credential is issued FIRST, while the edge is still confirmed —
        // which is how a real one comes to exist. Corrupting the row first would
        // make `enable()` refuse and prove only that the door is shut, not that
        // the disclosure is.
        Sanctum::actingAs($this->admin);

        // Consent recorded the way an office records it — through the endpoint,
        // against a confirmed edge, which is the only state that accepts it.
        $this->putJson($this->consentUrl($edge), ['scope' => GroupMembership::CONSENT_MEDIA])
            ->assertStatus(200);

        $this->postJson($this->adminUrl("/contacts/{$this->parent->id}/family-login"), [
            'login_email' => 'bilal@evil.test',
        ])->assertStatus(200);

        // With a live credential and a confirmed edge, the feed opens and the
        // photographs come with it. That is the baseline this is measured
        // against — without it, a later 403 could mean anything.
        $this->asFamily($this->parent->fresh())
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups/{$this->grade3->id}/posts")
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.media_withheld', false);

        // Now the edge is re-opened as a claim WITHOUT the columns being
        // cleared — the exact pre-fix row, under a credential that still works.
        DB::table('group_memberships')->where('id', $edge->id)->update([
            'provenance' => GroupMembership::PROVENANCE_SELF_ASSERTED,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        // An unconfirmed edge grants no standing at all, so the feed is refused
        // outright rather than merely stripped of its attachments — which is
        // `GroupAudience::membershipsFor()` filtering on `confirmed()`, and is
        // the stronger of the two refusals.
        $this->asFamily($this->parent->fresh())
            ->getJson("/api/family/masjids/{$this->masjid->id}/groups/{$this->grade3->id}/posts")
            ->assertStatus(403);
    }

    // ============================================================== helpers

    /**
     * A row in the state the previous `unconfirm()` left behind: a pending claim
     * still carrying a `media` consent record.
     *
     * Written with the query builder because there is no longer any path through
     * the application that can produce it — which is the whole reason a backfill
     * is needed rather than a code fix alone.
     */
    private function plantPreFixRow(): GroupMembership
    {
        $edge = $this->seedGuardianEdge(confirmed: true);

        DB::table('group_memberships')->where('id', $edge->id)->update([
            'provenance' => GroupMembership::PROVENANCE_SELF_ASSERTED,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        return $edge->fresh();
    }

    private function seedGuardianEdge(bool $confirmed, ?Contact $ward = null): GroupMembership
    {
        $edge = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $this->parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => ($ward ?? $this->child)->id,
        ]);

        $confirmed
            ? $edge->confirmedByStaff($this->admin)->save()
            : $edge->selfAssertedFrom(null)->save();

        return $edge;
    }

    /** One post with an attachment, so `media_withheld` has something to be about. */
    private function seedPhotographPost(): GroupPost
    {
        $post = GroupPost::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'title' => 'Class photograph',
        ]);

        $post->attachments()->create([
            'masjid_id' => $this->masjid->id,
            'disk' => 'private',
            'path' => 'group-posts/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);

        return $post;
    }

    private function runTheBackfill(): void
    {
        (require base_path(
            'database/migrations/2026_08_21_000000_clear_consent_on_unconfirmed_group_memberships.php'
        ))->up();
    }

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }

    private function consentUrl(GroupMembership $edge): string
    {
        return $this->adminUrl("/groups/{$this->grade3->id}/members/{$edge->id}/consent");
    }

    private function asFamily(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
    }
}
