<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\User;
use App\Services\Family\FamilyAccessService;
use App\Support\RosterClaimIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F9 — ONE ANONYMOUS POST AND ONE ORDINARY CLICK.
 *
 * Round five bound the operator's agreement to a list of ids. That is airtight
 * against a row INSERTED after the draw and useless against a row the operator
 * CANNOT TELL APART at draw time.
 *
 * Four public registrations land on a camp form wired to Grade 3 — three real
 * families, and one stranger who knows a child's name and household address (a
 * class list, a WhatsApp group, a birthday invite) and types the MOTHER's name
 * as payer with his own email:
 *
 *     anon POST /api/v1/offerings/autumn-term/register
 *       payer {name:"Aisha Ahmed", email:"bilal@evil.test"}
 *       registrants [{name:"Fatima Ahmed", email:"fatima@household.test"}]
 *     -> 200 {"status":"success","message":"Thank you — your registration is confirmed."}
 *
 * The roster the operator was then served, rendered exactly as the Guardians
 * table drew it:
 *
 *     BANNER: "Confirm all 6"
 *       GUARDIAN ROW #2  "Aisha Ahmed"  ->  —
 *       GUARDIAN ROW #4  "Huda Karim"   ->  —
 *       GUARDIAN ROW #6  "Nadia Sami"   ->  —
 *       GUARDIAN ROW #7  "Aisha Ahmed"  ->  —
 *
 * The ward column was empty on every row — `$snakeAttributes` serialises
 * `guardianOf` as `guardian_of` and the template read `membership.guardianOf` —
 * and no address appeared anywhere. The payload already carried the byte that
 * separated #2 from #7 and the screen dropped it:
 *
 *       #2  email='aisha@household.test'  src_reg={"id":1,...,"contact_id":2}
 *       #7  email='bilal@evil.test'       src_reg={"id":4,...,"contact_id":7}
 *
 * One press:
 *
 *     POST .../groups/1/members/confirm {"membership_ids":[2,4,6,7,...]}
 *     -> 200 {"confirmed":6,"skipped":0,"pending_claims":0}
 *     POST .../contacts/7/family-login {"login_email":"bilal@evil.test"} -> 200
 *     GET  /api/family/.../members/1/awards  (his own bearer token)
 *     -> 200 {"skill_label":"Left the classroom without permission","points":-3,
 *             "student":{"contact":{"first_name":"Fatima","last_name":"Ahmed"}}}
 *
 * `ConfirmGroupMembershipsRequest` claimed the agreement was safe because "ward
 * names, the claimed guardian, and which signup asserted it are all on the
 * screen the button lives on". Measured in `GroupRosterTab.vue` at the time:
 * `source_registration` 0 times, `confirmed_by` 0 times, and both occurrences of
 * `email` inside the add-to-roster picker. The comment had asserted the
 * safeguard into existence.
 *
 * ---------------------------------------------------------------------------
 * F1 (second half) — THE DRAWN ROW MUTATES AND THE ID DOES NOT CHANGE
 * ---------------------------------------------------------------------------
 *
 * A merge re-points `contact_id` on a pending claim; the row id is stable; the
 * operator confirms a relationship they never read. And a merge that
 * force-deletes an absorbed payer nulls `registrations.contact_id`, degrading
 * the provenance evidence WITHOUT touching the membership row at all — so even
 * a fingerprint over the membership's own columns would call that row unchanged.
 *
 * Both are answered by making the caller echo back what it drew, and by skipping
 * — in the vocabulary `skipped` already established — anything that no longer
 * matches its description.
 */
class RosterClaimIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

    private Group $grade3;

    private Offering $offering;

    private FeePlan $plan;

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

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);

        $this->grade3 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($this->grade3)
            ->create(['slug' => 'autumn-term']);

        $this->plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $this->offering->id,
        ]);
    }

    // =================================================== F9 — what the screen carries

    #[Test]
    public function the_roster_payload_names_what_separates_two_claims_over_one_child(): void
    {
        $this->seedTheFourRegistrations();

        Sanctum::actingAs($this->admin);

        $roster = $this->getJson($this->adminUrl("/groups/{$this->grade3->id}/members"))
            ->assertStatus(200)
            ->json();

        [$mother, $stranger] = $this->theTwoAishaRows($roster['data']);

        // THE WARD. This column rendered `—` on every row because the payload
        // key is snake_cased and the template read the camelCase relation name.
        $this->assertSame('Fatima', $mother['guardian_of']['first_name']);
        $this->assertSame('Fatima', $stranger['guardian_of']['first_name']);
        $this->assertSame(
            $mother['guardian_of_contact_id'],
            $stranger['guardian_of_contact_id'],
            'the fixture must exercise two claims over ONE child',
        );

        // THE NAMES ARE THE SAME. That is the whole point of the finding.
        $this->assertSame('Aisha Ahmed', $mother['contact']['first_name'] . ' ' . $mother['contact']['last_name']);
        $this->assertSame('Aisha Ahmed', $stranger['contact']['first_name'] . ' ' . $stranger['contact']['last_name']);

        // THE ADDRESSES ARE NOT, and they are on the row the screen draws.
        $this->assertSame('aisha@household.test', $mother['contact']['email']);
        $this->assertSame('bilal@evil.test', $stranger['contact']['email']);

        // AND THE SIGNUP THAT ASSERTED EACH ONE, with its payer, is served
        // rather than merely eager-loaded and dropped.
        $this->assertSame(RosterClaimIdentity::ORIGIN_REGISTRATION, $mother['claim']['origin']['state']);
        $this->assertSame('aisha@household.test', $mother['claim']['origin']['payer']['email']);
        $this->assertSame(RosterClaimIdentity::ORIGIN_REGISTRATION, $stranger['claim']['origin']['state']);
        $this->assertSame('bilal@evil.test', $stranger['claim']['origin']['payer']['email']);
        $this->assertNotSame(
            $mother['claim']['origin']['registration_id'],
            $stranger['claim']['origin']['registration_id'],
        );

        // AND BOTH ARE MARKED AS WHAT THEY ARE: two rows one operator cannot
        // read apart, each naming the other.
        $this->assertTrue($mother['claim']['contested']);
        $this->assertTrue($stranger['claim']['contested']);
        $this->assertSame([$stranger['id']], $mother['claim']['rival_claim_ids']);
        $this->assertSame([$mother['id']], $stranger['claim']['rival_claim_ids']);

        $this->assertSame(7, $roster['meta']['pending_claims']);
        $this->assertSame(2, $roster['meta']['contested_claims']);

        // A ROSTER LISTING IS NOT WHERE CREDENTIAL COLUMNS BELONG. `with('contact')`
        // served every column of every contact — including the four `login_*`
        // ones .claude/rules/credentials.md keeps out of request bodies — to a
        // screen that renders a name and an address.
        $this->assertArrayNotHasKey('login_email', $mother['contact']);
        $this->assertArrayNotHasKey('login_enabled_at', $mother['contact']);
    }

    #[Test]
    public function the_one_click_that_confirmed_a_stranger_now_leaves_both_claims_standing(): void
    {
        $this->seedTheFourRegistrations();

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        [$mother, $stranger] = $this->theTwoAishaRows($roster['data']);

        $fatimaMembership = $this->participantRowFor($roster['data'], (int) $mother['guardian_of_contact_id']);
        $award = $this->seedAward(
            GroupMembership::withoutMasjidScope()->findOrFail($fatimaMembership['id']),
            'Left the classroom without permission',
        );

        // THE CLICK, in the strongest form: every pending row named, every one
        // described, and NOTHING acknowledged as an individual decision. This is
        // what a client that has never heard of the contested shape sends — and
        // what the SPA would send if the exclusion lived only in the SPA.
        $everything = $this->allPending($roster['data']);

        $response = $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body($everything),
        )->assertStatus(200);

        // The three real families' rows and the three enrolments went through:
        // ONE click, no dialog per family. The pair the operator could not read
        // apart did not.
        $response->assertJsonPath('data.confirmed', 5);
        $response->assertJsonPath('data.skipped', 2);
        $response->assertJsonPath('data.skipped_detail.needs_an_individual_decision', 2);
        $response->assertJsonPath('data.pending_claims', 2);

        $this->assertEqualsCanonicalizing(
            [$mother['id'], $stranger['id']],
            $response->json('data.needs_an_individual_decision'),
        );

        // Said out loud, not buried in a count.
        $this->assertStringContainsString(
            'one entry at a time',
            (string) $response->json('message'),
        );

        // The stranger's edge is still a claim, so it still grants nothing…
        $strangerEdge = GroupMembership::withoutMasjidScope()->findOrFail($stranger['id']);
        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $strangerEdge->provenance);

        // …including the eligibility panel the office reads next, which is the
        // step that turned a claim into a live parent-portal credential.
        app(TenantContext::class)->set($this->masjid->id);
        $this->assertFalse(app(FamilyAccessService::class)->mayHoldAFamilyLogin(
            Contact::withoutMasjidScope()->findOrFail($strangerEdge->contact_id)
        ));
        app(TenantContext::class)->forgetTenant();

        Sanctum::actingAs($this->admin);

        $this->getJson($this->adminUrl("/contacts/{$strangerEdge->contact_id}/family-login"))
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', false);

        // AND THE NEXT ACT IN THE MEASURED CHAIN IS REFUSED. This POST answered
        // 200 and put `bilal@evil.test` on a live credential; the request after
        // it read her behaviour record with his own bearer token.
        $this->postJson($this->adminUrl("/contacts/{$strangerEdge->contact_id}/family-login"), [
            'login_email' => 'bilal@evil.test',
        ])->assertStatus(422);

        // He never gets a working credential at all, so the read is refused at
        // the door rather than by the audience filter behind it.
        $this->as(Contact::withoutMasjidScope()->findOrFail($strangerEdge->contact_id))
            ->getJson($this->familyUrl("/groups/{$this->grade3->id}/members/{$fatimaMembership['id']}/awards"))
            ->assertStatus(401);

        $this->assertNotNull($award->fresh());
    }

    #[Test]
    public function a_contested_claim_is_confirmed_only_when_it_is_named_as_an_individual_decision(): void
    {
        $this->seedTheFourRegistrations();

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        [$mother] = $this->theTwoAishaRows($roster['data']);

        // Naming it among the ordinary entries is not enough — that is exactly
        // the bind round five added, and it is what F9 walked through.
        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body([$mother]),
        )
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 0)
            ->assertJsonPath('data.needs_an_individual_decision', [$mother['id']]);

        $this->assertFalse(GroupMembership::withoutMasjidScope()->findOrFail($mother['id'])->isConfirmed());

        // Naming it a SECOND time — which the SPA does only from the dialog that
        // reads the rival's address out beside this one — is the decision.
        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body([$mother], [$mother['id']]),
        )
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 1);

        $this->assertTrue(GroupMembership::withoutMasjidScope()->findOrFail($mother['id'])->isConfirmed());
    }

    #[Test]
    public function confirming_one_id_at_a_time_does_not_walk_around_the_refusal(): void
    {
        // The obvious way round a rule that fires on sweeps: post the ids one by
        // one. The refusal is not about how many rows a request names — it is
        // about whether the caller said it knows this row has a twin.
        $this->seedTheFourRegistrations();

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();

        foreach ($this->allPending($roster['data']) as $row) {
            $this->postJson(
                $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
                $this->body([$row]),
            )->assertStatus(200);
        }

        [$mother, $stranger] = $this->theTwoAishaRows($roster['data']);

        $this->assertFalse(GroupMembership::withoutMasjidScope()->findOrFail($mother['id'])->isConfirmed());
        $this->assertFalse(GroupMembership::withoutMasjidScope()->findOrFail($stranger['id'])->isConfirmed());
    }

    #[Test]
    public function two_guardians_of_one_child_under_different_names_are_still_one_click(): void
    {
        // THE OVER-REFUSAL THIS MUST NOT BECOME. A mother and a father are two
        // guardian claims over one child, and they are not the F9 shape: two
        // names, two addresses, both legible. Lifting every plural guardianship
        // out of the sweep would put a camp intake back into one dialog per
        // family, which is the cost the bulk affordance exists to avoid.
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');
        $this->register('Yusuf Ahmed', 'yusuf@household.test', 'Fatima Ahmed', 'fatima@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();

        $this->assertSame(0, $roster['meta']['contested_claims']);

        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body($this->allPending($roster['data'])),
        )
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 3)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.pending_claims', 0);
    }

    #[Test]
    public function a_claim_that_matches_a_confirmed_guardian_by_name_is_contested_too(): void
    {
        // The same attack one authenticated act further along: let the office
        // confirm the mother this week, and the identical row sweeps through
        // next week beside a row that now looks settled. A CONFIRMED rival is
        // still a rival.
        $this->seedTheFourRegistrations();

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        [$mother, $stranger] = $this->theTwoAishaRows($roster['data']);

        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body([$mother], [$mother['id']]),
        )->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        // Next week's sweep, drawn fresh.
        $later = $this->drawRoster();

        $this->assertSame(1, $later['meta']['contested_claims']);

        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body($this->allPending($later['data'])),
        )
            ->assertStatus(200)
            ->assertJsonPath('data.needs_an_individual_decision', [$stranger['id']]);

        $this->assertFalse(GroupMembership::withoutMasjidScope()->findOrFail($stranger['id'])->isConfirmed());
    }

    // ============================================ F1 — the row mutated under the draw

    #[Test]
    public function a_claim_re_pointed_at_another_child_after_the_draw_is_skipped_and_reported(): void
    {
        // The shape a merge produces: `contact_id` (or the ward) moves and the
        // row id does not. Applied directly to the row here rather than through
        // the merge verb, because what this endpoint owes the operator is
        // independent of which writer moved it.
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');
        $this->register('Huda Karim', 'huda@household.test', 'Yusuf Karim', 'yusuf@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        $edge = collect($roster['data'])->first(
            fn (array $row): bool => $row['role'] === 'guardian'
                && $row['contact']['email'] === 'aisha@household.test'
        );

        $yusuf = collect($roster['data'])->first(
            fn (array $row): bool => $row['role'] === 'member'
                && $row['contact']['email'] === 'yusuf@household.test'
        );

        // The re-point. Everything the operator read about this row — whose
        // child it is about — is now wrong, and its id is unchanged.
        GroupMembership::withoutMasjidScope()->whereKey($edge['id'])->update([
            'guardian_of_contact_id' => $yusuf['contact_id'],
        ]);

        $response = $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body($drawn),
        )->assertStatus(200);

        $response->assertJsonPath('data.changed_since_shown', [$edge['id']]);
        $response->assertJsonPath('data.skipped_detail.changed_since_shown', 1);
        $this->assertStringContainsString('changed after the roster was drawn', (string) $response->json('message'));

        $this->assertFalse(
            GroupMembership::withoutMasjidScope()->findOrFail($edge['id'])->isConfirmed(),
            'the operator confirmed a guardianship over a child they never read',
        );

        // AND THE REST OF THE CLICK STILL LANDED. A row that moved must not cost
        // the other 199 families their place in the one press.
        $this->assertSame(count($drawn) - 1, $response->json('data.confirmed'));
    }

    #[Test]
    public function evidence_degraded_by_a_merge_is_neither_hidden_nor_swept_over(): void
    {
        // MEASURED, and the reason the fingerprint reaches past the membership
        // row: after a merge force-deletes an absorbed payer contact,
        // `registrations.contact_id` is `nullOnDelete`, so the roster payload
        // reads `"source_registration":{"id":2,...,"contact_id":null}`. The
        // membership row itself is byte-for-byte unchanged. The one field the
        // request's docblock calls the judgeable evidence is degraded by the
        // de-duplication, and "nothing" on the screen is what produced F9.
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        $edge = collect($roster['data'])->first(fn (array $row): bool => $row['role'] === 'guardian');
        $this->assertSame(RosterClaimIdentity::ORIGIN_REGISTRATION, $edge['claim']['origin']['state']);

        Registration::withoutMasjidScope()
            ->whereKey($edge['source_registration_id'])
            ->update(['contact_id' => null]);

        // 1. The description no longer holds, so the drawn click leaves it —
        //    and it leaves the CHILD'S OWN ENROLMENT row too, because that row
        //    was asserted by the same signup and its evidence degraded with it.
        //    That is the intended blast radius: one merge holds back the rows of
        //    the household it touched, which come back on the next draw, and
        //    leaves the other 199 families' rows alone.
        $sameSignup = array_values(array_map(
            fn (array $row): int => (int) $row['id'],
            array_filter($drawn, fn (array $row): bool => (int) $row['source_registration_id'] === (int) $edge['source_registration_id']),
        ));

        $this->assertContains($edge['id'], $sameSignup);

        $held = $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body($drawn),
        )
            ->assertStatus(200)
            ->json('data.changed_since_shown');

        $this->assertEqualsCanonicalizing($sameSignup, $held);

        // 2. And the next draw SAYS the evidence is gone rather than rendering a
        //    blank cell where the payer used to be.
        $redrawn = $this->drawRoster();
        $again = collect($redrawn['data'])->firstWhere('id', $edge['id']);

        $this->assertSame(RosterClaimIdentity::ORIGIN_PAYER_UNAVAILABLE, $again['claim']['origin']['state']);
        $this->assertNull($again['claim']['origin']['payer']);
        $this->assertSame($edge['source_registration_id'], $again['claim']['origin']['registration_id']);
    }

    #[Test]
    public function a_claim_with_no_signup_on_record_says_so_rather_than_showing_nothing(): void
    {
        // The other way evidence goes missing: `RosterMergeService` drops a
        // staff-confirmed edge back to a claim (`unconfirm()`), which clears the
        // confirming actor and leaves no registration behind either.
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'p@household.test']);

        $this->seedMembership($child, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($parent, GroupMembership::ROLE_GUARDIAN, $child);
        $edge->unconfirm()->save();

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $row = collect($roster['data'])->firstWhere('id', $edge->id);

        $this->assertSame(RosterClaimIdentity::ORIGIN_UNRECORDED, $row['claim']['origin']['state']);
        $this->assertNull($row['claim']['origin']['registration_id']);

        // It is still confirmable — the merge remedy this codebase documents
        // must keep working — it is simply not confirmable in ignorance.
        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->body([$row]),
        )->assertStatus(200)->assertJsonPath('data.confirmed', 1);
    }

    #[Test]
    public function naming_a_row_without_saying_what_it_showed_is_refused(): void
    {
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        // The round-five wire shape exactly: ids and nothing else.
        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), [
            'membership_ids' => array_column($drawn, 'id'),
        ])->assertStatus(422);

        // …and a list that describes only some of what it names.
        $body = $this->body($drawn);
        unset($body['fingerprints'][array_key_first($body['fingerprints'])]);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $body)
            ->assertStatus(422);

        foreach ($drawn as $row) {
            $this->assertFalse(GroupMembership::withoutMasjidScope()->findOrFail($row['id'])->isConfirmed());
        }
    }

    #[Test]
    public function a_fingerprint_cannot_be_replayed_from_one_row_onto_another(): void
    {
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');
        $this->register('Huda Karim', 'huda@household.test', 'Yusuf Karim', 'yusuf@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        $body = $this->body($drawn);
        $ids = array_keys($body['fingerprints']);
        $body['fingerprints'][$ids[0]] = $body['fingerprints'][$ids[1]];

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $body)
            ->assertStatus(200)
            ->assertJsonPath('data.changed_since_shown', [(int) $ids[0]]);
    }

    // ====================================== CONFIRMED SOUND — do not regress these

    #[Test]
    public function a_registration_arriving_while_the_dialog_is_open_is_still_left_alone(): void
    {
        $this->register('Huda Karim', 'huda@household.test', 'Yusuf Karim', 'yusuf@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        // The insertion, mid-dialog, from an anonymous caller.
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $this->register('Bilal Attacker', 'bilal@evil.test', 'Salma Other', 'salma@school.test');

        Auth::forgetGuards();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $this->body($drawn))
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', count($drawn))
            ->assertJsonPath('data.pending_claims', 2);

        $planted = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->get()
            ->first(fn (GroupMembership $m): bool => ! in_array($m->id, array_column($drawn, 'id'), true));

        $this->assertNotNull($planted, 'the fixture must exercise the inserted row');
        $this->assertFalse($planted->isConfirmed());
    }

    #[Test]
    public function two_hundred_signups_are_still_one_request_and_one_press(): void
    {
        // THE COST SIDE OF EVERY GUARD ABOVE. A school that takes 200 camp
        // signups must not face 200 dialogs — the ids, the fingerprints and the
        // (empty) contested list all ride the one POST the one button sends.
        for ($i = 0; $i < 200; $i++) {
            $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
            $this->seedClaim($child, GroupMembership::ROLE_MEMBER);
        }

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        $this->assertCount(200, $drawn);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $this->body($drawn))
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 200)
            ->assertJsonPath('data.pending_claims', 0);
    }

    #[Test]
    public function a_stale_screen_re_submitting_a_settled_list_is_still_an_honest_no_op(): void
    {
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $drawn = $this->allPending($roster['data']);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $this->body($drawn))
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', count($drawn));

        // The same screen, clicked again. Not a 422 an operator cannot act on,
        // and the reason is separated from the two that need a reaction.
        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $this->body($drawn))
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 0)
            ->assertJsonPath('data.skipped', count($drawn))
            ->assertJsonPath('data.skipped_detail.already_settled', count($drawn))
            ->assertJsonPath('data.skipped_detail.changed_since_shown', 0)
            ->assertJsonPath('data.changed_since_shown', []);
    }

    #[Test]
    public function confirming_still_reaches_only_this_group_and_this_tenant(): void
    {
        $other = $this->makeMasjid();
        $otherGroup = Group::factory()->create(['masjid_id' => $other->id, 'kind' => Group::KIND_CLASS]);
        $otherChild = Contact::factory()->create(['masjid_id' => $other->id]);

        $foreign = new GroupMembership([
            'masjid_id' => $other->id,
            'group_id' => $otherGroup->id,
            'contact_id' => $otherChild->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $foreign->selfAssertedFrom(null)->save();

        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $mine = $this->seedClaim($child, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $body = $this->body($this->allPending($roster['data']));

        // A foreign id, carrying a fingerprint the caller could only have made
        // up, is a MISS rather than a leak: it is not on this group's roster, so
        // nothing is compared and nothing is confirmed.
        $body['membership_ids'][] = $foreign->id;
        $body['fingerprints'][(string) $foreign->id] = str_repeat('a', 32);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $body)
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 1)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.skipped_detail.already_settled', 1);

        $this->assertTrue($mine->fresh()->isConfirmed());
        $this->assertFalse($foreign->fresh()->isConfirmed());
    }

    #[Test]
    public function typing_the_entry_in_by_hand_confirms_it_and_says_it_has_a_twin(): void
    {
        // THE OTHER DOOR ONTO THE SAME GRANT. `store()` confirms a pending
        // duplicate rather than answering 422, so an operator can reach the
        // contested row without going through the contested dialog.
        //
        // That is NOT closed, and the argument is in the controller: searching
        // the directory, picking ONE contact out of a list that shows each one's
        // address, and naming the ward IS the individual, addressed decision the
        // contested path asks for. What the operator had no way of knowing is
        // that a second adult of the same name is also claiming this child — so
        // the response says so.
        $this->seedTheFourRegistrations();

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        [$mother] = $this->theTwoAishaRows($roster['data']);

        $response = $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $mother['contact_id'],
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $mother['guardian_of_contact_id'],
        ])->assertStatus(200);

        $this->assertTrue(GroupMembership::withoutMasjidScope()->findOrFail($mother['id'])->isConfirmed());

        $message = (string) $response->json('message');
        $this->assertStringContainsString('1 other roster entry claims', $message);
        $this->assertStringContainsString('same name', $message);
    }

    #[Test]
    public function an_uncontested_entry_typed_in_by_hand_is_not_warned_about(): void
    {
        // The cost side of the sentence above: an ordinary confirmation through
        // the add form must not grow a warning that is always there.
        $this->register('Huda Karim', 'huda@household.test', 'Yusuf Karim', 'yusuf@household.test');

        Sanctum::actingAs($this->admin);

        $roster = $this->drawRoster();
        $edge = collect($roster['data'])->first(fn (array $row): bool => $row['role'] === 'guardian');

        $message = (string) $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $edge['contact_id'],
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $edge['guardian_of_contact_id'],
        ])->assertStatus(200)->json('message');

        $this->assertStringNotContainsString('other roster entry', $message);
    }

    // ==================================== the screen, measured rather than asserted

    #[Test]
    public function the_roster_screen_still_draws_the_identity_this_endpoint_serves(): void
    {
        // THE DOCBLOCK-VS-WIRE CHECK, made into a test because that gap IS the
        // finding. `ConfirmGroupMembershipsRequest` said the ward names, the
        // claimed guardian and the asserting signup were "all on the screen the
        // button lives on"; the file contained `source_registration` 0 times,
        // `confirmed_by` 0 times and no address on either table. A sentence in a
        // comment cannot notice when a template stops rendering something.
        $screen = file_get_contents(base_path('resources/vue-app/views/dashboard/groups/GroupRosterTab.vue'));

        $this->assertIsString($screen);

        foreach (['addressLabel(', 'originLabel(', 'isContested(', 'membership.guardian_of'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $screen,
                "the roster screen stopped drawing what the confirm decision is made from: {$needle}",
            );
        }

        // The camelCase relation name is what made the ward column render `—` on
        // every row while the docblock called it the safeguard. Asserted on the
        // CALL rather than the token, because the file's own docblock now quotes
        // the broken name while explaining it.
        $this->assertStringNotContainsString('fullName(membership.guardianOf)', $screen);

        // And the bulk press must keep sending fingerprints — the argument that
        // makes a mutated row skippable rather than silently confirmed.
        $store = file_get_contents(base_path('resources/vue-app/stores/masjid/groupsStore.ts'));
        $this->assertIsString($store);
        $this->assertStringContainsString('fingerprints[', $store);
        $this->assertStringContainsString('contested_membership_ids[]', $store);
    }

    // ============================================================== helpers

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }

    private function familyUrl(string $path = ''): string
    {
        return "/api/family/masjids/{$this->masjid->id}" . $path;
    }

    private function as(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
    }

    /** The roster exactly as the SPA draws it. */
    private function drawRoster(): array
    {
        return $this->getJson($this->adminUrl("/groups/{$this->grade3->id}/members"))
            ->assertStatus(200)
            ->json();
    }

    /** @return array<int, array> the pending rows of a drawn roster */
    private function allPending(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['provenance'] !== GroupMembership::PROVENANCE_CONFIRMED,
        ));
    }

    /**
     * The request body the SPA sends: the ids it drew, what each one said, and
     * (only from the contested dialog) the rows decided one at a time.
     *
     * @param  array<int, array>  $rows
     * @param  array<int, int>  $contested
     */
    private function body(array $rows, array $contested = []): array
    {
        $body = [
            'membership_ids' => array_map(fn (array $row): int => (int) $row['id'], $rows),
            'fingerprints' => [],
        ];

        foreach ($rows as $row) {
            $body['fingerprints'][(string) $row['id']] = $row['claim']['fingerprint'];
        }

        if ($contested !== []) {
            $body['contested_membership_ids'] = $contested;
        }

        return $body;
    }

    /** @return array{0: array, 1: array} the mother's row, then the stranger's */
    private function theTwoAishaRows(array $rows): array
    {
        $aishas = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['role'] === 'guardian'
                && $row['contact']['first_name'] === 'Aisha',
        ));

        $this->assertCount(2, $aishas, 'the fixture must exercise two claims under one name');

        $mother = $aishas[0]['contact']['email'] === 'aisha@household.test' ? $aishas[0] : $aishas[1];
        $stranger = $aishas[0]['contact']['email'] === 'aisha@household.test' ? $aishas[1] : $aishas[0];

        return [$mother, $stranger];
    }

    private function participantRowFor(array $rows, int $contactId): array
    {
        $row = collect($rows)->first(
            fn (array $r): bool => $r['role'] !== 'guardian' && (int) $r['contact_id'] === $contactId
        );

        $this->assertNotNull($row, 'the ward must hold a participant row');

        return $row;
    }

    /** Three real families and one stranger, all through the public endpoint. */
    private function seedTheFourRegistrations(): void
    {
        $this->register('Aisha Ahmed', 'aisha@household.test', 'Fatima Ahmed', 'fatima@household.test');
        $this->register('Huda Karim', 'huda@household.test', 'Yusuf Karim', 'yusuf@household.test');
        $this->register('Nadia Sami', 'nadia@household.test', 'Layla Sami', 'layla@household.test');

        // The stranger: the MOTHER's name, his own email, her child's real
        // address off a class list.
        $this->register('Aisha Ahmed', 'bilal@evil.test', 'Fatima Ahmed', 'fatima@household.test');
    }

    private function register(string $payer, string $payerEmail, string $child, string $childEmail): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $this->postJson("/api/v1/offerings/{$this->offering->slug}/register", [
            'fee_plan_id' => $this->plan->id,
            'payer' => ['name' => $payer, 'email' => $payerEmail],
            'registrants' => [['name' => $child, 'email' => $childEmail]],
            'data' => ['full_name' => $child],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
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
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function seedMembership(Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        $membership = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);

        $membership->confirmedByStaff($this->admin)->save();

        return $membership;
    }

    private function seedClaim(Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        $membership = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);

        $membership->selfAssertedFrom(null)->save();

        return $membership;
    }

    private function seedAward(GroupMembership $subject, string $label): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $subject->group_id,
            'group_membership_id' => $subject->id,
            'skill_label' => $label,
            'skill_polarity' => BehaviorSkill::POLARITY_NEGATIVE,
            'points' => -3,
        ]);
    }
}
