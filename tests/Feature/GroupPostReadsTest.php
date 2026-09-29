<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupPostRead;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * Read receipts on class stories — "Seen by 4 of 7 parents"
 * (owner, 2026-09-29; T-002.3).
 *
 * The receipts ship BEHIND a switch that is OFF by default
 * (`groups.story_reads.enabled`), because the parent-facing notice's ar / ur /
 * ps / fa-AF wording is machine-drafted and needs a human review before any read
 * is recorded in production. What this file pins:
 *
 *   - OFF by default: recording writes nothing, the family portal is told not to
 *     draw the notice or fire the POST, and the staff payload OMITS the "seen"
 *     fields rather than showing a zero for a receipt nobody keeps;
 *   - a read is recorded ONLY by the client POST — never by the /posts GET, which
 *     the portal fires on page load whatever tab is open;
 *   - the POST is the FEED READ gate: no consent, withdrawn consent, a departed
 *     family, or a revoked login records nothing; foreign / other-class /
 *     soft-deleted ids are ignored;
 *   - the AUDIENCE (the denominator) is consented, current guardians with a live
 *     login — a parent who cannot have seen a story is not counted;
 *   - `seen_by`, `seen_count` and `audience_count` exist ONLY in the staff
 *     payloads. The test walks the FAMILY JSON — every surface a parent can
 *     read — rather than trusting one serializer;
 *   - staff see names; the row is idempotent and keeps the FIRST time.
 */
class GroupPostReadsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    /** Every key the family payload must never carry, at any depth. */
    private const STAFF_ONLY_KEYS = ['seen_by', 'seen_count', 'audience_count', 'unreachable_count', 'seen_tracked', 'seen_since'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        Bus::fake([SendGroupNotificationJob::class]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->buildStoryWorld();
        $this->consent($this->parentA);
        $this->consent($this->parentB);
    }

    private function switchOn(): void
    {
        config(['groups.story_reads.enabled' => true]);
    }

    private function seen(array $ids, Contact $parent): \Illuminate\Testing\TestResponse
    {
        return $this->asParent($parent)->postJson($this->familyUrl('/posts/seen'), ['post_ids' => $ids]);
    }

    private function readRows(): int
    {
        return GroupPostRead::withoutMasjidScope()->count();
    }

    /** Every key at every depth of a decoded payload. */
    private function allKeys(mixed $node, array &$found = []): array
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $found[] = $key;
                }
                $this->allKeys($value, $found);
            }
        }

        return $found;
    }

    // ------------------------------------------------ the switch (OFF by default)

    #[Test]
    public function the_switch_is_off_by_default(): void
    {
        // Read from the repository's own config file, not from this test's
        // environment: production ships this value, so this is what it ships.
        $config = require base_path('config/groups.php');

        $this->assertFalse($config['story_reads']['enabled'], 'read receipts must ship OFF until the notice translations are reviewed');
        $this->assertFalse((bool) config('groups.story_reads.enabled'));
    }

    #[Test]
    public function while_off_nothing_is_recorded_and_the_portal_is_told_not_to_ask(): void
    {
        $post = $this->makePost();

        $family = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk();
        $this->assertFalse($family->json('meta.story_reads.enabled'));

        $this->seen([$post->id], $this->parentA)
            ->assertOk()
            ->assertJsonPath('data.recorded', 0)
            ->assertJsonPath('data.enabled', false);

        $this->assertSame(0, $this->readRows());
    }

    #[Test]
    public function while_off_the_staff_payload_omits_the_seen_fields_rather_than_showing_zero(): void
    {
        $this->makePost();

        $response = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();

        $this->assertFalse($response->json('meta.story_reads.enabled'));
        $this->assertArrayNotHasKey('unreachable_count', $response->json('meta.story_reads'));
        $keys = $this->allKeys($response->json('data'));
        foreach (self::STAFF_ONLY_KEYS as $key) {
            $this->assertNotContains($key, $keys, "`{$key}` was served while receipts are off");
        }
    }

    // ------------------------------------------------ recorded ONLY by the POST

    #[Test]
    public function a_get_never_records_a_read_however_many_posts_it_returns(): void
    {
        $this->switchOn();
        foreach (range(1, 15) as $i) {
            $this->makePost(body: "Story {$i}");
        }
        $one = GroupPost::query()->orderBy('id')->firstOrFail();

        // The portal fetches /posts on page load whatever tab is open, and it
        // returns the 15 newest stories: a GET-side write would mark them all
        // "seen" when a parent only opened Grades.
        $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk()->assertJsonCount(15, 'data.data');
        $this->asParent($this->parentA)->getJson($this->familyUrl('/posts?per_page=100'))->assertOk();
        $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$one->id}"))->assertOk();
        $this->asParent($this->parentA)->getJson($this->familyUrl(''))->assertOk();

        $this->assertSame(0, $this->readRows());
    }

    #[Test]
    public function the_post_records_the_stories_named_for_this_parent_only_and_is_idempotent(): void
    {
        $this->switchOn();
        $a = $this->makePost(body: 'One');
        $b = $this->makePost(body: 'Two');

        $this->travelTo(now()->startOfMinute());
        $this->seen([$a->id, $b->id], $this->parentA)
            ->assertOk()
            ->assertJsonPath('data.recorded', 2)
            ->assertJsonPath('data.enabled', true);

        $this->assertSame(2, $this->readRows());
        $rows = GroupPostRead::withoutMasjidScope()->get();
        $this->assertSame([$this->parentA->id], $rows->pluck('contact_id')->unique()->values()->all());
        $this->assertSame([$this->school->id], $rows->pluck('masjid_id')->map(fn ($v) => (int) $v)->unique()->values()->all());

        // Again, later: nothing new, and the FIRST time is kept.
        $first = $rows->firstWhere('group_post_id', $a->id)->first_seen_at->toIso8601String();
        $this->travel(2)->hours();
        $this->seen([$a->id, $b->id], $this->parentA)->assertOk()->assertJsonPath('data.recorded', 0);
        $this->assertSame(2, $this->readRows());
        $this->assertSame($first, GroupPostRead::withoutMasjidScope()->where('group_post_id', $a->id)->sole()->first_seen_at->toIso8601String());
    }

    #[Test]
    public function ids_that_are_not_stories_of_this_class_are_ignored(): void
    {
        $this->switchOn();
        $mine = $this->makePost();
        $gone = $this->makePost(body: 'Deleted');
        $gone->delete();

        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $inOtherClass = $this->makePost(class: $otherClass);

        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $foreignTeacher = $this->makeTeacher($this->otherSchool, $foreignClass, 'Ustadh Elsewhere');
        $foreign = $this->makePost($foreignTeacher, class: $foreignClass);

        $this->seen([$mine->id, $gone->id, $inOtherClass->id, $foreign->id, 999999], $this->parentA)
            ->assertOk()
            ->assertJsonPath('data.recorded', 1);

        $this->assertSame([$mine->id], GroupPostRead::withoutMasjidScope()->pluck('group_post_id')->map(fn ($v) => (int) $v)->all());
    }

    #[Test]
    public function the_request_shape_is_validated_and_bounded(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        foreach ([
            [],
            ['post_ids' => 'nope'],
            ['post_ids' => []],
            ['post_ids' => ['abc']],
            ['post_ids' => [[1]]],
            ['post_ids' => range(1, 51)],
        ] as $body) {
            $this->asParent($this->parentA)
                ->postJson($this->familyUrl('/posts/seen'), $body)
                ->assertStatus(422);
        }

        $this->assertSame(0, $this->readRows());

        // The boundary itself is fine.
        $this->seen(array_merge([$post->id], range(1000, 1048)), $this->parentA)->assertOk();
    }

    // ------------------------------------------------ who may be recorded

    #[Test]
    public function only_a_consented_current_guardian_with_a_live_login_can_be_recorded(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        // Never consented.
        [$stranger] = $this->makeFamily('Layla', 'Noor', 'Haddad');
        $this->seen([$post->id], $stranger)->assertStatus(403);

        // Consent withdrawn.
        $this->withdrawConsent($this->parentA);
        $this->seen([$post->id], $this->parentA)->assertStatus(403);

        // Family left the class.
        $this->familyLeaves($this->parentB);
        $this->seen([$post->id], $this->parentB)->assertStatus(403);

        $this->assertSame(0, $this->readRows());
    }

    #[Test]
    public function a_revoked_login_records_nothing(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        $token = $this->parentA->createFamilyToken()->plainTextToken;
        $this->parentA->forceFill(['login_revoked_at' => now()])->save();

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $status = $this->flushHeaders()
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($this->familyUrl('/posts/seen'), ['post_ids' => [$post->id]])
            ->status();

        $this->assertContains($status, [401, 403]);
        $this->assertSame(0, $this->readRows());
    }

    #[Test]
    public function a_staff_member_opening_the_story_records_nothing(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        // There is no staff route that writes a read, and a staff token on the
        // family route is a refusal, not a read.
        $status = $this->asTeacher()->postJson($this->familyUrl('/posts/seen'), ['post_ids' => [$post->id]])->status();
        $this->assertContains($status, [401, 403]);

        $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
        $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertOk();

        $this->assertSame(0, $this->readRows());
    }

    // ------------------------------------------------ the fraction

    #[Test]
    public function the_audience_counts_only_parents_who_could_have_seen_the_story(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        // Consented, current, live login: parents A and B (from setUp), plus:
        [$noLogin] = $this->makeFamily('Idris', 'Salma', 'Noor', login: false);
        $this->consent($noLogin);
        [$noConsent] = $this->makeFamily('Layla', 'Rania', 'Haddad');
        [$departed] = $this->makeFamily('Omar', 'Hind', 'Saleh');
        $this->consent($departed);
        $this->familyLeaves($departed);

        $this->seen([$post->id], $this->parentA)->assertOk();
        $this->seen([$post->id], $this->parentB)->assertOk();

        // Stale rows for people who could NOT have seen it (data that predates
        // the current state): they must not inflate either side of the fraction.
        foreach ([$noLogin, $noConsent, $departed] as $contact) {
            GroupPostRead::create([
                'masjid_id' => $this->school->id, 'group_post_id' => $post->id,
                'contact_id' => $contact->id, 'first_seen_at' => now(),
            ]);
        }
        $this->assertSame(5, $this->readRows());

        $response = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
        $row = $response->json('data.data.0');

        $this->assertSame(2, $row['audience_count']);
        $this->assertSame(2, $row['seen_count']);
        $this->assertEqualsCanonicalizing(['Huda Yusuf', 'Maryam Karimi'], array_column($row['seen_by'], 'name'));
        $this->assertLessThanOrEqual($row['audience_count'], $row['seen_count']);

        // One parent (no login) is on paper the story's audience and a receipt
        // cannot reach them: the footnote says so.
        $this->assertSame(1, $response->json('meta.story_reads.unreachable_count'));
        $this->assertTrue($response->json('meta.story_reads.enabled'));
    }

    #[Test]
    public function the_fraction_moves_when_a_parent_reads_and_is_zero_of_n_before(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        $before = $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertOk();
        $this->assertSame(0, $before->json('data.seen_count'));
        $this->assertSame(2, $before->json('data.audience_count'));
        $this->assertSame([], $before->json('data.seen_by'));

        $this->seen([$post->id], $this->parentA)->assertOk();

        $after = $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertOk();
        $this->assertSame(1, $after->json('data.seen_count'));
        $this->assertSame('Huda Yusuf', $after->json('data.seen_by.0.name'));
        $this->assertNotNull($after->json('data.seen_by.0.seen_at'));
    }

    #[Test]
    public function the_office_that_leads_the_class_sees_the_same_receipt_by_name(): void
    {
        $this->switchOn();
        $post = $this->makePost();
        $admin = $this->makeLeadingAdmin();
        $this->seen([$post->id], $this->parentB)->assertOk();

        $response = $this->asUser($admin)->getJson($this->adminUrl("/posts/{$post->id}"))->assertOk();

        $this->assertSame(1, $response->json('data.seen_count'));
        $this->assertSame('Maryam Karimi', $response->json('data.seen_by.0.name'));
    }

    #[Test]
    public function a_deleted_story_leaves_no_receipt_behind(): void
    {
        $this->switchOn();
        $post = $this->makePost();
        $this->seen([$post->id], $this->parentA)->assertOk();

        $post->purge();

        $this->assertSame(0, $this->readRows());
    }

    // ------------------------------------------------ staff-only, by walking the JSON

    #[Test]
    public function the_family_payload_never_carries_a_receipt_on_any_surface(): void
    {
        $this->switchOn();
        $post = $this->makePost();
        $this->seen([$post->id], $this->parentA)->assertOk();
        $this->seen([$post->id], $this->parentB)->assertOk();

        // EVERY surface a parent can read a story, or a receipt, through.
        $surfaces = [
            'list' => $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk(),
            'show' => $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertOk(),
            'seen' => $this->seen([$post->id], $this->parentA)->assertOk(),
            'react' => $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk(),
            'unreact' => $this->asParent($this->parentA)->deleteJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk(),
            'class' => $this->asParent($this->parentA)->getJson($this->familyUrl(''))->assertOk(),
        ];

        foreach ($surfaces as $name => $response) {
            $keys = $this->allKeys($response->json());

            foreach (self::STAFF_ONLY_KEYS as $key) {
                $this->assertNotContains($key, $keys, "the family '{$name}' payload carries `{$key}`");
            }

            // Nor is another family's reading told by name in any form.
            $raw = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach (['Maryam', 'Karimi', 'Zayd'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, "the family '{$name}' payload names {$secret}");
            }
        }

        // And it IS on the staff side, so the walk above is not vacuous.
        $staff = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
        $this->assertContains('seen_by', $this->allKeys($staff->json()));
    }

    #[Test]
    public function the_notice_flag_and_the_recording_switch_are_one_value(): void
    {
        $this->makePost();

        // OFF: the portal is told not to show the notice, and would not record.
        $this->assertFalse($this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->json('meta.story_reads.enabled'));

        // ON: the same request now says show the notice — and the POST records.
        $this->switchOn();
        $this->assertTrue($this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->json('meta.story_reads.enabled'));
        $this->assertTrue($this->asParent($this->parentA)->getJson($this->familyUrl('/posts/'.GroupPost::query()->value('id')))->json('meta.story_reads.enabled'));
    }

    #[Test]
    public function the_family_and_staff_payloads_share_one_story_reads_shape(): void
    {
        $this->makePost();

        foreach ([false, true] as $on) {
            config(['groups.story_reads.enabled' => $on]);

            $family = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk()->json('meta.story_reads');
            $staff = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk()->json('meta.story_reads');

            $this->assertIsArray($family, 'an object with `enabled`, never a bare boolean: shared code reads `.enabled`');
            $this->assertSame(['enabled'], array_keys($family), 'the family shape carries no staff-only field');
            $this->assertSame($on, $family['enabled']);
            $this->assertSame($on, $staff['enabled']);
        }
    }

    // ------------------------------------------------ stories older than recording

    /** A story written $days ago. */
    private function makeOldPost(int $days, string $body): GroupPost
    {
        $post = $this->makePost(body: $body);
        GroupPost::withoutMasjidScope()->whereKey($post->id)->update(['created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);

        return $post->refresh();
    }

    private function readAt(GroupPost $post, Contact $parent, \Illuminate\Support\Carbon $when): void
    {
        GroupPostRead::create([
            'masjid_id' => $this->school->id, 'group_post_id' => $post->id,
            'contact_id' => $parent->id, 'first_seen_at' => $when,
        ]);
    }

    #[Test]
    public function a_story_that_predates_recording_is_not_tracked_rather_than_seen_by_zero(): void
    {
        $this->switchOn();
        $since = now()->subDays(3)->toDateString();
        config(['groups.story_reads.since' => $since]);

        $unread = $this->makeOldPost(10, 'Old, no read on it');
        $readLater = $this->makeOldPost(9, 'Old, but a parent opened it since');
        $this->readAt($readLater, $this->parentA, now()->subDay());
        $fresh = $this->makePost(body: 'Written after recording began');

        $rows = collect($this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk()->json('data.data'))->keyBy('id');

        // Predates recording, nothing on it: "not kept", and no zero to misread.
        $old = $rows[$unread->id];
        $this->assertFalse($old['seen_tracked']);
        $this->assertSame($since, $old['seen_since']);
        foreach (['seen_by', 'seen_count', 'audience_count'] as $key) {
            $this->assertArrayNotHasKey($key, $old, "`{$key}` would print 'Seen by 0 of N' for a story nobody was keeping receipts on");
        }

        // A read that was recorded is a real read, whatever the story's age.
        $this->assertArrayNotHasKey('seen_tracked', $rows[$readLater->id]);
        $this->assertSame(1, $rows[$readLater->id]['seen_count']);

        // Written after recording began: zero of N really is zero.
        $this->assertArrayNotHasKey('seen_tracked', $rows[$fresh->id]);
        $this->assertSame(0, $rows[$fresh->id]['seen_count']);
        $this->assertSame(2, $rows[$fresh->id]['audience_count']);

        // The single-post payload says the same.
        $this->asTeacher()->getJson($this->teacherUrl("/posts/{$unread->id}"))->assertOk()
            ->assertJsonPath('data.seen_tracked', false)
            ->assertJsonPath('data.seen_since', $since);

        // Never in the family payload, whatever the story's age.
        $family = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk()->json();
        $keys = $this->allKeys($family);
        foreach (self::STAFF_ONLY_KEYS as $key) {
            $this->assertNotContains($key, $keys, "`{$key}` reached the family payload");
        }
    }

    #[Test]
    public function without_a_configured_day_recording_began_at_the_schools_earliest_read(): void
    {
        $this->switchOn();
        config(['groups.story_reads.since' => null]);

        $ancient = $this->makeOldPost(20, 'Before anything was recorded');
        $older = $this->makeOldPost(5, 'Read on the first day');
        $recent = $this->makeOldPost(1, 'Written after the first read');
        $firstDay = now()->subDays(2);
        $this->readAt($older, $this->parentA, $firstDay);

        $rows = collect($this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk()->json('data.data'))->keyBy('id');

        $this->assertFalse($rows[$ancient->id]['seen_tracked']);
        $this->assertSame($firstDay->toDateString(), $rows[$ancient->id]['seen_since']);
        $this->assertSame(1, $rows[$older->id]['seen_count']);
        $this->assertArrayNotHasKey('seen_tracked', $rows[$recent->id]);
        $this->assertSame(0, $rows[$recent->id]['seen_count']);
    }

    #[Test]
    public function with_no_configured_day_and_no_read_yet_no_story_is_marked(): void
    {
        $this->switchOn();
        config(['groups.story_reads.since' => null]);
        $old = $this->makeOldPost(30, 'Old');

        $row = $this->asTeacher()->getJson($this->teacherUrl("/posts/{$old->id}"))->assertOk();

        // Nothing to date it by: plain, honest zero until the first parent opens the page.
        $this->assertSame(0, $row->json('data.seen_count'));
        $this->assertNull($row->json('data.seen_tracked'));
    }

    #[Test]
    public function the_tracking_day_is_never_served_while_receipts_are_off_and_a_bad_date_is_ignored(): void
    {
        config(['groups.story_reads.since' => now()->subDay()->toDateString()]);
        $this->makeOldPost(10, 'Old');

        $off = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
        $keys = $this->allKeys($off->json('data'));
        foreach (self::STAFF_ONLY_KEYS as $key) {
            $this->assertNotContains($key, $keys, "`{$key}` was served while receipts are off");
        }

        // A malformed date must not 500 the list: it falls back to the data.
        $this->switchOn();
        config(['groups.story_reads.since' => 'the day we started']);
        $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
    }

    // ------------------------------------------------ a guardian with two children

    #[Test]
    public function a_parent_with_two_children_and_no_login_is_one_unreachable_parent_not_two(): void
    {
        $this->switchOn();
        $this->makePost();

        [$noLogin] = $this->makeFamily('Idris', 'Salma', 'Noor', login: false);
        $this->addSibling($noLogin, 'Yusuf');
        $this->consent($noLogin);

        $response = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();

        $this->assertSame(1, $response->json('meta.story_reads.unreachable_count'), 'two children, one parent');
    }

    #[Test]
    public function a_parent_with_two_children_is_one_reader_in_the_audience(): void
    {
        $this->switchOn();
        $post = $this->makePost();

        $this->addSibling($this->parentA, 'Yusuf');
        $this->consent($this->parentA);
        $this->seen([$post->id], $this->parentA)->assertOk();

        $row = $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertOk();

        $this->assertSame(2, $row->json('data.audience_count'), 'Huda and Maryam, however many children each has');
        $this->assertSame(1, $row->json('data.seen_count'));
        $this->assertSame(['Huda Yusuf'], array_column($row->json('data.seen_by'), 'name'));
    }

    // ------------------------------------------------ the order of "Seen by"

    #[Test]
    public function seen_by_lists_parents_in_the_order_they_first_opened_the_story(): void
    {
        $this->switchOn();
        $post = $this->makePost();
        $now = now();

        // Maryam's row is written FIRST (lower id) but her first look was LATER, so
        // neither insertion order nor contact id can produce this answer.
        $this->readAt($post, $this->parentB, $now->copy()->addMinutes(5));
        $this->readAt($post, $this->parentA, $now->copy());

        $seenBy = $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertOk()->json('data.seen_by');

        $this->assertSame(['Huda Yusuf', 'Maryam Karimi'], array_column($seenBy, 'name'));
        $this->assertSame(
            [$now->copy()->toIso8601String(), $now->copy()->addMinutes(5)->toIso8601String()],
            array_column($seenBy, 'seen_at')
        );
    }

    #[Test]
    public function seen_by_breaks_a_tie_on_the_same_instant_by_the_order_the_reads_arrived(): void
    {
        $this->switchOn();
        $post = $this->makePost();
        $same = now();

        // Same instant. Maryam's read arrived first even though Huda's contact id is lower.
        $this->readAt($post, $this->parentB, $same);
        $this->readAt($post, $this->parentA, $same);

        $names = array_column(
            $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertOk()->json('data.seen_by'),
            'name'
        );

        $this->assertSame(['Maryam Karimi', 'Huda Yusuf'], $names);
    }

    // ------------------------------------------------ tenancy + schema

    #[Test]
    public function a_bound_tenant_cannot_read_update_or_delete_another_organizations_group_post_read(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();

        $mk = function (Masjid $masjid, string $name) {
            $group = Group::factory()->create([
                'masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS, 'name' => $name, 'slug' => strtolower($name),
            ]);
            $teacher = $this->makeTeacher($masjid, $group, 'Teacher '.$name);
            $post = $this->makePost($teacher, class: $group);
            $contact = Contact::factory()->create(['masjid_id' => $masjid->id]);

            return GroupPostRead::create([
                'masjid_id' => $masjid->id, 'group_post_id' => $post->id,
                'contact_id' => $contact->id, 'first_seen_at' => now(),
            ]);
        };

        $inA = $mk($a, 'Alpha');
        $inB = $mk($b, 'Beta');

        app(TenantContext::class)->set($a->id);

        $this->assertNull(GroupPostRead::find($inB->id));
        $this->assertSame(0, GroupPostRead::query()->where('id', $inB->id)->update(['first_seen_at' => now()->subYear()]));
        $this->assertSame(0, GroupPostRead::query()->where('id', $inB->id)->delete());
        $this->assertSame(1, GroupPostRead::query()->count());

        // create() stamps the BOUND tenant over a client-supplied masjid_id.
        $post = GroupPost::query()->firstOrFail();
        $other = Contact::factory()->create(['masjid_id' => $a->id]);
        $stamped = GroupPostRead::create([
            'masjid_id' => $b->id, 'group_post_id' => $post->id,
            'contact_id' => $other->id, 'first_seen_at' => now(),
        ]);
        $this->assertSame($a->id, (int) $stamped->masjid_id);

        app(TenantContext::class)->forgetTenant();

        $this->assertNotNull(GroupPostRead::withoutMasjidScope()->find($inB->id));
        $this->assertSame($inA->masjid_id, GroupPostRead::withoutMasjidScope()->find($inA->id)->masjid_id);
    }

    #[Test]
    public function the_schema_is_what_the_code_assumes(): void
    {
        $this->assertTrue(Schema::hasColumns('group_post_reads', [
            'masjid_id', 'group_post_id', 'contact_id', 'first_seen_at',
        ]));

        // first_seen_at is a DATETIME: a timestamp() would carry MySQL's implicit
        // ON UPDATE and the "first" time would quietly become the latest.
        $column = collect(Schema::getColumns('group_post_reads'))->firstWhere('name', 'first_seen_at');
        $this->assertStringContainsString('datetime', strtolower($column['type']));

        foreach (Schema::getIndexes('group_post_reads') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
        }
    }
}
