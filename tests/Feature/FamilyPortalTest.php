<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupPost;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Support\GroupAudience;
use App\Support\GroupPostAttachments;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-015e — an authenticated parent finally sees something, and never sees
 * anybody else's child.
 *
 * ---------------------------------------------------------------------------
 * THE FIXTURE IS THE TEST
 * ---------------------------------------------------------------------------
 *
 * Every case below runs against ONE classroom holding TWO families. That is not
 * incidental: a suite where each parent has a group to themselves would pass
 * with the audience rules deleted, because there would be nothing to leak. Here
 * Amina's mother and Bilal's father are in the same group, on the same feed,
 * beside each other's children's behaviour records, ḥifẓ records and
 * teacher-guardian threads — which is the actual shape of a weekend school and
 * the exact configuration `.claude/rules/groups.md` says "guardian here never
 * meant guardian of this child" about.
 *
 * Design invariants 6 and 7 (`docs/t015-parent-identity-design.md` §9) are what
 * this file exists to pin:
 *
 *   6. Guardian A asking for guardian B's ward: 403 at the endpoint AND zero
 *      rows from the readable* queries. Both halves, because the 403 is honest
 *      and the query constraint is what makes the honesty safe — a count, a
 *      paginator total or a SUM() must not be a side channel.
 *   7. A guardian with NO consent record: no feed, no media, `media_withheld`
 *      — and their own ward's participant thread, awards and ḥifẓ still
 *      readable, because consent gates broadcasts and not a parent's view of
 *      their own child.
 */
class FamilyPortalTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Group $group;

    private Contact $parentA;
    private Contact $childA;
    private GroupMembership $childAMembership;

    private Contact $parentB;
    private Contact $childB;
    private GroupMembership $childBMembership;

    private Contact $teacher;

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

        // Group media is PRIVATE — fake the disk the feature names, never
        // 'public' (.claude/rules/private-uploads.md).
        Storage::fake((string) config('groups.media.disk'));

        $this->masjid = $this->makeMasjid();
        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        // ---- family A ----
        $this->parentA = $this->makeParent($this->masjid);
        $this->childA = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
        ]);
        $this->childAMembership = $this->seedMembership($this->childA, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->parentA, GroupMembership::ROLE_GUARDIAN, $this->childA);

        // ---- family B, in the SAME group ----
        $this->parentB = $this->makeParent($this->masjid);
        $this->childB = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Bilal',
        ]);
        $this->childBMembership = $this->seedMembership($this->childB, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->parentB, GroupMembership::ROLE_GUARDIAN, $this->childB);

        // ---- the teacher, as a roster row ----
        $this->teacher = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->teacher, GroupMembership::ROLE_LEADER);
    }

    // ---------------------------------------------------------------- helpers

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
        ], $overrides));
    }

    /** `forceFill` because the four login_* columns are deliberately not fillable. */
    private function makeParent(Masjid $masjid, array $login = []): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $contact->forceFill(array_merge([
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ], $login))->save();

        return $contact->refresh();
    }

    private function seedMembership(
        Contact $contact,
        string $role,
        ?Contact $ward = null,
        ?string $consentScope = null
    ): GroupMembership {
        return GroupMembership::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
            'consent_granted_at' => $consentScope !== null ? now() : null,
            'consent_scope' => $consentScope,
        ]);
    }

    /** Record consent on a guardian edge, as GroupConsentController would. */
    private function consent(Contact $parent, string $scope): void
    {
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->group->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => now(), 'consent_scope' => $scope]);
    }

    /**
     * Authenticate as a parent for the NEXT request.
     *
     * A real bearer token, not `Sanctum::actingAs()`: `actingAs` calls
     * `setUser()` on the guard directly and never enters
     * `Laravel\Sanctum\Guard::__invoke()`, where the provider comparison that
     * keeps the two realms apart actually lives. The guards and the tenant are
     * dropped first so each call is an honest new request — `RequestGuard::user()`
     * memoizes and `TenantContext` is a scoped binding nothing clears mid-process,
     * so without this a second call inside one test would be answered out of the
     * first call's state.
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

    private function studentUrl(int $membershipId, string $path = ''): string
    {
        return $this->groupUrl("/members/{$membershipId}/student".$path);
    }

    /** The token a parent mints when handing the phone to their child. */
    private function handoffToken(Contact $parent, int $membershipId): string
    {
        $response = $this->as($parent)
            ->postJson($this->groupUrl("/members/{$membershipId}/student-session"))
            ->assertCreated();

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $response->json('data.token');
    }

    private function url(string $path = ''): string
    {
        return "/api/family/masjids/{$this->masjid->id}" . $path;
    }

    private function groupUrl(string $path = ''): string
    {
        return $this->url("/groups/{$this->group->id}" . $path);
    }

    private function audience(): GroupAudience
    {
        app(TenantContext::class)->set($this->masjid->id);

        return app(GroupAudience::class);
    }

    private function seedPostWithImage(string $title = 'Trip photos'): GroupPost
    {
        $post = GroupPost::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'title' => $title,
        ]);

        GroupPostAttachments::store($post, [
            UploadedFile::fake()->create('class.jpg', 40, 'image/jpeg'),
        ]);

        return $post->refresh();
    }

    private function seedAward(GroupMembership $subject, string $label): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'group_membership_id' => $subject->id,
            'skill_label' => $label,
            'skill_polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'points' => 3,
            'note' => $label . ' note',
        ]);
    }

    private function seedHifz(GroupMembership $subject, int $toAyah): HifzEntry
    {
        return HifzEntry::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'group_membership_id' => $subject->id,
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78,
            'from_ayah' => 1,
            'to_surah' => 78,
            'to_ayah' => $toAyah,
        ]);
    }

    private function seedParticipantThread(GroupMembership $about, string $subject): GroupThread
    {
        $thread = GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $about->id,
            'subject' => $subject,
        ]);

        $thread->messages()->create([
            'masjid_id' => $this->masjid->id,
            'body' => 'A private note about ' . $subject,
        ]);

        return $thread;
    }

    // ------------------------------------------------- 0. the parent can REPLY (T-015f)

    #[Test]
    public function a_parent_can_reply_in_a_thread_about_their_own_child(): void
    {
        $thread = $this->seedParticipantThread($this->childAMembership, 'Amina');

        $this->as($this->parentA)
            ->postJson($this->groupUrl("/threads/{$thread->id}/messages"), [
                'body' => 'Jazakum Allahu khayran — we will practise at home.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Jazakum Allahu khayran — we will practise at home.')
            ->assertJsonPath('data.author_is_parent', true);

        $message = GroupMessage::withoutMasjidScope()
            ->where('group_thread_id', $thread->id)
            ->latest('id')
            ->first();

        // The author is the AUTHENTICATED contact, and no staff account is
        // implicated in something a parent said.
        $this->assertSame($this->parentA->id, (int) $message->author_contact_id);
        $this->assertNull($message->author_user_id);
    }

    #[Test]
    public function the_author_comes_from_the_token_and_never_from_the_payload(): void
    {
        $thread = $this->seedParticipantThread($this->childAMembership, 'Amina');

        $this->as($this->parentA)
            ->postJson($this->groupUrl("/threads/{$thread->id}/messages"), [
                'body' => 'Hello',
                // A client claiming to be the other family, or a staff member.
                'author_contact_id' => $this->parentB->id,
                'author_user_id' => 1,
            ])
            ->assertCreated();

        $message = GroupMessage::withoutMasjidScope()
            ->where('group_thread_id', $thread->id)
            ->latest('id')
            ->first();

        $this->assertSame($this->parentA->id, (int) $message->author_contact_id,
            'a client claimed authorship of another person\'s message');
        $this->assertNull($message->author_user_id);
    }

    #[Test]
    public function a_parent_cannot_reply_in_another_familys_thread(): void
    {
        // The sharpest line in the product: this is where a teacher and a
        // guardian discuss a safeguarding concern about a different child.
        $thread = $this->seedParticipantThread($this->childBMembership, 'Bilal');

        $this->as($this->parentA)
            ->postJson($this->groupUrl("/threads/{$thread->id}/messages"), ['body' => 'Who is this about?'])
            ->assertForbidden();

        $this->assertSame(1, GroupMessage::withoutMasjidScope()->where('group_thread_id', $thread->id)->count());
    }

    #[Test]
    public function replying_is_refused_on_a_thread_the_school_has_closed(): void
    {
        $thread = $this->seedParticipantThread($this->childAMembership, 'Amina');
        $thread->forceFill(['closed_at' => now()])->save();

        $this->as($this->parentA)
            ->postJson($this->groupUrl("/threads/{$thread->id}/messages"), ['body' => 'One more thing'])
            ->assertStatus(422);
    }

    /**
     * A DELIBERATE REVERSAL, recorded rather than quietly deleted.
     *
     * This test used to assert 405 — no route at all — on the reasoning that "a
     * parent opening a thread about their own child would route around the
     * teacher who decides what is discussed and where." The school's owner
     * decided otherwise on 2026-09-08, and the reason the original position lost
     * is worth writing down: a parent who cannot open a conversation does not
     * stay silent, they phone the office or text a personal number, and the
     * exchange leaves the system that exists to hold it.
     *
     * What replaced it is NOT the staff verb. Three narrowings survive from the
     * original concern, and each is asserted below:
     *   - a parent may never reach the whole class;
     *   - a parent may never open a thread about someone else's child;
     *   - and it is throttled, where replying is not.
     */
    #[Test]
    public function a_parent_may_start_a_conversation_only_about_their_own_child(): void
    {
        $this->as($this->parentA)
            ->postJson($this->groupUrl('/threads'), [
                'subject' => 'A new topic',
                'about_membership_id' => $this->childAMembership->id,
                'body' => 'Something I wanted to raise.',
            ])
            ->assertCreated();

        // Another family's child is refused outright.
        $this->as($this->parentA)
            ->postJson($this->groupUrl('/threads'), [
                'subject' => 'About someone else',
                'about_membership_id' => $this->childBMembership->id,
                'body' => 'Not my child.',
            ])
            ->assertForbidden();

        $this->assertSame(1, GroupThread::withoutGlobalScopes()->count());
    }

    /**
     * A parent stays signed in far longer than a staff member, and the two are
     * genuinely independent.
     *
     * This is the whole point of the `sanctum-family` driver. If
     * `auth.guards.family.driver` is ever set back to `sanctum`, a parent
     * silently drops to the 8-hour staff window and starts re-authenticating on
     * every visit — a regression nobody would notice for weeks, because it looks
     * like a parent simply being logged out.
     */
    #[Test]
    public function a_parent_session_outlives_the_staff_window(): void
    {
        $token = $this->parentA->createFamilyToken();

        // Aged past the global 480-minute expiration that still governs staff.
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['created_at' => now()->subHours(24)]);

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson($this->groupUrl('/threads'))
            ->assertOk();

        // And staff were NOT moved to get here.
        $this->assertSame(
            480,
            (int) config('sanctum.expiration'),
            'the global expiration governs STAFF and must stay at 8 hours'
        );
        $this->assertSame('sanctum-family', config('auth.guards.family.driver'));
    }

    /** The audience is decided server-side; a scope in the payload is ignored. */
    #[Test]
    public function a_parent_cannot_reach_the_whole_class_even_by_asking(): void
    {
        $this->as($this->parentA)
            ->postJson($this->groupUrl('/threads'), [
                'subject' => 'Everyone should know',
                'scope' => GroupThread::SCOPE_GROUP,
                'about_membership_id' => $this->childAMembership->id,
                'body' => 'Trying to reach every family.',
            ])
            ->assertCreated();

        $this->assertSame(
            GroupThread::SCOPE_PARTICIPANT,
            GroupThread::withoutGlobalScopes()->latest('id')->first()->scope
        );
    }

    #[Test]
    public function an_empty_reply_is_refused(): void
    {
        $thread = $this->seedParticipantThread($this->childAMembership, 'Amina');

        $this->as($this->parentA)
            ->postJson($this->groupUrl("/threads/{$thread->id}/messages"), ['body' => '   '])
            ->assertStatus(422);
    }

    #[Test]
    public function reading_a_thread_bookmarks_it_for_the_parent_and_not_for_any_staff_account(): void
    {
        $thread = $this->seedParticipantThread($this->childAMembership, 'Amina');

        $this->as($this->parentA)->getJson($this->groupUrl("/threads/{$thread->id}"))->assertOk();

        $read = DB::table('group_thread_reads')->where('group_thread_id', $thread->id)->get();

        $this->assertCount(1, $read);
        $this->assertSame($this->parentA->id, (int) $read->first()->contact_id);
        $this->assertNull($read->first()->user_id,
            'a parent\'s read was written into the staff column');
    }

    #[Test]
    public function two_parents_reading_one_thread_do_not_collide(): void
    {
        // The (thread, user) unique key used to make this impossible, which is
        // why user_id could not simply be reused for parents.
        $thread = GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'scope' => GroupThread::SCOPE_GROUP,
            'subject' => 'Field trip',
        ]);
        $thread->messages()->create(['masjid_id' => $this->masjid->id, 'body' => 'Slips due Friday']);

        $this->consent($this->parentA, GroupMembership::CONSENT_FEED);
        $this->consent($this->parentB, GroupMembership::CONSENT_FEED);

        $this->as($this->parentA)->getJson($this->groupUrl("/threads/{$thread->id}"))->assertOk();
        $this->as($this->parentB)->getJson($this->groupUrl("/threads/{$thread->id}"))->assertOk();

        $this->assertSame(2, DB::table('group_thread_reads')->where('group_thread_id', $thread->id)->count());
    }

    #[Test]
    public function a_message_cannot_have_two_authors(): void
    {
        $thread = $this->seedParticipantThread($this->childAMembership, 'Amina');

        $this->expectException(\LogicException::class);

        $thread->messages()->create([
            'masjid_id' => $this->masjid->id,
            'author_user_id' => 1,
            'author_contact_id' => $this->parentA->id,
            'body' => 'Who wrote this?',
        ]);
    }

    // --------------------------------- 0c. child mode, and its boundary

    #[Test]
    public function a_parent_can_hand_the_device_to_their_own_child(): void
    {
        $response = $this->as($this->parentA)
            ->postJson($this->groupUrl("/members/{$this->childAMembership->id}/student-session"))
            ->assertCreated();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame($this->childAMembership->id, $response->json('data.membership_id'));
    }

    #[Test]
    public function a_parent_cannot_hand_the_device_to_someone_elses_child(): void
    {
        $this->as($this->parentA)
            ->postJson($this->groupUrl("/members/{$this->childBMembership->id}/student-session"))
            ->assertForbidden();
    }

    #[Test]
    public function a_child_in_student_mode_can_choose_their_own_avatar(): void
    {
        $token = $this->handoffToken($this->parentA, $this->childAMembership->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson($this->studentUrl($this->childAMembership->id, '/me'))
            ->assertOk()
            ->assertJsonPath('data.student.membership_id', $this->childAMembership->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson($this->studentUrl($this->childAMembership->id, '/avatar'), [
                'character' => 'ameera', 'tone' => 'tone1', 'color' => 'blue',
            ])
            ->assertOk();

        $this->assertSame('ameera', $this->childA->fresh()->avatar_character);
    }

    #[Test]
    public function a_student_token_cannot_open_the_parents_portal(): void
    {
        // THE boundary. With the phone in the child's hands, the class feed, the
        // teacher threads and the sibling's records are refused by the SERVER —
        // not hidden by a screen the child is asked not to leave.
        $token = $this->handoffToken($this->parentA, $this->childAMembership->id);
        $thread = $this->seedParticipantThread($this->childAMembership, 'About Amina');

        foreach ([
            $this->url('/me'),
            $this->url('/groups'),
            $this->groupUrl(),
            $this->groupUrl('/posts'),
            $this->groupUrl('/threads'),
            $this->groupUrl("/threads/{$thread->id}"),
            $this->groupUrl("/members/{$this->childAMembership->id}/awards"),
        ] as $forbidden) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson($forbidden)
                ->assertForbidden();
        }
    }

    #[Test]
    public function a_student_token_minted_for_one_sibling_cannot_address_the_other(): void
    {
        // Two children on one phone are two sessions, because the ability names
        // a membership and the middleware compares it to the URL.
        $token = $this->handoffToken($this->parentA, $this->childAMembership->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson($this->studentUrl($this->childBMembership->id, '/avatar'), [
                'character' => 'ameer', 'tone' => 'tone1', 'color' => 'blue',
            ])
            ->assertForbidden();

        $this->assertNull($this->childB->fresh()->avatar_character);
    }

    #[Test]
    public function a_parent_token_cannot_use_the_student_screen(): void
    {
        // The student routes must not become a second, weaker way for a parent
        // to act — every ability check runs in both directions.
        $this->as($this->parentA)
            ->getJson($this->studentUrl($this->childAMembership->id, '/me'))
            ->assertForbidden();
    }

    // ------------------------------- 0d. a teacher never destroys the child's pick

    #[Test]
    public function a_staff_override_hides_the_childs_avatar_without_erasing_it(): void
    {
        $this->childA->forceFill([
            'avatar_character' => 'ameera', 'avatar_tone' => 'tone1', 'avatar_color' => 'pink',
        ])->save();

        // A teacher sets something else.
        $this->childA->forceFill([
            'staff_avatar_character' => 'ameera', 'staff_avatar_tone' => 'tone4', 'staff_avatar_color' => 'white',
        ])->save();

        $avatar = $this->childA->fresh()->avatar;

        $this->assertSame('staff', $avatar['source']);
        $this->assertSame('tone4', $avatar['tone']);
        // The child's own is still there, and still says pink.
        $this->assertSame('pink', $avatar['student_choice']['color']);

        // Dropping the override brings it straight back — it was never touched.
        $this->childA->forceFill([
            'staff_avatar_character' => null, 'staff_avatar_tone' => null, 'staff_avatar_color' => null,
        ])->save();

        $restored = $this->childA->fresh()->avatar;
        $this->assertSame('student', $restored['source']);
        $this->assertSame('pink', $restored['color']);
    }

    // ------------------------------------- 0a. the letter tracker, read only

    #[Test]
    public function a_parent_watches_their_own_childs_letters(): void
    {
        $this->group->forceFill(['arabic_stage' => 'short_vowels'])->save();

        $response = $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/letters"))
            ->assertOk();

        $response->assertJsonCount(28, 'data.letters');
        $response->assertJsonPath('data.stage.id', 'short_vowels');
        $this->assertSame(28 * 4, $response->json('data.totals.total'));
    }

    #[Test]
    public function a_parent_cannot_watch_another_familys_child(): void
    {
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/letters"))
            ->assertForbidden();
    }

    #[Test]
    public function a_parent_cannot_mark_a_drill(): void
    {
        // Marking is the teacher's, like behaviour points and hifz. A parent who
        // could tick "mastered" would make the record say something the
        // classroom never observed. There is simply no route.
        $this->as($this->parentA)
            ->putJson($this->groupUrl("/members/{$this->childAMembership->id}/letters"), [
                'drill_id' => 'ba', 'status' => 'mastered',
            ])
            ->assertStatus(405);
    }

    // ------------------------------------------- 0b. a child's own avatar

    #[Test]
    public function a_parent_can_choose_an_avatar_for_their_own_child(): void
    {
        $this->as($this->parentA)
            ->putJson($this->groupUrl("/members/{$this->childAMembership->id}/avatar"), [
                'character' => 'ameera', 'tone' => 'tone3', 'color' => 'pink',
            ])
            ->assertOk()
            ->assertJsonPath('data.contact.avatar.character', 'ameera')
            ->assertJsonPath('data.contact.avatar.tone', 'tone3');

        $this->assertSame('ameera', $this->childA->fresh()->avatar_character);
        $this->assertStringContainsString(
            'ameera_tone3_pink.webp',
            $this->childA->fresh()->avatarUrl()
        );
    }

    #[Test]
    public function a_parent_cannot_dress_another_familys_child(): void
    {
        // The ward edge is the authorisation, so a classmate is refused even
        // though both children sit in the same group.
        $this->as($this->parentA)
            ->putJson($this->groupUrl("/members/{$this->childBMembership->id}/avatar"), [
                'character' => 'ameer', 'tone' => 'tone1', 'color' => 'blue',
            ])
            ->assertForbidden();

        $this->assertNull($this->childB->fresh()->avatar_character);
    }

    #[Test]
    public function two_thirds_of_an_avatar_is_refused(): void
    {
        // A character with no tone names no drawing; storing it would leave the
        // child with a permanently blank face and nothing reporting it wrong.
        $this->as($this->parentA)
            ->putJson($this->groupUrl("/members/{$this->childAMembership->id}/avatar"), [
                'character' => 'ameera', 'tone' => null, 'color' => null,
            ])
            ->assertStatus(422);

        $this->assertNull($this->childA->fresh()->avatar_character);
    }

    #[Test]
    public function clearing_all_three_returns_the_child_to_initials(): void
    {
        $this->childA->forceFill([
            'avatar_character' => 'ameera', 'avatar_tone' => 'tone1', 'avatar_color' => 'black',
        ])->save();

        $this->as($this->parentA)
            ->putJson($this->groupUrl("/members/{$this->childAMembership->id}/avatar"), [
                'character' => null, 'tone' => null, 'color' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.contact.avatar', null);

        $this->assertNull($this->childA->fresh()->avatarUrl());
    }

    #[Test]
    public function a_childs_avatar_reaches_the_parent_on_the_group_payload(): void
    {
        // The whole point: a face wherever the child appears.
        $this->childA->forceFill([
            'avatar_character' => 'ameera', 'avatar_tone' => 'tone2', 'avatar_color' => 'green',
        ])->save();

        $this->as($this->parentA)->getJson($this->url('/groups'))
            ->assertOk()
            ->assertJsonPath('data.0.children.0.contact.avatar.color', 'green');
    }

    // ------------------------------------------------- 1. the parent sees THEIR group

    #[Test]
    public function a_parent_sees_their_own_group_and_their_own_child_in_it(): void
    {
        $response = $this->as($this->parentA)->getJson($this->url('/groups'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->group->id);
        $response->assertJsonPath('data.0.name', 'Grade 3');
        $response->assertJsonPath('data.0.is_guardian', true);

        // Their own ward, addressed by the ward's PARTICIPANT membership id —
        // the id every per-child endpoint takes.
        $response->assertJsonCount(1, 'data.0.children');
        $response->assertJsonPath('data.0.children.0.membership_id', $this->childAMembership->id);
        $response->assertJsonPath('data.0.children.0.contact.first_name', 'Amina');

        // THE ROSTER IS NOT DISCLOSED. Not the other family's child, not the
        // teacher, not a member count. A class list is a list of children.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Bilal', $body);
        $this->assertStringNotContainsString((string) $this->childB->last_name, $body);

        // Vertical-aware labelling, not a hardcoded "Classroom".
        $response->assertJsonPath('meta.group_label', $this->masjid->term('groups'));
    }

    #[Test]
    public function a_parent_is_refused_a_group_they_are_not_in(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->as($this->parentA)
            ->getJson($this->url("/groups/{$other->id}"))
            ->assertStatus(403);

        // And it never appeared in the listing to begin with.
        $this->as($this->parentA)
            ->getJson($this->url('/groups'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ------------------------- 2. THE CENTREPIECE: two families, one classroom

    #[Test]
    public function each_parent_sees_only_their_own_child_across_every_record_surface(): void
    {
        $awardA = $this->seedAward($this->childAMembership, 'Helped a classmate');
        $awardB = $this->seedAward($this->childBMembership, 'Excellent tajwid');

        $this->seedHifz($this->childAMembership, 10);
        $this->seedHifz($this->childBMembership, 25);

        $threadA = $this->seedParticipantThread($this->childAMembership, 'About Amina');
        $threadB = $this->seedParticipantThread($this->childBMembership, 'About Bilal');

        // ---------------- parent A ----------------

        $awards = $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/awards"))
            ->assertOk();
        $awards->assertJsonCount(1, 'data.data');
        $awards->assertJsonPath('data.data.0.skill_label', 'Helped a classmate');

        // The other family's child, by membership id: an honest 403.
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/awards"))
            ->assertStatus(403);
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/awards/summary"))
            ->assertStatus(403);
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/hifz"))
            ->assertStatus(403);
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/hifz/progress"))
            ->assertStatus(403);

        // Threads: A's own participant thread, and NOT B's — neither in the
        // listing nor by direct id.
        $threads = $this->as($this->parentA)
            ->getJson($this->groupUrl('/threads'))
            ->assertOk();
        $threads->assertJsonCount(1, 'data.data');
        $threads->assertJsonPath('data.data.0.id', $threadA->id);
        $this->assertStringNotContainsString('About Bilal', $threads->getContent());

        $this->as($this->parentA)
            ->getJson($this->groupUrl("/threads/{$threadB->id}"))
            ->assertStatus(403);

        // ---------------- parent B, the mirror ----------------

        $awardsB = $this->as($this->parentB)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/awards"))
            ->assertOk();
        $awardsB->assertJsonCount(1, 'data.data');
        $awardsB->assertJsonPath('data.data.0.skill_label', 'Excellent tajwid');

        $this->as($this->parentB)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/awards"))
            ->assertStatus(403);

        $threadsB = $this->as($this->parentB)
            ->getJson($this->groupUrl('/threads'))
            ->assertOk();
        $threadsB->assertJsonCount(1, 'data.data');
        $threadsB->assertJsonPath('data.data.0.id', $threadB->id);

        // ---------------- and the same at QUERY level ----------------
        //
        // Invariant 6's second half. The 403s above are the honest answer; THIS
        // is what makes them safe. If the endpoint check were the only barrier,
        // a paginator total, a `count()` or the summary's `SUM(points)` would
        // still be computed over the whole class — a parent could learn how many
        // times another family's child was marked without ever reading a row.

        $audience = $this->audience();

        $readableAwards = $audience->readableAwardsQuery($this->parentA->fresh(), $this->group);
        $this->assertNotNull($readableAwards);
        $this->assertSame([$awardA->id], $readableAwards->pluck('id')->all());
        $this->assertNotContains($awardB->id, $readableAwards->pluck('id')->all());

        $readableHifz = $audience->readableHifzQuery($this->parentA->fresh(), $this->group);
        $this->assertNotNull($readableHifz);
        $this->assertSame(1, $readableHifz->count());
        $this->assertSame(
            [$this->childAMembership->id],
            $readableHifz->pluck('group_membership_id')->unique()->all()
        );

        $readableThreads = $audience->readableThreadsQuery($this->parentA->fresh(), $this->group);
        $this->assertNotNull($readableThreads);
        $this->assertSame([$threadA->id], $readableThreads->pluck('id')->all());
    }

    #[Test]
    public function a_parents_own_childs_summary_and_progress_are_computed_over_their_child_only(): void
    {
        $this->seedAward($this->childAMembership, 'Kindness');
        $this->seedAward($this->childAMembership, 'Kindness');
        $this->seedAward($this->childBMembership, 'Kindness');

        $summary = $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/awards/summary"))
            ->assertOk();

        // 2 awards x 3 points. THREE awards exist under that label in this
        // classroom; the third belongs to another family and must be
        // arithmetically absent, not merely unlisted.
        $summary->assertJsonPath('data.totals.awards', 2);
        $summary->assertJsonPath('data.totals.points', 6);

        // Ḥifẓ: position, never a percentage (.claude/rules/groups.md).
        $this->seedHifz($this->childAMembership, 10);
        $this->seedHifz($this->childBMembership, 40);

        $progress = $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/hifz/progress"))
            ->assertOk();

        $progress->assertJsonPath('data.current_position.surah', 78);
        $progress->assertJsonPath('data.current_position.ayah', 10);
        $progress->assertJsonMissingPath('data.memorized.percentage');
        // The other child memorised further; that must not move this number.
        $progress->assertJsonPath('data.memorized.ayahs', 10);
    }

    // ------------------------------------------------------- 3. consent (inv. 7)

    #[Test]
    public function a_guardian_with_no_consent_record_gets_no_feed_but_keeps_their_own_childs_records(): void
    {
        $post = $this->seedPostWithImage();
        $thread = $this->seedParticipantThread($this->childAMembership, 'About Amina');
        $this->seedAward($this->childAMembership, 'Kindness');
        $this->seedHifz($this->childAMembership, 10);

        // No consent has been recorded anywhere — the state EVERY guardian edge
        // starts in, because absence of a record means no consent.

        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertStatus(403);
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/posts/{$post->id}"))
            ->assertStatus(403);

        $this->as($this->parentA)
            ->getJson($this->url('/groups'))
            ->assertOk()
            ->assertJsonPath('data.0.may_receive_feed', false)
            ->assertJsonPath('data.0.may_receive_media', false);

        // AND YET the records most obviously theirs still open. Requiring feed
        // consent here would lock a parent out of their own child's record and
        // out of talking to the teacher about them, which inverts what consent
        // protects.
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/awards"))
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/hifz"))
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->as($this->parentA)
            ->getJson($this->groupUrl("/threads/{$thread->id}"))
            ->assertOk();
    }

    #[Test]
    public function feed_consent_opens_the_words_and_withholds_the_pictures(): void
    {
        $post = $this->seedPostWithImage();
        $this->consent($this->parentA, GroupMembership::CONSENT_FEED);

        $response = $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertOk();

        $response->assertJsonPath('data.data.0.title', 'Trip photos');

        // The attachment list is OMITTED, not merely un-downloadable: a
        // filename and a file size are themselves a disclosure about a child.
        $response->assertJsonCount(0, 'data.data.0.attachments');
        $response->assertJsonPath('data.data.0.media_withheld', true);
        $response->assertJsonPath('meta.may_receive_media', false);
        $this->assertStringNotContainsString('class.jpg', $response->getContent());

        $attachment = $post->attachments()->firstOrFail();

        $this->as($this->parentA)
            ->get($this->groupUrl("/posts/{$post->id}/attachments/{$attachment->id}"))
            ->assertStatus(403);
    }

    #[Test]
    public function media_consent_opens_the_bytes_and_never_a_signed_url(): void
    {
        $post = $this->seedPostWithImage();
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);

        $response = $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertOk();

        // `media` covers `feed` — the scopes are a hierarchy.
        $response->assertJsonPath('data.data.0.media_withheld', false);
        $response->assertJsonCount(1, 'data.data.0.attachments');

        $path = $response->json('data.data.0.attachments.0.download_path');

        // The link is an AUTHENTICATED FAMILY endpoint, not a signed URL and not
        // the admin path (which would 401 for a parent). Design §8: consent
        // revoked mid-session is only safe because every byte re-resolves the
        // ownership chain, which a signed or CDN-cached URL would bypass
        // forever.
        $this->assertSame(
            "/api/family/masjids/{$this->masjid->id}/groups/{$this->group->id}"
            . "/posts/{$post->id}/attachments/{$response->json('data.data.0.attachments.0.id')}",
            $path
        );
        $this->assertStringNotContainsString('signature=', $path);
        $this->assertStringNotContainsString('expires=', $path);

        $download = $this->as($this->parentA)->get($path)->assertOk();

        // Directive by directive, not as a literal string: Symfony normalises
        // and re-orders Cache-Control, so an exact match would be asserting the
        // framework's formatting rather than the policy. The policy is what
        // matters — no proxy, no CDN and no shared cache may hold one family's
        // photograph and hand it to the next person who asks for the URL.
        $cacheControl = (string) $download->headers->get('Cache-Control');

        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    #[Test]
    public function withdrawing_consent_darkens_the_feed_on_the_very_next_request(): void
    {
        $post = $this->seedPostWithImage();
        $attachment = $post->attachments()->firstOrFail();
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);

        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertOk();
        $this->as($this->parentA)
            ->get($this->groupUrl("/posts/{$post->id}/attachments/{$attachment->id}"))
            ->assertOk();

        // Withdrawal returns the row to the never-consented state.
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->group->id)
            ->where('contact_id', $this->parentA->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => null, 'consent_scope' => null]);

        // The SAME token — withdrawal has to reach a credential already in a
        // phone, on the next request, not whenever it happens to expire.
        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertStatus(403);
        $this->as($this->parentA)
            ->get($this->groupUrl("/posts/{$post->id}/attachments/{$attachment->id}"))
            ->assertStatus(403);
    }

    // --------------------------------- 4. the roster IS the revocation mechanism

    #[Test]
    public function removing_the_guardian_edge_empties_the_parents_whole_surface(): void
    {
        $this->seedAward($this->childAMembership, 'Kindness');
        $this->seedHifz($this->childAMembership, 10);
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);

        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertOk();

        // A custody order is executed by removing the EDGE, not by revoking a
        // household login — which is exactly why login_email is per-contact.
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->group->id)
            ->where('contact_id', $this->parentA->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->get()
            ->each
            ->delete();

        $this->as($this->parentA)->getJson($this->url('/groups'))->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->parentA)->getJson($this->groupUrl())->assertStatus(403);
        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertStatus(403);
        $this->as($this->parentA)->getJson($this->groupUrl('/threads'))->assertStatus(403);
        $this->as($this->parentA)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/awards"))
            ->assertStatus(403);

        // The token itself is still perfectly valid — this is the roster doing
        // the work, not a credential expiring.
        $this->as($this->parentA)->getJson($this->url('/me'))->assertOk();
    }

    #[Test]
    public function removing_the_child_from_the_roster_takes_the_guardian_edge_with_them(): void
    {
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);
        $this->seedPostWithImage();

        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertOk();

        // GroupMembership::booted()'s deleting hook removes the guardian edges
        // pointing AT a departing participant, so the parent's surface empties
        // on the next request — the correct least-disclosure outcome.
        GroupMembership::withoutMasjidScope()->findOrFail($this->childAMembership->id)->delete();

        $this->as($this->parentA)->getJson($this->groupUrl('/posts'))->assertStatus(403);
        $this->as($this->parentA)->getJson($this->url('/groups'))->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_revoked_login_closes_every_read_endpoint_not_only_me(): void
    {
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);
        $this->seedAward($this->childAMembership, 'Kindness');

        $token = $this->parentA->createFamilyToken()->plainTextToken;

        $endpoints = [
            $this->url('/me'),
            $this->url('/groups'),
            $this->groupUrl(),
            $this->groupUrl('/posts'),
            $this->groupUrl('/threads'),
            $this->groupUrl("/members/{$this->childAMembership->id}/awards"),
            $this->groupUrl("/members/{$this->childAMembership->id}/hifz"),
        ];

        foreach ($endpoints as $endpoint) {
            Auth::forgetGuards();
            app(TenantContext::class)->forgetTenant();

            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson($endpoint)->assertOk();
        }

        $this->parentA->forceFill(['login_revoked_at' => now()])->save();

        // `family.active` re-reads liveness on EVERY request, which is why this
        // holds for endpoints that never call GroupAudience as well as for those
        // that do. Checking revocation only inside the disclosure layer would
        // leave /me open to a revoked credential.
        foreach ($endpoints as $endpoint) {
            Auth::forgetGuards();
            app(TenantContext::class)->forgetTenant();

            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson($endpoint)
                ->assertStatus(401)
                ->assertJsonPath('message', 'Unauthorized.');
        }
    }

    // ---------------------------------------- 5. group-wide threads are consented

    #[Test]
    public function a_group_wide_thread_is_consent_gated_and_a_participant_thread_is_not(): void
    {
        $wide = GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'scope' => GroupThread::SCOPE_GROUP,
            'subject' => 'Class announcement',
        ]);
        $mine = $this->seedParticipantThread($this->childAMembership, 'About Amina');

        // No consent: the announcement thread is a BROADCAST and stays closed;
        // the conversation about their own child does not.
        $threads = $this->as($this->parentA)->getJson($this->groupUrl('/threads'))->assertOk();
        $this->assertSame([$mine->id], collect($threads->json('data.data'))->pluck('id')->all());
        $this->as($this->parentA)->getJson($this->groupUrl("/threads/{$wide->id}"))->assertStatus(403);

        $this->consent($this->parentA, GroupMembership::CONSENT_FEED);

        $threads = $this->as($this->parentA)->getJson($this->groupUrl('/threads'))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$mine->id, $wide->id],
            collect($threads->json('data.data'))->pluck('id')->all()
        );
        $this->as($this->parentA)->getJson($this->groupUrl("/threads/{$wide->id}"))->assertOk();
    }

    // ------------------------------------------------ 6. the realm stays read-only

    // ------------------------------------------------------------------
    // REPORT CARDS
    //
    // These endpoints shipped with the teacher's side of the feature and had no
    // parent-facing coverage at all: every test in ReportCardTest authenticates
    // as a staff User against /api/teacher, and the two sweep tests above
    // enumerate their surfaces by hand rather than from the route table, so a
    // regression here would have passed the suite green.
    // ------------------------------------------------------------------

    #[Test]
    public function a_parent_reads_their_own_childs_published_report_card(): void
    {
        $card = $this->makeCard($this->childAMembership, published: true);

        $index = $this->as($this->parentA)->getJson($this->reportCardsUrl($this->childAMembership));

        $index->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $card->id);
        $index->assertJsonPath('data.0.type_label', 'Report Card');
        $index->assertJsonPath('data.0.period_label', 'Quarter 2, 2026-2027');

        $show = $this->as($this->parentA)->getJson($this->reportCardsUrl($this->childAMembership, $card->id));

        $show->assertOk();
        $show->assertJsonPath('data.grade_label', '3rd');
        $show->assertJsonPath('data.teacher_comment', 'A good quarter.');
        $show->assertJsonPath('data.subjects.0.subject', 'Qur\'an');
        $show->assertJsonPath('data.subjects.0.criteria.0.criterion', 'Tajweed');
        $show->assertJsonPath('data.subjects.0.criteria.0.level', 3);
        $show->assertJsonPath('data.subjects.0.criteria.0.comment', 'Clear makhraj.');

        // Beside the subjects, never inside one.
        $show->assertJsonPath('data.learning_behaviours.0.criterion', 'Works well with others');
        $show->assertJsonPath('data.learning_behaviours.0.level', 4);
    }

    /**
     * The claim the controller is built around: a draft is a 404 in exactly the
     * way a nonexistent card is.
     *
     * `published()` is applied as a SCOPE inside the query rather than as a
     * check after the fetch, so a parent cannot learn that a report about their
     * own child exists but is being withheld while a teacher is still writing
     * it. If someone ever "simplifies" that into an `if ($card->isPublished())`
     * after a findOrFail, this is the test that says no.
     */
    #[Test]
    public function a_draft_report_card_does_not_exist_as_far_as_a_parent_is_concerned(): void
    {
        $draft = $this->makeCard($this->childAMembership, published: false);

        $this->as($this->parentA)
            ->getJson($this->reportCardsUrl($this->childAMembership))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->as($this->parentA)
            ->getJson($this->reportCardsUrl($this->childAMembership, $draft->id))
            ->assertNotFound();
    }

    #[Test]
    public function a_parent_cannot_open_another_familys_childs_report_card(): void
    {
        $bilals = $this->makeCard($this->childBMembership, published: true);

        $this->as($this->parentA)
            ->getJson($this->reportCardsUrl($this->childBMembership))
            ->assertForbidden();

        $this->as($this->parentA)
            ->getJson($this->reportCardsUrl($this->childBMembership, $bilals->id))
            ->assertForbidden();
    }

    /**
     * A report card a parent cannot decode has not communicated anything, so the
     * key travels WITH the document — as a sibling of `data`, which is the shape
     * both the teacher screen and the parent screen read it from.
     */
    #[Test]
    public function the_performance_level_key_travels_with_the_card(): void
    {
        $card = $this->makeCard($this->childAMembership, published: true);

        $show = $this->as($this->parentA)->getJson($this->reportCardsUrl($this->childAMembership, $card->id));

        $show->assertOk();
        $show->assertJsonCount(4, 'performance_levels');
        $show->assertJsonPath('performance_levels.0.level', 4);
        $show->assertJsonPath('performance_levels.0.label', 'Exceeds Expectations');
        $show->assertJsonPath('performance_levels.3.level', 1);

        // A sibling of `data`, not a member of it. A screen reading
        // data.performance_levels gets undefined and renders no legend at all.
        $this->assertArrayNotHasKey('performance_levels', $show->json('data'));
    }

    /**
     * NULL is "not assessed" — true of a child who joined in week eight — and
     * must survive the trip to the family as a null, never as a zero.
     */
    #[Test]
    public function an_unassessed_criterion_reaches_the_family_as_null_and_never_as_a_zero(): void
    {
        $card = $this->makeCard($this->childAMembership, published: true);

        $show = $this->as($this->parentA)->getJson($this->reportCardsUrl($this->childAMembership, $card->id));

        $show->assertOk();
        $this->assertNull($show->json('data.subjects.0.criteria.1.level'));
        $this->assertNull($show->json('data.subjects.0.criteria.1.level_label'));
    }

    /** The document a family keeps must not rewrite its own attendance later. */
    #[Test]
    public function the_family_reads_the_attendance_frozen_at_publication(): void
    {
        $card = $this->makeCard($this->childAMembership, published: true);

        $show = $this->as($this->parentA)->getJson($this->reportCardsUrl($this->childAMembership, $card->id));

        $show->assertJsonPath('data.attendance.present', 30);
        $show->assertJsonPath('data.attendance.absent', 2);
        $show->assertJsonPath('data.attendance.late', 3);
    }

    #[Test]
    public function report_cards_are_listed_newest_first(): void
    {
        $first = $this->makeCard($this->childAMembership, published: true, term: 1);
        $second = $this->makeCard($this->childAMembership, published: true, term: 2);

        $index = $this->as($this->parentA)->getJson($this->reportCardsUrl($this->childAMembership));

        $index->assertOk();
        $index->assertJsonPath('data.0.id', $second->id);
        $index->assertJsonPath('data.1.id', $first->id);
    }

    /**
     * The printable copy goes through the SAME two gates as the readable one.
     *
     * A separate, looser lookup for the download is exactly how a withheld
     * document escapes — so these three cases mirror the ones above rather than
     * trusting that the route "obviously" inherits them.
     */
    #[Test]
    public function a_parent_can_download_their_own_childs_report_card(): void
    {
        $card = $this->makeCard($this->childAMembership, published: true);

        $response = $this->as($this->parentA)
            ->get($this->reportCardsUrl($this->childAMembership, $card->id) . '/pdf');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    #[Test]
    public function a_draft_report_card_cannot_be_downloaded_either(): void
    {
        $draft = $this->makeCard($this->childAMembership, published: false);

        $this->as($this->parentA)
            ->get($this->reportCardsUrl($this->childAMembership, $draft->id) . '/pdf')
            ->assertNotFound();
    }

    #[Test]
    public function a_parent_cannot_download_another_familys_childs_report_card(): void
    {
        $bilals = $this->makeCard($this->childBMembership, published: true);

        $this->as($this->parentA)
            ->get($this->reportCardsUrl($this->childBMembership, $bilals->id) . '/pdf')
            ->assertForbidden();
    }

    private function reportCardsUrl(GroupMembership $membership, ?int $cardId = null): string
    {
        return $this->groupUrl(
            "/members/{$membership->id}/report-cards" . ($cardId !== null ? "/{$cardId}" : '')
        );
    }

    /**
     * A card with one marked criterion, one deliberately unmarked one, and one
     * learning behaviour.
     *
     * `published_at` is written with forceFill because it is deliberately absent
     * from ReportCard::$fillable — publication is what makes a document visible
     * to a family and must never happen as a side effect of saving a draft.
     */
    private function makeCard(GroupMembership $membership, bool $published, int $term = 2): ReportCard
    {
        $card = ReportCard::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'group_membership_id' => $membership->id,
            'type' => ReportCard::TYPE_REPORT_CARD,
            'school_year' => '2026-2027',
            'term' => $term,
            'grade_label' => '3rd',
            'teacher_comment' => 'A good quarter.',
            'days_present' => 30,
            'days_absent' => 2,
            'days_late' => 3,
        ]);

        $rows = [
            [ReportCardMark::KIND_ACADEMIC, 'Qur\'an', 'Tajweed', 3, 'Clear makhraj.', 0],
            [ReportCardMark::KIND_ACADEMIC, 'Qur\'an', 'Memorisation', null, null, 1],
            [ReportCardMark::KIND_BEHAVIOUR, 'Learning Behaviours', 'Works well with others', 4, null, 2],
        ];

        foreach ($rows as [$kind, $subject, $criterion, $level, $comment, $position]) {
            ReportCardMark::create([
                'masjid_id' => $this->masjid->id,
                'report_card_id' => $card->id,
                'kind' => $kind,
                'subject' => $subject,
                'criterion' => $criterion,
                'level' => $level,
                'comment' => $comment,
                'position' => $position,
            ]);
        }

        if ($published) {
            $card->forceFill(['published_at' => now()])->save();
        }

        return $card;
    }

    #[Test]
    public function the_family_realm_writes_exactly_nine_things(): void
    {
        // This used to assert the realm accepted NO write verb at all, because
        // T-015f (parents replying) was deliberately unbuilt. T-015f now exists,
        // so the guarantee is restated rather than dropped: the realm's writes
        // are COUNTED, and adding an eighth has to be a deliberate edit here.
        //
        // The seventh, added deliberately, is a parent OPENING a conversation.
        // Until it existed neither a parent nor a teacher could start one — only
        // the office could, from the admin console — so "message your teacher"
        // was a thing a family had to phone the school to arrange. It is
        // narrower than the staff verb: the scope is FORCED to participant in
        // the controller and never read from the payload, so no request can
        // reach the whole class; the subject must be the caller's own ward; and
        // it is throttled per contact, which replying deliberately is not.
        //
        // The eighth and ninth (2026-09-08) are a parent setting and removing
        // their OWN password, plus the sign-in door that password opens. They
        // are the only writes in this realm that touch a credential, and the
        // only ones whose subject cannot be named by the request at all: both
        // act on `Auth::user()`, so aiming them at another family is not a
        // request that can be expressed. There is deliberately no admin twin —
        // an office may enable or revoke a family's ACCESS, but may never set,
        // read or reset their password.
        //
        // T-015h (self-service consent withdrawal) is still absent — a parent
        // cannot change a roster, and cannot withdraw consent without the office.
        $writes = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/family')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'HEAD'], true)) {
                    continue;
                }

                $writes[] = $method . ' /' . $route->uri();
            }
        }

        sort($writes);

        $this->assertSame([
            'DELETE /api/family/masjids/{masjid_id}/password',
            'POST /api/family/masjids/{masjid_id}/auth/password',
            'POST /api/family/masjids/{masjid_id}/auth/request-code',
            'POST /api/family/masjids/{masjid_id}/auth/verify-code',
            'POST /api/family/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/student-session',
            'POST /api/family/masjids/{masjid_id}/groups/{group_id}/threads',
            'POST /api/family/masjids/{masjid_id}/groups/{group_id}/threads/{thread_id}/messages',
            'PUT /api/family/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/avatar',
            'PUT /api/family/masjids/{masjid_id}/groups/{group_id}/members/{membership_id}/student/avatar',
            'PUT /api/family/masjids/{masjid_id}/password',
        ], $writes);
    }

    // ------------------------------- 7. the §7 hazard, pinned rather than hidden

    #[Test]
    public function a_login_enabled_participant_reads_nothing_through_their_own_roster_row(): void
    {
        // THIS TEST USED TO DOCUMENT A HAZARD. It now pins the hazard shut.
        //
        // `standingIn()` set `feed = true` outright for any PARTICIPANT — correct
        // for the adult it was written for, and for a STAFF caller it still is
        // (a volunteer on a masjid's team is a `member` row exactly as a child
        // in a classroom is). Applied to a PARENT-PORTAL credential it meant
        // enabling a login on a child's contact row handed that child the entire
        // class feed — every classmate's photograph, with nobody's consent —
        // plus the participant threads about themselves, which are where a
        // teacher and a guardian discuss a safeguarding concern. Measured, and
        // this test asserted every one of those 200s.
        //
        // No code can tell an adult volunteer from a student: the schema has no
        // age or role flag. So the rule is not about WHO holds a credential — a
        // previous round tried that and it refused a parent in the adult ḥalaqa
        // and a teacher who is also a parent, and had to be patched from behind
        // by a hook that an anonymous POST could fire. The rule is about what a
        // FAMILY credential SPEAKS THROUGH: `GroupAudience::membershipsFor()`
        // keeps a `Contact` principal's guardian edges and drops their own
        // participant rows. A child's contact row is nobody's guardian, so it
        // now resolves to no standing anywhere — which is what makes the
        // enable-time rule ("a guardian, over a live ward") sufficient instead of
        // merely necessary.
        $post = $this->seedPostWithImage();
        $threadAboutThem = $this->seedParticipantThread($this->childAMembership, 'About Amina');

        // The child's own contact row, given a login — forceFill, because
        // FamilyAccessService::enable() refuses this contact outright (nobody's
        // guardian) and the point here is that the DISCLOSURE layer refuses it
        // too, independently of the door.
        $this->childA->forceFill([
            'login_email' => 'child-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ])->save();

        $child = $this->childA->refresh();

        // The group they are a MEMBER of does not exist as far as this
        // credential is concerned.
        $this->as($child)->getJson($this->url('/groups'))->assertOk()->assertJsonCount(0, 'data');
        $this->as($child)->getJson($this->groupUrl())->assertStatus(403);

        // No feed…
        $this->as($child)->getJson($this->groupUrl('/posts'))->assertStatus(403);

        // …no bytes…
        $attachment = $post->attachments()->firstOrFail();
        $this->as($child)
            ->get($this->groupUrl("/posts/{$post->id}/attachments/{$attachment->id}"))
            ->assertStatus(403);

        // …and not the participant thread about themselves, which is the one
        // that mattered most.
        $this->as($child)
            ->getJson($this->groupUrl("/threads/{$threadAboutThem->id}"))
            ->assertStatus(403);

        // Their own records, addressed by their own membership id, are refused
        // too — this is deliberately NOT the student-login standing computation,
        // which is its own task. A family credential gets nothing from a
        // participant row rather than the narrow slice a student one should.
        $this->as($child)
            ->getJson($this->groupUrl("/members/{$this->childAMembership->id}/awards"))
            ->assertStatus(403);

        // And the other family's child stays refused, as it always was.
        $this->as($child)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/awards"))
            ->assertStatus(403);
        $this->as($child)
            ->getJson($this->groupUrl("/members/{$this->childBMembership->id}/hifz"))
            ->assertStatus(403);
    }

    #[Test]
    public function unread_is_computed_from_the_parents_own_bookmark(): void
    {
        // This used to assert the OPPOSITE — that no bookmark existed and no
        // `unread` was served — because `group_thread_reads.user_id` was NOT
        // NULL against `users` and there was no column a Contact could be
        // written into. T-015f added one, so the flag can now be computed
        // honestly instead of being withheld.
        $thread = $this->seedParticipantThread($this->childAMembership, 'About Amina');

        // Never opened: there is a message and no bookmark, so it is news.
        $this->as($this->parentA)->getJson($this->groupUrl('/threads'))
            ->assertOk()
            ->assertJsonPath('data.data.0.unread', true)
            ->assertJsonPath('data.data.0.last_read_at', null);

        $this->as($this->parentA)->getJson($this->groupUrl("/threads/{$thread->id}"))->assertOk();

        // Opened: nothing has been said since.
        $this->as($this->parentA)->getJson($this->groupUrl('/threads'))
            ->assertOk()
            ->assertJsonPath('data.data.0.unread', false);

        // The teacher says something new — it is news again. Time has to move:
        // a message written in the same second as the bookmark is not "after"
        // it, and the whole test would otherwise pass or fail on clock luck.
        $this->travel(1)->minutes();
        $thread->messages()->create(['masjid_id' => $this->masjid->id, 'body' => 'One more thing']);

        $this->as($this->parentA)->getJson($this->groupUrl('/threads'))
            ->assertOk()
            ->assertJsonPath('data.data.0.unread', true);
    }

    #[Test]
    public function one_parents_bookmark_is_not_another_parents(): void
    {
        $thread = GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'scope' => GroupThread::SCOPE_GROUP,
            'subject' => 'Field trip',
        ]);
        $thread->messages()->create(['masjid_id' => $this->masjid->id, 'body' => 'Slips due Friday']);

        $this->consent($this->parentA, GroupMembership::CONSENT_FEED);
        $this->consent($this->parentB, GroupMembership::CONSENT_FEED);

        // A reads it. B must still be told it is unread.
        $this->as($this->parentA)->getJson($this->groupUrl("/threads/{$thread->id}"))->assertOk();

        $this->as($this->parentA)->getJson($this->groupUrl('/threads'))
            ->assertOk()->assertJsonPath('data.data.0.unread', false);

        $this->as($this->parentB)->getJson($this->groupUrl('/threads'))
            ->assertOk()->assertJsonPath('data.data.0.unread', true);
    }
}
