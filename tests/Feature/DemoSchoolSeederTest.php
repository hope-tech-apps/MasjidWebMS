<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Models\Section;
use App\Models\User;
use App\Support\DemoSchool;
use App\Support\GroupPostAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Al-Razi demo tenant seeder (`php artisan demo:seed-school`).
 *
 * Four things are actually proven here, because they are the four that could
 * hurt somebody:
 *
 *   1. it produces a REAL school tenant — provisioned through
 *      OnboardingController, so it carries the school feature bundle and the
 *      seeded school forms rather than a hand-rolled imitation;
 *   2. running it twice adds nothing and OVERWRITES nothing, including edits a
 *      demoer made in between;
 *   3. the rollback is SCOPED — a real-looking tenant seeded alongside, with
 *      its own private-disk image, comes through completely untouched;
 *   4. it refuses to run on production without --force, and is not wired into
 *      anything that runs on its own.
 */
class DemoSchoolSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The full seeded catalog (MobileAppFeaturesSeeder), mirrored as in ProvisionOrgTypeTest. */
    private const FEATURE_KEYS = [
        'quran', 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate',
        'about_us', 'gallery', 'services', 'announcements', 'contact_us',
    ];

    private const WORSHIP_KEYS = ['adhkar', 'hadith', 'qibla', 'quran', 'tasbih'];

    private int $countryId;

    private int $cityId;

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

        // Group media goes to the `local` (private) disk. Faking it keeps the
        // suite off the real storage tree while still exercising the genuine
        // write path in App\Support\GroupPostAttachments.
        Storage::fake('local');

        // Country/City declare no $fillable, so seed them through the query
        // builder rather than mass assignment (mirrors ProvisionOrgTypeTest).
        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);

        foreach (self::FEATURE_KEYS as $key) {
            MobileAppFeature::create(['key' => $key, 'name' => ucfirst($key)]);
        }
    }

    // ------------------------------------------------------------------
    // 1. A real school tenant
    // ------------------------------------------------------------------

    #[Test]
    public function it_provisions_a_school_tenant_through_the_real_onboarding_path(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $this->assertSame(Masjid::ORG_TYPE_SCHOOL, $masjid->orgType());
        $this->assertTrue((bool) $masjid->crm_enabled, 'the CRM gate must be open or no group screen loads');

        // The school FEATURE BUNDLE — proof this went through provisioning and
        // not a hand-written masjids row: a school never gets the worship
        // modules, and provisioning writes a pivot row for every catalog key.
        $enabled = MobileAppFeature::whereIn(
            'id',
            MasjidMobileAppFeature::where('masjid_id', $masjid->id)->where('is_available', true)->pluck('feature_id')
        )->pluck('key')->all();

        $this->assertSame([], array_values(array_intersect($enabled, self::WORSHIP_KEYS)));
        $this->assertContains('announcements', $enabled);
        $this->assertSame(
            count(self::FEATURE_KEYS),
            MasjidMobileAppFeature::where('masjid_id', $masjid->id)->count()
        );

        // The vertical's starter FORMS, seeded by FormTemplates inside the
        // provisioning transaction — nothing in the demo blueprint writes them.
        $this->assertEqualsCanonicalizing(
            ['admissions-interest', 'careers-application', 'withdrawal-request'],
            Form::where('masjid_id', $masjid->id)
                ->whereIn('slug', ['admissions-interest', 'careers-application', 'withdrawal-request'])
                ->pluck('slug')->all()
        );

        // Terminology follows from org_type, not from anything seeded here.
        $this->assertSame('Families', $masjid->term('members'));
        $this->assertSame('Classrooms', $masjid->term('groups'));
    }

    #[Test]
    public function every_address_it_creates_is_inside_the_unroutable_demo_namespace(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $this->assertTrue(DemoSchool::isDemoEmail($masjid->email));

        foreach (Contact::where('masjid_id', $masjid->id)->pluck('email') as $email) {
            $this->assertTrue(DemoSchool::isDemoEmail($email), "contact address escaped the demo namespace: {$email}");
        }

        foreach (User::pluck('email') as $email) {
            $this->assertTrue(DemoSchool::isDemoEmail($email), "user address escaped the demo namespace: {$email}");
        }

        // Reserved fictional NANP range (555-0100…555-0199): nothing can ring.
        foreach (Contact::where('masjid_id', $masjid->id)->pluck('phone') as $phone) {
            $this->assertMatchesRegularExpression('/^\+19055550\d{3}$/', $phone);
        }
    }

    // ------------------------------------------------------------------
    // 2. The shape a demo needs
    // ------------------------------------------------------------------

    #[Test]
    public function it_seeds_rosters_guardian_edges_and_one_deliberately_unconsented_edge(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $expectedContacts = count(DemoSchool::staff())
            + count(DemoSchool::guardians())
            + count(DemoSchool::students());

        $this->assertSame($expectedContacts, Contact::where('masjid_id', $masjid->id)->count());
        $this->assertSame(count(DemoSchool::classrooms()), Group::where('masjid_id', $masjid->id)->count());
        $this->assertSame(20, count(DemoSchool::students()));

        $guardianEdges = GroupMembership::where('masjid_id', $masjid->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN);

        $this->assertGreaterThan(20, (clone $guardianEdges)->count());

        // Every guardian edge names its ward — the invariant that makes "may
        // this adult see this child's record?" answerable from the row.
        $this->assertSame(0, (clone $guardianEdges)->whereNull('guardian_of_contact_id')->count());
        $this->assertSame(
            0,
            GroupMembership::where('masjid_id', $masjid->id)
                ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
                ->whereNotNull('guardian_of_contact_id')
                ->count()
        );

        // EXACTLY ONE edge with no consent record, so the gate can be shown.
        $unconsented = (clone $guardianEdges)->whereNull('consent_granted_at')->get();
        $this->assertCount(1, $unconsented);

        // And the same parent HAS consented for their other child elsewhere:
        // consent is per (guardian, ward, group) and never leaks sideways.
        $sameGuardianElsewhere = GroupMembership::where('masjid_id', $masjid->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('contact_id', $unconsented->first()->contact_id)
            ->whereNotNull('consent_granted_at')
            ->count();

        $this->assertSame(1, $sameGuardianElsewhere);

        // A ward always holds a participant membership in the same group.
        foreach ((clone $guardianEdges)->get() as $edge) {
            $this->assertTrue(
                GroupMembership::where('group_id', $edge->group_id)
                    ->where('contact_id', $edge->guardian_of_contact_id)
                    ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
                    ->exists(),
                'a guardian edge names a ward who is not in the group'
            );
        }
    }

    #[Test]
    public function it_writes_feed_images_to_the_private_disk_the_way_the_upload_endpoint_does(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $expectedPosts = array_sum(array_map('count', DemoSchool::posts()));
        $this->assertSame($expectedPosts, GroupPost::where('masjid_id', $masjid->id)->count());

        $attachments = GroupPostAttachment::where('masjid_id', $masjid->id)->get();
        $this->assertCount(count(DemoSchool::classrooms()), $attachments);

        foreach ($attachments as $attachment) {
            // Tenant-scoped directory, randomised stored name, real bytes the
            // download endpoint can read back.
            $this->assertStringStartsWith("group-media/{$masjid->id}/", $attachment->path);
            $this->assertStringNotContainsString($attachment->original_name, $attachment->path);
            $this->assertSame('image/png', $attachment->mime_type);
            Storage::disk($attachment->disk)->assertExists($attachment->path);
            $this->assertTrue($attachment->exists());
        }
    }

    #[Test]
    public function it_seeds_threads_behaviour_and_hifz_with_shape(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $this->assertSame(
            array_sum(array_map('count', DemoSchool::threads())),
            GroupThread::where('masjid_id', $masjid->id)->count()
        );
        $this->assertGreaterThan(0, GroupMessage::where('masjid_id', $masjid->id)->count());

        // Both thread shapes exist: one broadcast, one about a single student.
        $this->assertTrue(
            GroupThread::where('masjid_id', $masjid->id)->where('scope', GroupThread::SCOPE_GROUP)->exists()
        );
        $participant = GroupThread::where('masjid_id', $masjid->id)
            ->where('scope', GroupThread::SCOPE_PARTICIPANT)->get();
        $this->assertGreaterThan(0, $participant->count());
        foreach ($participant as $thread) {
            $this->assertNotNull($thread->about_membership_id, 'a participant thread must name its subject');
        }

        $this->assertSame(
            count(DemoSchool::behaviorSkills()),
            BehaviorSkill::where('masjid_id', $masjid->id)->count()
        );
        $this->assertSame(
            array_sum(array_map('count', DemoSchool::awards())),
            BehaviorAward::where('masjid_id', $masjid->id)->count()
        );

        // The award VALUES are snapshots, not joins: a listing must be legible
        // with the skill row deleted.
        foreach (BehaviorAward::where('masjid_id', $masjid->id)->get() as $award) {
            $this->assertNotEmpty($award->skill_label);
            $this->assertContains($award->skill_polarity, BehaviorSkill::POLARITIES);
        }

        $expectedHifz = array_sum(array_map('count', DemoSchool::hifzEntries()));
        $this->assertSame($expectedHifz, HifzEntry::where('masjid_id', $masjid->id)->count());

        // All three bands are represented, and every entry names a student.
        foreach (HifzEntry::KINDS as $kind) {
            $this->assertTrue(
                HifzEntry::where('masjid_id', $masjid->id)->where('kind', $kind)->exists(),
                "no {$kind} entries were seeded"
            );
        }
        $this->assertSame(0, HifzEntry::where('masjid_id', $masjid->id)->whereNull('group_membership_id')->count());
    }

    #[Test]
    public function it_seeds_local_money_rows_and_nothing_at_stripe(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $this->assertSame(count(DemoSchool::offerings()), Offering::where('masjid_id', $masjid->id)->count());

        $plans = FeePlan::where('masjid_id', $masjid->id)->get();
        $this->assertCount(count(DemoSchool::offerings()), $plans);
        $this->assertTrue($plans->contains(fn (FeePlan $p) => $p->kind === FeePlan::KIND_FREE));
        $this->assertTrue($plans->contains(fn (FeePlan $p) => $p->kind === FeePlan::KIND_ONE_TIME));

        // Integer minor units, always.
        foreach ($plans as $plan) {
            $this->assertIsInt($plan->amount_minor);
        }

        $registrations = Registration::where('masjid_id', $masjid->id)->get();
        $this->assertGreaterThan(0, $registrations->count());

        // Both a settled seat and an outstanding one, so the demo has contrast.
        $this->assertTrue($registrations->contains(fn (Registration $r) => $r->isConfirmed()));
        $this->assertTrue($registrations->contains(fn (Registration $r) => $r->isPending()));

        // NOTHING AT STRIPE: no identifier written, no ledger row invented.
        foreach ($registrations as $registration) {
            $this->assertNull($registration->stripe_checkout_session_id);
            $this->assertNull($registration->stripe_subscription_id);
            $this->assertNull($registration->stripe_subscription_schedule_id);
        }
        $this->assertSame(0, RegistrationPayment::where('masjid_id', $masjid->id)->count());

        // The seat counter came from RegistrationService, not from us.
        foreach (Offering::where('masjid_id', $masjid->id)->get() as $offering) {
            $this->assertSame(
                Registration::where('offering_id', $offering->id)
                    ->whereIn('status', [Registration::STATUS_PENDING, Registration::STATUS_CONFIRMED])
                    ->count(),
                (int) $offering->registration_count
            );
        }
    }

    #[Test]
    public function it_seeds_public_pages_built_from_the_school_section_types(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();

        $this->assertSame(count(DemoSchool::pages()), Page::where('masjid_id', $masjid->id)->count());

        $types = Section::where('masjid_id', $masjid->id)
            ->get()
            ->map(fn (Section $s) => $s->section_type->value)
            ->all();

        $this->assertEqualsCanonicalizing(
            ['staff_directory', 'programs', 'admissions_tuition'],
            $types
        );

        // Every section is actually placed on a page.
        foreach (Page::where('masjid_id', $masjid->id)->get() as $page) {
            $this->assertGreaterThan(0, $page->sections()->count());
        }
    }

    // ------------------------------------------------------------------
    // 3. Idempotency
    // ------------------------------------------------------------------

    #[Test]
    public function running_it_twice_adds_nothing_and_clobbers_no_edits(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();

        $masjid = $this->demoTenant();
        $before = $this->census($masjid);

        // What a demoer might do between runs.
        $group = Group::where('masjid_id', $masjid->id)->where('slug', 'grade-3-boys')->firstOrFail();
        $group->update(['name' => 'Room 4', 'description' => 'Renamed during a demo']);

        $consented = GroupMembership::where('masjid_id', $masjid->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->whereNotNull('consent_granted_at')
            ->firstOrFail();
        $consented->update(['consent_granted_at' => null, 'consent_scope' => null]);

        $post = GroupPost::where('masjid_id', $masjid->id)->firstOrFail();
        $post->update(['title' => 'Edited for the demo']);

        $skill = BehaviorSkill::where('masjid_id', $masjid->id)->firstOrFail();
        $skill->update(['default_points' => 9]);

        $this->artisan('demo:seed-school')->assertSuccessful();

        $this->assertSame($before, $this->census($masjid->fresh()));
        $this->assertSame(1, Masjid::where('email', DemoSchool::TENANT_EMAIL)->count());

        // Every edit survived: nothing is ever UPDATED by a re-run.
        $this->assertSame('Room 4', $group->fresh()->name);
        $this->assertNull($consented->fresh()->consent_granted_at);
        $this->assertSame('Edited for the demo', $post->fresh()->title);
        $this->assertSame(9, $skill->fresh()->default_points);
    }

    // ------------------------------------------------------------------
    // 4. The rollback is scoped
    // ------------------------------------------------------------------

    #[Test]
    public function rollback_removes_the_demo_tenant_and_leaves_a_real_one_completely_untouched(): void
    {
        $real = $this->seedRealLookingTenant();

        $this->artisan('demo:seed-school')->assertSuccessful();

        $demoId = $this->demoTenant()->id;
        $this->assertNotSame($real['masjid']->id, $demoId);

        $demoAttachmentPaths = GroupPostAttachment::where('masjid_id', $demoId)->pluck('path')->all();
        $this->assertNotEmpty($demoAttachmentPaths);

        $this->artisan('demo:seed-school --rollback')->assertSuccessful();

        // --- the demo is gone, down to the bytes on the private disk ---
        $this->assertNull(Masjid::withTrashed()->where('email', DemoSchool::TENANT_EMAIL)->first());
        $this->assertSame(0, Contact::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, Group::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, GroupMembership::where('masjid_id', $demoId)->count());
        $this->assertSame(0, GroupPost::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, GroupPostAttachment::where('masjid_id', $demoId)->count());
        $this->assertSame(0, GroupThread::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, GroupMessage::where('masjid_id', $demoId)->count());
        $this->assertSame(0, BehaviorAward::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, BehaviorSkill::where('masjid_id', $demoId)->count());
        $this->assertSame(0, HifzEntry::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, Registration::where('masjid_id', $demoId)->count());
        $this->assertSame(0, Offering::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, Form::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, Page::withTrashed()->where('masjid_id', $demoId)->count());
        $this->assertSame(0, Section::where('masjid_id', $demoId)->count());
        $this->assertSame(0, User::withTrashed()->where('email', 'like', '%' . DemoSchool::DOMAIN)->count());

        foreach ($demoAttachmentPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        // --- the real tenant is exactly as it was ---
        $this->assertNotNull(Masjid::find($real['masjid']->id));
        $this->assertNotNull(Contact::find($real['contact']->id));
        $this->assertNotNull(Group::find($real['group']->id));
        $this->assertNotNull(GroupMembership::find($real['membership']->id));
        $this->assertNotNull(GroupPost::find($real['post']->id));
        $this->assertNotNull(BehaviorSkill::find($real['skill']->id));
        $this->assertNotNull(Form::find($real['form']->id));
        $this->assertNotNull(Page::find($real['page']->id));
        $this->assertNotNull(Section::find($real['section']->id));
        $this->assertNotNull(User::find($real['user']->id));
        $this->assertNotNull(GroupPostAttachment::find($real['attachment']->id));
        Storage::disk('local')->assertExists($real['attachment']->path);
    }

    #[Test]
    public function rollback_on_a_database_with_no_demo_tenant_removes_nothing(): void
    {
        $real = $this->seedRealLookingTenant();

        $this->artisan('demo:seed-school --rollback')->assertSuccessful();

        $this->assertNotNull(Masjid::find($real['masjid']->id));
        $this->assertNotNull(GroupPost::find($real['post']->id));
        Storage::disk('local')->assertExists($real['attachment']->path);
    }

    #[Test]
    public function fresh_rebuilds_the_tenant_rather_than_duplicating_it(): void
    {
        $this->artisan('demo:seed-school')->assertSuccessful();
        $firstId = $this->demoTenant()->id;

        $this->artisan('demo:seed-school --fresh')->assertSuccessful();

        $this->assertSame(1, Masjid::where('email', DemoSchool::TENANT_EMAIL)->count());
        $this->assertNotSame($firstId, $this->demoTenant()->id, '--fresh provisions a new tenant row');
        $this->assertGreaterThan(0, GroupPost::where('masjid_id', $this->demoTenant()->id)->count());
    }

    // ------------------------------------------------------------------
    // 5. Opt-in only
    // ------------------------------------------------------------------

    #[Test]
    public function it_refuses_to_run_on_production_without_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('demo:seed-school')->assertFailed();

        $this->assertNull(Masjid::where('email', DemoSchool::TENANT_EMAIL)->first());
        $this->assertSame(0, User::where('email', 'like', '%' . DemoSchool::DOMAIN)->count());

        // --force is the deliberate override, and it does get through.
        $this->artisan('demo:seed-school --rollback --force')->assertSuccessful();
    }

    #[Test]
    public function nothing_that_runs_on_its_own_references_the_demo_seeder(): void
    {
        $allowed = [
            app_path('Support/DemoSchool.php'),
            app_path('Support/DemoSchoolSeeder.php'),
            app_path('Console/Commands/SeedDemoSchool.php'),
        ];

        $offenders = [];

        foreach ([app_path(), database_path(), base_path('routes')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                if (in_array($path, $allowed, true)) {
                    continue;
                }

                if (str_contains((string) file_get_contents($path), 'DemoSchool')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'the demo seeder must stay opt-in: nothing in app/, database/ or routes/ may reference it'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function demoTenant(): Masjid
    {
        return Masjid::where('email', DemoSchool::TENANT_EMAIL)->firstOrFail();
    }

    /** @return array<string,int> */
    private function census(Masjid $masjid): array
    {
        return [
            'contacts' => Contact::where('masjid_id', $masjid->id)->count(),
            'groups' => Group::where('masjid_id', $masjid->id)->count(),
            'memberships' => GroupMembership::where('masjid_id', $masjid->id)->count(),
            'posts' => GroupPost::where('masjid_id', $masjid->id)->count(),
            'attachments' => GroupPostAttachment::where('masjid_id', $masjid->id)->count(),
            'threads' => GroupThread::where('masjid_id', $masjid->id)->count(),
            'messages' => GroupMessage::where('masjid_id', $masjid->id)->count(),
            'skills' => BehaviorSkill::where('masjid_id', $masjid->id)->count(),
            'awards' => BehaviorAward::where('masjid_id', $masjid->id)->count(),
            'hifz' => HifzEntry::where('masjid_id', $masjid->id)->count(),
            'forms' => Form::where('masjid_id', $masjid->id)->count(),
            'offerings' => Offering::where('masjid_id', $masjid->id)->count(),
            'fee_plans' => FeePlan::where('masjid_id', $masjid->id)->count(),
            'registrations' => Registration::where('masjid_id', $masjid->id)->count(),
            'pages' => Page::where('masjid_id', $masjid->id)->count(),
            'sections' => Section::where('masjid_id', $masjid->id)->count(),
            'users' => User::count(),
        ];
    }

    /**
     * A second tenant that looks like a paying customer, including a private
     * image on the same disk — the control group for the rollback.
     *
     * @return array<string,mixed>
     */
    private function seedRealLookingTenant(): array
    {
        $user = User::create([
            'name' => 'Real Admin',
            'email' => 'admin@real-academy.test',
            'phone' => '+16135550111',
            'type' => 'MasjidAdmin',
            'password' => bcrypt('not-a-demo-account'),
        ]);

        $masjid = Masjid::create([
            'name' => 'Real Academy',
            'org_type' => Masjid::ORG_TYPE_SCHOOL,
            'email' => 'office@real-academy.test',
            'phone' => '+16135550112',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'address' => '1 Real Street',
            'latitude' => 45.42,
            'longitude' => -75.69,
            'user_id' => $user->id,
        ]);

        $contact = Contact::create([
            'masjid_id' => $masjid->id,
            'first_name' => 'Real',
            'last_name' => 'Student',
            'email' => 'real.student@real-academy.test',
        ]);

        $group = Group::create([
            'masjid_id' => $masjid->id,
            'name' => 'Real Classroom',
            'slug' => 'real-classroom',
            'kind' => Group::KIND_CLASS,
        ]);

        $membership = GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $post = GroupPost::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'author_user_id' => $user->id,
            'title' => 'A real post',
            'body' => 'Written by a real tenant that must survive the demo rollback.',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'real-tenant-');
        file_put_contents($path, DemoSchool::sampleImageBytes());
        [$attachment] = GroupPostAttachments::store($post, [
            new UploadedFile($path, 'real-photo.png', 'image/png', null, true),
        ]);
        @unlink($path);

        $skill = BehaviorSkill::create([
            'masjid_id' => $masjid->id,
            'label' => 'Real Skill',
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 3,
        ]);

        $form = Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'real-form',
            'name' => 'Real Form',
            'schema' => ['sections' => [['id' => 's', 'title' => 'S', 'fields' => [
                ['name' => 'realName', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ]]]],
        ]);

        $page = Page::create([
            'masjid_id' => $masjid->id,
            'slug' => 'real-page',
            'title' => 'Real Page',
            'is_active' => true,
        ]);

        $section = Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => 'text',
            'title' => 'Real Section',
            'content' => ['body' => 'Real content'],
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1]);

        return compact('user', 'masjid', 'contact', 'group', 'membership', 'post', 'attachment', 'skill', 'form', 'page', 'section');
    }
}
