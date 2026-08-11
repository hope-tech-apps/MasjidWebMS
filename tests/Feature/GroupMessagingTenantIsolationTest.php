<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer guarantees behind group messaging (PLAN T-005c).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is
 * the only thing keeping one organization's parent conversations out of
 * another's queries. .claude/rules/tenant-scoping.md makes a cross-tenant test
 * mandatory for every new tenant-scoped model; this is it for GroupThread,
 * GroupMessage AND GroupThreadRead, binding TenantContext directly the way
 * ResolveMasjidTenant does per request.
 *
 * It also pins what the MODELS own rather than the HTTP boundary, because it
 * must hold for every caller:
 *   - retention: a thread is stamped with a window on create, and the shared
 *     `groups:purge-feed` sweep removes it WITH its messages and read markers;
 *   - activity: writing a message touches its thread, which is what makes
 *     "recently active first" a real ordering;
 *   - cascade hygiene: force-deleting a group takes its threads' rows with it
 *     (rows only — no bytes exist on this surface, which is exactly why the
 *     plain DB cascade is acceptable here and was not for the feed).
 */
class GroupMessagingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Group $groupA;
    private Group $groupB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — this suite must
        // never need a network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->groupA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);
        $this->groupB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);
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
        ], $overrides));
    }

    private function makeThread(Masjid $masjid, Group $group, array $overrides = []): GroupThread
    {
        return GroupThread::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
        ], $overrides));
    }

    private function makeMessage(GroupThread $thread, array $overrides = []): GroupMessage
    {
        return GroupMessage::factory()->create(array_merge([
            'masjid_id' => $thread->masjid_id,
            'group_thread_id' => $thread->id,
        ], $overrides));
    }

    private function makeReader(): User
    {
        return User::factory()->create([
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function makeRead(GroupThread $thread, User $user): GroupThreadRead
    {
        return GroupThreadRead::create([
            'masjid_id' => $thread->masjid_id,
            'group_thread_id' => $thread->id,
            'user_id' => $user->id,
            'last_read_at' => now(),
        ]);
    }

    // ---------- tenant scope: threads ----------

    #[Test]
    public function the_scope_hides_another_organizations_threads(): void
    {
        $this->makeThread($this->masjidA, $this->groupA);
        $foreign = $this->makeThread($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupThread::count());
        $this->assertSame($this->masjidA->id, GroupThread::first()->masjid_id);
        $this->assertNull(GroupThread::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_a_thread(): void
    {
        $this->tenant->set($this->masjidA->id);

        // A client-supplied masjid_id must lose to the bound tenant.
        $thread = GroupThread::create([
            'masjid_id' => $this->masjidB->id,
            'group_id' => $this->groupA->id,
            'subject' => 'Week 3 check-in',
            'scope' => GroupThread::SCOPE_GROUP,
        ]);

        $this->assertSame($this->masjidA->id, $thread->masjid_id);
    }

    #[Test]
    public function without_masjid_scope_sees_every_organizations_threads(): void
    {
        $this->makeThread($this->masjidA, $this->groupA);
        $this->makeThread($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, GroupThread::withoutMasjidScope()->count());
    }

    // ---------- tenant scope: messages ----------

    #[Test]
    public function the_scope_hides_another_organizations_messages(): void
    {
        $this->makeMessage($this->makeThread($this->masjidA, $this->groupA));
        $foreign = $this->makeMessage($this->makeThread($this->masjidB, $this->groupB));

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupMessage::count());
        $this->assertNull(GroupMessage::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_a_message(): void
    {
        $thread = $this->makeThread($this->masjidA, $this->groupA);

        $this->tenant->set($this->masjidA->id);

        $message = GroupMessage::create([
            'masjid_id' => $this->masjidB->id,
            'group_thread_id' => $thread->id,
            'body' => 'Salaam.',
        ]);

        $this->assertSame($this->masjidA->id, $message->masjid_id);
    }

    // ---------- tenant scope: read markers ----------

    #[Test]
    public function the_scope_hides_another_organizations_read_markers(): void
    {
        $reader = $this->makeReader();
        $this->makeRead($this->makeThread($this->masjidA, $this->groupA), $reader);
        $foreign = $this->makeRead($this->makeThread($this->masjidB, $this->groupB), $reader);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupThreadRead::count());
        $this->assertNull(GroupThreadRead::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_a_read_marker(): void
    {
        $thread = $this->makeThread($this->masjidA, $this->groupA);
        $reader = $this->makeReader();

        $this->tenant->set($this->masjidA->id);

        $read = GroupThreadRead::create([
            'masjid_id' => $this->masjidB->id,
            'group_thread_id' => $thread->id,
            'user_id' => $reader->id,
            'last_read_at' => now(),
        ]);

        $this->assertSame($this->masjidA->id, $read->masjid_id);
    }

    // ---------- activity clock ----------

    #[Test]
    public function writing_a_message_touches_its_thread(): void
    {
        // "Recently active first" orders by updated_at, so a message MUST move
        // it — otherwise a year-old thread with a fresh reply sinks.
        $thread = $this->makeThread($this->masjidA, $this->groupA);
        $thread->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $this->makeMessage($thread);

        $this->assertTrue($thread->fresh()->updated_at->gt(now()->subHour()));
    }

    // ---------- deletion, and what it deliberately does not do ----------

    #[Test]
    public function soft_deleting_a_thread_keeps_its_messages(): void
    {
        $thread = $this->makeThread($this->masjidA, $this->groupA);
        $message = $this->makeMessage($thread);

        $thread->delete();

        $this->assertSoftDeleted('group_threads', ['id' => $thread->id]);
        // A mis-click must not destroy what was said; only the purge does.
        $this->assertDatabaseHas('group_messages', ['id' => $message->id]);
    }

    #[Test]
    public function purging_a_thread_removes_its_messages_and_read_markers(): void
    {
        $thread = $this->makeThread($this->masjidA, $this->groupA);
        $message = $this->makeMessage($thread);
        $read = $this->makeRead($thread, $this->makeReader());

        $thread->purge();

        // Rows only, so the DB cascade is the whole teardown — nothing on disk
        // exists to orphan, which is why this is forceDelete() and not the
        // model-walking purge the feed needs.
        $this->assertDatabaseMissing('group_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('group_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('group_thread_reads', ['id' => $read->id]);
    }

    #[Test]
    public function force_deleting_a_group_takes_its_threads_and_messages(): void
    {
        $thread = $this->makeThread($this->masjidA, $this->groupA);
        $message = $this->makeMessage($thread);

        $this->groupA->forceDelete();

        $this->assertDatabaseMissing('group_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('group_messages', ['id' => $message->id]);
    }

    // ---------- retention ----------

    #[Test]
    public function a_new_thread_inherits_the_configured_retention_window(): void
    {
        config(['groups.messaging.retention_days' => 30]);

        $thread = $this->makeThread($this->masjidA, $this->groupA);

        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $thread->retained_until->toDateString()
        );
    }

    #[Test]
    public function a_retention_window_the_caller_sets_is_respected(): void
    {
        config(['groups.messaging.retention_days' => 30]);

        $thread = $this->makeThread($this->masjidA, $this->groupA, [
            'retained_until' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertSame(now()->addDays(2)->toDateString(), $thread->retained_until->toDateString());
    }

    #[Test]
    public function retention_days_of_zero_leaves_the_window_open(): void
    {
        config(['groups.messaging.retention_days' => 0]);

        $thread = $this->makeThread($this->masjidA, $this->groupA);

        $this->assertNull($thread->retained_until);
    }

    #[Test]
    public function the_purge_sweep_removes_threads_past_their_retention_date(): void
    {
        $due = $this->makeThread($this->masjidA, $this->groupA, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $dueMessage = $this->makeMessage($due);

        // Soft-deleted AND past retention: exactly the row that must not linger.
        $removed = $this->makeThread($this->masjidA, $this->groupA, [
            'retained_until' => now()->subMonth()->toDateString(),
        ]);
        $removed->delete();

        Artisan::call('groups:purge-feed');

        $this->assertDatabaseMissing('group_threads', ['id' => $due->id]);
        $this->assertDatabaseMissing('group_threads', ['id' => $removed->id]);
        $this->assertDatabaseMissing('group_messages', ['id' => $dueMessage->id]);
    }

    #[Test]
    public function the_purge_sweep_leaves_threads_inside_their_window(): void
    {
        $keep = $this->makeThread($this->masjidA, $this->groupA, [
            'retained_until' => now()->addYear()->toDateString(),
        ]);
        $open = $this->makeThread($this->masjidA, $this->groupA, ['retained_until' => null]);

        Artisan::call('groups:purge-feed');

        $this->assertDatabaseHas('group_threads', ['id' => $keep->id]);
        // A null window means "nobody has decided", never "purge me now".
        $this->assertDatabaseHas('group_threads', ['id' => $open->id]);
    }

    #[Test]
    public function the_purge_sweep_can_be_limited_to_one_organization(): void
    {
        $mine = $this->makeThread($this->masjidA, $this->groupA, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $theirs = $this->makeThread($this->masjidB, $this->groupB, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);

        Artisan::call('groups:purge-feed', ['--masjid' => $this->masjidA->id]);

        $this->assertDatabaseMissing('group_threads', ['id' => $mine->id]);
        $this->assertDatabaseHas('group_threads', ['id' => $theirs->id]);
    }

    #[Test]
    public function a_dry_run_purge_deletes_no_threads(): void
    {
        $due = $this->makeThread($this->masjidA, $this->groupA, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $message = $this->makeMessage($due);

        Artisan::call('groups:purge-feed', ['--dry-run' => true]);

        $this->assertDatabaseHas('group_threads', ['id' => $due->id]);
        $this->assertDatabaseHas('group_messages', ['id' => $message->id]);
    }
}
