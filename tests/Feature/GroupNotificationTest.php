<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Mail\GroupUpdateNudgeMail;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Groups\GroupNotificationRecipientResolver;
use App\Services\Groups\GroupPushChannel;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notifications on class events — WHO is nudged, and that the nudge carries no
 * child content.
 *
 * The consent rule is asymmetric and it is the whole point: a class-story post is
 * a broadcast (feed consent required), a message about one child is a private
 * conversation that child's guardian may always be told about (no consent gate).
 * These pin both directions, the author-skip, and the privacy-safe email.
 *
 * The resolver and the job's handle() are exercised directly — the controller
 * dispatch is a one-line afterCommit() hook whose behaviour is these two.
 */
class GroupNotificationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;
    private Masjid $masjid;
    private Group $class;
    private User $teacher;
    private Contact $studentA;
    private Contact $studentB;
    private Contact $gA1;   // guardian of A, feed-consented, login active
    private Contact $gA2;   // guardian of A, NOT consented, login active
    private Contact $gB;    // guardian of B, feed-consented, login active

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'office@al-razi.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->tenant->set($this->masjid->id);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3 · Qur\'an', 'slug' => 'g3',
        ]);

        // A teacher (User + group_staff).
        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'email' => 'teacher@al-razi.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create(['masjid_id' => $this->masjid->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->masjid->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        // Two students.
        $this->studentA = $this->child('Amira');
        $this->studentB = $this->child('Yusuf');
        $this->participant($this->studentA);
        $this->participant($this->studentB);

        // Guardians (all with a live family login).
        $this->gA1 = $this->guardianContact('huda@fam.test');
        $this->gA2 = $this->guardianContact('omar@fam.test');
        $this->gB = $this->guardianContact('sara@fam.test');

        $this->guardianEdge($this->gA1, $this->studentA, consented: true);
        $this->guardianEdge($this->gA2, $this->studentA, consented: false);
        $this->guardianEdge($this->gB, $this->studentB, consented: true);
    }

    // ---------------------------------------------------------- resolver

    #[Test]
    public function a_class_story_reaches_only_feed_consented_guardians(): void
    {
        $addrs = $this->addresses($this->resolver()->feedGuardians($this->class, null));

        $this->assertEqualsCanonicalizing(['huda@fam.test', 'sara@fam.test'], $addrs);
        $this->assertNotContains('omar@fam.test', $addrs, 'a guardian who never consented to the feed must not be nudged');
        $this->assertNotContains('teacher@al-razi.test', $addrs);
    }

    #[Test]
    public function a_ward_thread_reaches_that_wards_guardians_with_no_consent_gate(): void
    {
        $addrs = $this->addresses(
            $this->resolver()->wardGuardians($this->class, (int) $this->studentA->id, null)
        );

        // BOTH of A's guardians — consent is not consulted for a message about
        // their own child — and never B's guardian.
        $this->assertEqualsCanonicalizing(['huda@fam.test', 'omar@fam.test'], $addrs);
        $this->assertNotContains('sara@fam.test', $addrs);
    }

    #[Test]
    public function a_parent_reply_reaches_the_class_teachers(): void
    {
        $addrs = $this->addresses($this->resolver()->classTeachers($this->class, null));

        $this->assertSame(['teacher@al-razi.test'], $addrs);
    }

    #[Test]
    public function the_author_is_never_notified_about_their_own_post(): void
    {
        $addrs = $this->addresses($this->resolver()->feedGuardians($this->class, 'HUDA@fam.test'));

        $this->assertSame(['sara@fam.test'], $addrs, 'the author (case-insensitive) is removed');
    }

    #[Test]
    public function a_guardian_with_no_active_login_is_unreachable(): void
    {
        $this->gB->forceFill(['login_revoked_at' => now()])->save();

        $addrs = $this->addresses($this->resolver()->feedGuardians($this->class, null));

        $this->assertSame(['huda@fam.test'], $addrs, 'a revoked family login is skipped, not emailed a dead end');
    }

    // ---------------------------------------------------------- job / email

    #[Test]
    public function the_class_story_job_emails_consented_guardians_only(): void
    {
        Mail::fake();
        $this->runJob(GroupNotificationEvent::CLASS_STORY, authorUserId: $this->teacher->id);

        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('huda@fam.test'));
        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('sara@fam.test'));
        Mail::assertNotSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('omar@fam.test'));
        Mail::assertNotSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('teacher@al-razi.test'));
        Mail::assertSent(GroupUpdateNudgeMail::class, 2);
    }

    #[Test]
    public function a_ward_message_job_emails_both_of_that_wards_guardians(): void
    {
        Mail::fake();
        $this->runJob(GroupNotificationEvent::GUARDIAN_THREAD_MESSAGE, aboutContactId: (int) $this->studentA->id, authorUserId: $this->teacher->id);

        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('huda@fam.test'));
        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('omar@fam.test'));
        Mail::assertNotSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('sara@fam.test'));
        Mail::assertSent(GroupUpdateNudgeMail::class, 2);
    }

    #[Test]
    public function a_parent_reply_job_emails_the_teacher(): void
    {
        Mail::fake();
        $this->runJob(GroupNotificationEvent::TEACHER_THREAD_MESSAGE, authorContactId: (int) $this->gA1->id);

        Mail::assertSent(GroupUpdateNudgeMail::class, 1);
        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo('teacher@al-razi.test'));
    }

    #[Test]
    public function the_nudge_email_carries_the_class_name_but_no_child_content(): void
    {
        Mail::fake();
        $this->runJob(GroupNotificationEvent::CLASS_STORY, authorUserId: $this->teacher->id);

        Mail::assertSent(GroupUpdateNudgeMail::class, function (GroupUpdateNudgeMail $m) {
            // The mailable is a nudge: org + class label + a sign-in link only.
            // It structurally cannot carry a child's name, a post body, or a photo.
            $this->assertSame('Grade 3 · Qur\'an', $m->groupLabel);
            $this->assertSame('update', $m->kind);
            $this->assertStringContainsString('/family/'.$this->masjid->id.'/sign-in', $m->signInUrl);
            $vars = get_object_vars($m);
            $this->assertArrayNotHasKey('body', $vars);
            $this->assertArrayNotHasKey('childName', $vars);
            return true;
        });
    }

    // ---------------------------------------------------------- helpers

    private function resolver(): GroupNotificationRecipientResolver
    {
        return app(GroupNotificationRecipientResolver::class);
    }

    private function runJob(GroupNotificationEvent $event, ?int $aboutContactId = null, ?int $authorUserId = null, ?int $authorContactId = null): void
    {
        (new SendGroupNotificationJob($this->masjid->id, $this->class->id, $event, $aboutContactId, $authorUserId, $authorContactId))
            ->handle($this->resolver(), app(GroupPushChannel::class));
    }

    private function addresses($recipients): array
    {
        return $recipients->map(fn ($r) => $r->address)->sort()->values()->all();
    }

    private function child(string $first): Contact
    {
        return Contact::factory()->create(['masjid_id' => $this->masjid->id, 'first_name' => $first, 'email' => null]);
    }

    private function participant(Contact $c): void
    {
        GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'contact_id' => $c->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function guardianContact(string $email): Contact
    {
        $c = Contact::factory()->create([
            'masjid_id' => $this->masjid->id, 'first_name' => 'Guardian', 'last_name' => 'Of', 'email' => null,
        ]);

        // login_email / login_enabled_at are deliberately non-fillable (credential-
        // adjacent), so set them past the guard, as FamilyAccessService::enable does.
        $c->forceFill(['login_email' => $email, 'login_enabled_at' => now(), 'login_revoked_at' => null])->save();

        return $c;
    }

    private function guardianEdge(Contact $guardian, Contact $ward, bool $consented): void
    {
        GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'contact_id' => $guardian->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'consent_granted_at' => $consented ? now() : null,
            'consent_scope' => $consented ? GroupMembership::CONSENT_FEED : null,
        ]);
    }
}
