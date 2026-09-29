<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveMasjidTenant;
use App\Mail\AccountAccessMail;
use App\Mail\StaffAddedToOrganisation;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin door for a teacher who ALREADY has a Manara login at another school
 * (docs/multi-tenant-admin-design.md §6, DECISIONS.md 2026-09-29 and the critic's
 * fixes): TeachersController::store is create-or-attach.
 *
 * What is pinned, and why each one matters:
 *   - attach writes a membership and classes and touches NOTHING on `users`
 *     (password, name, phone, tokens), and sends a notice with no password link;
 *   - it refuses every other kind of login, and restores a trashed teacher only
 *     when no SuperAdmin trashed them on purpose (H1);
 *   - the response cannot tell the inviting school whether the email existed
 *     elsewhere, by message OR by data (H2);
 *   - a shared teacher's global fields are read-only and hidden from the second
 *     school, and the second school cannot sign them out of the first;
 *   - removing a teacher from one school leaves the other intact, promotes a
 *     default, and never asks the gate.
 *
 * The suite default for `tenancy.multi_membership` is shut and production is
 * open, so every test that depends on it sets it explicitly.
 */
class TeacherAttachTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $alrazi;   // the teacher's existing school
    private Masjid $biss;     // the school that adds them
    private User $alraziAdmin;
    private User $bissAdmin;
    private Group $alraziClass;
    private Group $seventh;
    private Group $eighth;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['tenancy.multi_membership' => true]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->alrazi, $this->alraziAdmin] = $this->makeSchoolWithAdmin('Al-Razi');
        [$this->biss, $this->bissAdmin] = $this->makeSchoolWithAdmin('BISS');

        $this->alraziClass = $this->makeClass($this->alrazi, 'Grade 3');
        $this->seventh = $this->makeClass($this->biss, '7th Grade');
        $this->eighth = $this->makeClass($this->biss, '8th Grade');

        Mail::fake();
        Sanctum::actingAs($this->bissAdmin, ['staff']);
    }

    // ------------------------------------------------------------------ T1.4

    #[Test]
    public function attaching_an_existing_teacher_adds_a_school_and_classes_and_keeps_everything_else(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass], ['name' => 'Stored Name', 'phone' => '+15550001111']);
        $teacher->createToken('phone');
        $teacher->createToken('laptop');
        $hashBefore = $teacher->fresh()->password;

        $this->postJson($this->bissBase().'/teachers', [
            'name' => 'Typed Name',
            'email' => $teacher->email,
            'phone' => '+15559990000',
            'class_ids' => [$this->seventh->id, $this->eighth->id],
            'class_subjects' => [$this->seventh->id => ['islamic_studies'], $this->eighth->id => ['islamic_studies']],
        ])->assertCreated();

        // Exactly one new membership, NOT the default (they already have one).
        $rows = MasjidUser::where('user_id', $teacher->id)->orderBy('masjid_id')->get();
        $this->assertCount(2, $rows);
        $bissRow = $rows->firstWhere('masjid_id', $this->biss->id);
        $this->assertFalse((bool) $bissRow->is_default);
        $this->assertSame('teacher', $bissRow->role);
        $this->assertTrue((bool) $rows->firstWhere('masjid_id', $this->alrazi->id)->is_default, 'the original default is untouched');

        // Their classes at BISS, stamped with BISS, subject as typed.
        $staff = GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->orderBy('group_id')->get();
        $this->assertSame([$this->seventh->id, $this->eighth->id], $staff->pluck('group_id')->map(fn ($i): int => (int) $i)->all());
        $this->assertSame([['islamic_studies'], ['islamic_studies']], $staff->map(fn ($r) => $r->subjects)->all());
        $this->assertSame(1, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->alrazi->id)->count(), 'Al-Razi assignment untouched');

        // `users` is NOT written: password, name, phone, sessions.
        $fresh = $teacher->fresh();
        $this->assertSame($hashBefore, $fresh->password);
        $this->assertSame('Stored Name', $fresh->name);
        $this->assertSame('+15550001111', $fresh->phone);
        $this->assertSame(2, $fresh->tokens()->count());

        // No set-password token was minted, and the notice — not the invite — was sent.
        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $teacher->email)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $teacher->email)->count());
        Mail::assertNotSent(AccountAccessMail::class);
        Mail::assertSent(StaffAddedToOrganisation::class, function (StaffAddedToOrganisation $mail) use ($teacher) {
            return $mail->hasTo($teacher->email)
                && $mail->orgName === $this->biss->name
                && $mail->classNames === ['7th Grade', '8th Grade'];
        });
    }

    #[Test]
    public function the_added_notice_carries_no_link_that_can_change_the_login(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();

        Mail::assertSent(StaffAddedToOrganisation::class, function (StaffAddedToOrganisation $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('/auth/sign-in', $html);
            $this->assertStringNotContainsString('reset-password', $html);
            $this->assertStringNotContainsString('token=', $html);
            $this->assertStringContainsString('7th Grade', $html);

            return true;
        });
    }

    // ------------------------------------------------------------------ M2

    #[Test]
    public function the_email_is_matched_case_insensitively_and_new_logins_are_stored_lowercase(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass], ['email' => 'moneeb@hopetechapps.test']);
        $usersBefore = User::withTrashed()->count();

        $this->postJson($this->bissBase().'/teachers', $this->payload("  Moneeb@HopeTechApps.TEST ", [$this->seventh]))->assertCreated();

        $this->assertSame($usersBefore, User::withTrashed()->count(), 'a mixed-case address must find the existing login, not mint a second');
        $this->assertSame(2, MasjidUser::where('user_id', $teacher->id)->count());

        // A LEGACY row stored with capitals (SQLite compares case-sensitively; a
        // lowercase lookup must still find it).
        $legacy = $this->teacherAt($this->alrazi, [], ['email' => 'Legacy.Teacher@Example.test']);
        $this->postJson($this->bissBase().'/teachers', $this->payload('legacy.teacher@example.test', [$this->eighth]))->assertCreated();
        $this->assertSame(2, MasjidUser::where('user_id', $legacy->id)->count());

        // A brand-new login is stored lowercased.
        $this->postJson($this->bissBase().'/teachers', $this->payload('New.Person@Example.TEST', [$this->seventh]))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'new.person@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'New.Person@Example.TEST']);
    }

    // ---------------------------------------------------------------- T1.5

    /** @return array<string, array{0: string}> */
    public static function otherKindsOfLogin(): array
    {
        return [
            'MasjidAdmin' => ['MasjidAdmin'],
            'LunchStaff' => [User::TYPE_LUNCH_STAFF],
            'SuperAdmin' => ['SuperAdmin'],
        ];
    }

    #[Test]
    #[DataProvider('otherKindsOfLogin')]
    public function a_login_of_any_other_type_is_refused_and_nothing_is_written(string $type): void
    {
        $other = User::factory()->create(['type' => $type, 'phone' => '+15550002222']);
        $before = $other->fresh()->getAttributes();

        $this->postJson($this->bissBase().'/teachers', $this->payload($other->email, [$this->seventh]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(0, MasjidUser::where('user_id', $other->id)->count());
        $this->assertSame(0, GroupStaff::withoutMasjidScope()->where('user_id', $other->id)->count());
        $this->assertSame($before, $other->fresh()->getAttributes(), 'the row is untouched');
        Mail::assertNothingSent();
    }

    #[Test]
    #[DataProvider('otherKindsOfLogin')]
    public function a_trashed_login_of_any_other_type_is_not_restored_either(string $type): void
    {
        $other = User::factory()->create(['type' => $type, 'phone' => '+15550003333']);
        $other->delete();

        $this->postJson($this->bissBase().'/teachers', $this->payload($other->email, [$this->seventh]))->assertStatus(422);

        $this->assertTrue(User::withTrashed()->find($other->id)->trashed());
        $this->assertSame(0, MasjidUser::where('user_id', $other->id)->count());
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------- T1.6

    #[Test]
    public function with_the_gate_shut_the_attach_is_refused_but_a_new_teacher_still_works(): void
    {
        config(['tenancy.multi_membership' => false]);
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))
            ->assertStatus(422)
            ->assertJsonPath('data.email.0', 'Adding an existing login to a second school is switched off.');

        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->count());
        $this->assertSame(0, GroupStaff::withoutMasjidScope()->where('masjid_id', $this->biss->id)->count());
        Mail::assertNothingSent();

        // Creating a brand-new login is not a cross-organisation grant.
        $this->postJson($this->bissBase().'/teachers', $this->payload('brand.new@example.test', [$this->seventh]))->assertCreated();
        Mail::assertSent(AccountAccessMail::class);
    }

    #[Test]
    public function a_live_teacher_who_belongs_nowhere_is_attached_even_with_the_gate_shut(): void
    {
        // No existing membership means no cross-organisation grant is being made.
        config(['tenancy.multi_membership' => false]);
        $orphan = User::factory()->create(['type' => 'Teacher', 'phone' => '']);

        $this->postJson($this->bissBase().'/teachers', $this->payload($orphan->email, [$this->seventh]))->assertCreated();

        $row = MasjidUser::where('user_id', $orphan->id)->sole();
        $this->assertTrue((bool) $row->is_default, 'their first membership is their default');
    }

    // ---------------------------------------------------------------- T1.7 / H1

    #[Test]
    public function a_retired_teacher_with_no_memberships_is_restored_as_if_new(): void
    {
        $retired = User::factory()->create(['type' => 'Teacher', 'name' => 'Old Name', 'phone' => '+15551110000']);
        $retired->createToken('stale');
        $oldHash = $retired->fresh()->password;
        $retired->delete();

        $response = $this->postJson($this->bissBase().'/teachers', [
            'name' => 'New Name', 'email' => strtoupper($retired->email), 'phone' => '+15552220000',
            'class_ids' => [$this->seventh->id],
        ])->assertCreated();

        $fresh = User::find($retired->id);
        $this->assertNotNull($fresh, 'restored, not a second row (and no unique-index 500)');
        $this->assertNotSame($oldHash, $fresh->password, 'the old password is over');
        $this->assertSame(0, $fresh->tokens()->count(), 'stale sessions are gone');
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('+15552220000', $fresh->phone);
        $this->assertTrue((bool) MasjidUser::where('user_id', $fresh->id)->sole()->is_default);

        // They are effectively new, so they get the set-password invite.
        Mail::assertSent(AccountAccessMail::class);
        Mail::assertNotSent(StaffAddedToOrganisation::class);
        $response->assertJsonPath('data.id', $retired->id);
    }

    #[Test]
    public function a_teacher_a_superadmin_trashed_is_not_restored_by_a_school(): void
    {
        // UsersController::moveToTrash soft-deletes and LEAVES the membership rows;
        // TeachersController::destroy only trashes once none remain. So "trashed
        // with rows" means somebody with authority locked them out on purpose.
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $teacher->createToken('x');
        $teacher->delete();

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertTrue(User::withTrashed()->find($teacher->id)->trashed(), 'still trashed');
        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->count(), 'no membership added');
        $this->assertSame(0, GroupStaff::withoutMasjidScope()->where('masjid_id', $this->biss->id)->count());
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------- T1.8

    #[Test]
    public function adding_the_same_email_twice_at_one_school_is_refused_and_writes_nothing_more(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $payload = $this->payload($teacher->email, [$this->seventh]);

        $this->postJson($this->bissBase().'/teachers', $payload)->assertCreated();
        $this->postJson($this->bissBase().'/teachers', $payload)
            ->assertStatus(422)
            ->assertJsonPath('data.email.0', strtolower($teacher->email).' is already a teacher at this school. Use Edit to change their classes.');

        $this->assertSame(2, MasjidUser::where('user_id', $teacher->id)->count());
        $this->assertSame(1, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->count());
    }

    // ------------------------------------------------------------ T1.9 / H2

    #[Test]
    public function the_create_and_attach_replies_are_indistinguishable_by_message_and_by_data(): void
    {
        $existing = $this->teacherAt($this->alrazi, [$this->alraziClass], ['name' => 'Stored Name', 'phone' => '+15550001111']);

        $attach = $this->postJson($this->bissBase().'/teachers', [
            'name' => 'Typed One', 'email' => $existing->email, 'phone' => '+15559990001', 'class_ids' => [$this->seventh->id],
        ])->assertCreated();

        $create = $this->postJson($this->bissBase().'/teachers', [
            'name' => 'Typed Two', 'email' => 'somebody.new@example.test', 'phone' => '+15559990002', 'class_ids' => [$this->seventh->id],
        ])->assertCreated();

        // Same message shape, with only the typed address differing.
        $this->assertSame('Invitation sent to '.$existing->email.'.', $attach->json('message'));
        $this->assertSame('Invitation sent to somebody.new@example.test.', $create->json('message'));

        // Same keys and the same value TYPES, recursively, in `data`.
        $this->assertSame($this->shape($create->json()), $this->shape($attach->json()));

        // The data is what the inviter TYPED. Nothing another school entered comes back.
        $this->assertSame('Typed One', $attach->json('data.name'));
        $this->assertSame($existing->email, $attach->json('data.email'));
        $body = $attach->getContent();
        $this->assertStringNotContainsString('Stored Name', $body);
        $this->assertStringNotContainsString('+15550001111', $body);
        $this->assertStringNotContainsString('Al-Razi', $body);
    }

    // -------------------------------------------------- privacy on the screens

    #[Test]
    public function a_shared_teacher_shows_every_school_the_phone_and_only_that_schools_last_opened(): void
    {
        // Owner, 2026-09-29: "Seeing their phone number I do not see as a problem", and
        // "they should see when they last opened the specific school instead, not
        // necessarily the last time they logged in". So the phone is shown to both
        // schools, the global sign-in (the newest token) is shown to neither, and each
        // school sees its OWN membership's last_seen_at.
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass], ['name' => 'Stored Name', 'phone' => '+15550001111']);
        $teacher->createToken('phone'); // a sign-in at school A
        $solo = $this->teacherAt($this->biss, [$this->seventh], ['phone' => '+15557778888']);
        $solo->createToken('phone');

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->eighth]))->assertCreated();

        DB::table('masjid_user')->where('user_id', $teacher->id)->where('masjid_id', $this->alrazi->id)->update(['last_seen_at' => '2026-09-01 10:00:00']);
        DB::table('masjid_user')->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->update(['last_seen_at' => '2026-09-02 11:00:00']);
        $alraziIso = \Illuminate\Support\Carbon::parse('2026-09-01 10:00:00')->toIso8601String();
        $bissIso = \Illuminate\Support\Carbon::parse('2026-09-02 11:00:00')->toIso8601String();

        // Teachers list and edit read: the stored phone, for the shared teacher as for anyone.
        $list = $this->getJson($this->bissBase().'/teachers')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $teacher->id);
        $this->assertSame(['id', 'name', 'email', 'phone', 'last_seen_at', 'invited', 'classes'], array_keys($row));
        $this->assertSame('Stored Name', $row['name']);
        $this->assertSame('+15550001111', $row['phone']);
        $this->assertSame($bissIso, $row['last_seen_at'], 'BISS sees when they last opened BISS');

        $show = $this->getJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();
        $show->assertJsonPath('data.shared', true);
        $show->assertJsonPath('data.phone', '+15550001111');
        $show->assertJsonPath('data.last_seen_at', $bissIso);

        // A single-school teacher is unchanged, and has nothing recorded yet.
        $soloShow = $this->getJson($this->bissBase()."/teachers/{$solo->id}")->assertOk();
        $soloShow->assertJsonPath('data.shared', false);
        $soloShow->assertJsonPath('data.phone', '+15557778888');
        $soloShow->assertJsonPath('data.last_seen_at', null);

        // Team & Access: the phone for both, this school's last-opened for both, and no
        // global sign-in key at all.
        $teamResponse = $this->getJson($this->bissBase().'/team')->assertOk();
        $team = collect($teamResponse->json('data.people'))->keyBy('user_id');
        $this->assertSame('+15550001111', $team[$teacher->id]['phone']);
        $this->assertSame($bissIso, $team[$teacher->id]['last_seen_at']);
        $this->assertSame('+15557778888', $team[$solo->id]['phone']);
        $this->assertNull($team[$solo->id]['last_seen_at']);
        $this->assertArrayNotHasKey('last_sign_in_at', $team[$teacher->id]);
        $this->assertStringNotContainsString('2026-09-01', $teamResponse->getContent(), "Al-Razi's value never appears in BISS's screen");

        // The other school sees the phone too, and its OWN value, never BISS's.
        Sanctum::actingAs($this->alraziAdmin, ['staff']);
        $alraziList = $this->getJson($this->alraziBase().'/teachers')->assertOk();
        $alraziRow = collect($alraziList->json('data'))->firstWhere('id', $teacher->id);
        $this->assertSame('+15550001111', $alraziRow['phone']);
        $this->assertSame($alraziIso, $alraziRow['last_seen_at']);
        $this->assertStringNotContainsString('2026-09-02', $alraziList->getContent());
    }

    // ---------------------------------------------------------------- T1.10

    #[Test]
    public function a_shared_teachers_name_and_phone_cannot_be_changed_but_their_classes_can(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass], ['name' => 'Stored Name', 'phone' => '+15550001111']);
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        $url = $this->bissBase()."/teachers/{$teacher->id}";

        // A different name: refused.
        $this->putJson($url, ['name' => 'Renamed By B', 'class_ids' => [$this->seventh->id]])
            ->assertStatus(422)
            ->assertJsonPath('data.name.0', "This teacher's name and phone are shared with another Manara school; ask them or Manara support to change them.");

        // ANY phone: refused (no value comparison, so it cannot be used to guess the stored one).
        $this->putJson($url, ['name' => 'Stored Name', 'phone' => '+15550001111', 'class_ids' => [$this->seventh->id]])->assertStatus(422);
        $this->putJson($url, ['name' => 'Stored Name', 'phone' => '+15550009999', 'class_ids' => [$this->seventh->id]])->assertStatus(422);

        $this->assertSame('Stored Name', $teacher->fresh()->name);
        $this->assertSame('+15550001111', $teacher->fresh()->phone);

        // Classes only (the form sends the unchanged name and no phone): allowed,
        // and school A's assignment is untouched.
        $this->putJson($url, ['name' => 'Stored Name', 'class_ids' => [$this->seventh->id, $this->eighth->id]])->assertOk();
        $this->assertEqualsCanonicalizing(
            [$this->seventh->id, $this->eighth->id],
            GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->pluck('group_id')->map(fn ($i): int => (int) $i)->all()
        );
        $this->assertSame(1, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->alrazi->id)->count());
        $this->assertSame('+15550001111', $teacher->fresh()->phone, 'the phone is still what school A entered');
    }

    #[Test]
    public function a_single_school_teacher_can_still_be_renamed_and_re_phoned(): void
    {
        $solo = $this->teacherAt($this->biss, [$this->seventh], ['name' => 'Before', 'phone' => '+15557778888']);

        $this->putJson($this->bissBase()."/teachers/{$solo->id}", [
            'name' => 'After', 'phone' => '+15556665555', 'class_ids' => [$this->seventh->id],
        ])->assertOk();

        $this->assertSame('After', $solo->fresh()->name);
        $this->assertSame('+15556665555', $solo->fresh()->phone);
    }

    // ---------------------------------------------------------------- T1.11 / M1

    #[Test]
    public function school_b_cannot_send_a_shared_teacher_a_set_password_link(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $teacher->createToken('phone');
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        Mail::fake();

        $this->postJson($this->bissBase()."/teachers/{$teacher->id}/invite")
            ->assertStatus(422)
            ->assertJsonPath('message', 'They already have a Manara login. They can use "Forgot password" on the sign-in page.');

        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $teacher->email)->count());
        $this->assertSame(1, $teacher->fresh()->tokens()->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_single_school_teachers_invite_can_still_be_resent_and_says_how_long_it_lasts(): void
    {
        $solo = $this->teacherAt($this->biss, [$this->seventh]);

        $this->postJson($this->bissBase()."/teachers/{$solo->id}/invite")
            ->assertOk()
            ->assertJsonPath('message', 'An invitation is on its way to '.$solo->email.'. The link works for 7 days.');

        Mail::assertSent(AccountAccessMail::class);
    }

    #[Test]
    public function team_and_access_refuses_to_resend_an_invite_to_a_teacher(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $teacher->createToken('phone');
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        Mail::fake();

        $this->postJson($this->bissBase()."/team/{$teacher->id}/invite")->assertStatus(422);

        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $teacher->email)->count());
        $this->assertSame(1, $teacher->fresh()->tokens()->count());
        Mail::assertNothingSent();

        // ...and it still works for the people it is for.
        $this->postJson($this->bissBase()."/team/{$this->bissAdmin->id}/invite")->assertOk();
        Mail::assertSent(AccountAccessMail::class);
    }

    // ---------------------------------------------------------------- T1.12

    #[Test]
    public function removing_a_teacher_from_one_school_leaves_the_other_school_working(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        $teacher->createToken('phone');

        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        // BISS is gone, Al-Razi is intact, the login and its session remain.
        $this->assertSame([$this->alrazi->id], MasjidUser::where('user_id', $teacher->id)->pluck('masjid_id')->map(fn ($i): int => (int) $i)->all());
        $this->assertSame(0, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->count());
        $this->assertSame(1, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->alrazi->id)->count());
        $this->assertNull(User::withTrashed()->find($teacher->id)->deleted_at);
        $this->assertSame(1, $teacher->fresh()->tokens()->count(), 'sessions are not ended while another school remains');

        // The teacher's next request: BISS is refused, Al-Razi still serves.
        Sanctum::actingAs($teacher->fresh(), ['staff']);
        $this->getJson("/api/teacher/masjids/{$this->biss->id}/groups")
            ->assertForbidden()
            ->assertJsonPath('message', ResolveMasjidTenant::FORBIDDEN_MESSAGE);
        $this->getJson("/api/teacher/masjids/{$this->alrazi->id}/groups")->assertOk()->assertJsonCount(1, 'data');

        // ...and the picker no longer offers BISS.
        $memberships = $this->getJson('/api/teacher/user')->assertOk()->json('data.memberships');
        $this->assertSame([$this->alrazi->id], collect($memberships)->pluck('masjid_id')->map(fn ($i): int => (int) $i)->all());
    }

    #[Test]
    public function removal_works_with_the_gate_shut_because_it_is_how_the_gate_is_closed(): void
    {
        // M4. The rollback runbook is "remove the extra memberships first", so the
        // door that removes them must never consult tenancy.multi_membership
        // (MasjidAdminsController::revokeMembership does, and refuses).
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();

        config(['tenancy.multi_membership' => false]);

        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->count());
        $this->assertSame(0, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->count());
    }

    // ---------------------------------------------------------------- T1.13

    #[Test]
    public function removing_the_default_school_promotes_the_remaining_one(): void
    {
        // The teacher's default is BISS here (their first school); Al-Razi adds
        // them second, so it is the non-default row.
        $teacher = $this->teacherAt($this->biss, [$this->seventh]);
        Sanctum::actingAs($this->alraziAdmin, ['staff']);
        $this->postJson($this->alraziBase().'/teachers', $this->payload($teacher->email, [$this->alraziClass]))->assertCreated();

        $this->assertTrue((bool) MasjidUser::where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->value('is_default'));
        $this->assertFalse((bool) MasjidUser::where('user_id', $teacher->id)->where('masjid_id', $this->alrazi->id)->value('is_default'));

        Sanctum::actingAs($this->bissAdmin, ['staff']);
        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        $remaining = MasjidUser::where('user_id', $teacher->id)->sole();
        $this->assertSame($this->alrazi->id, (int) $remaining->masjid_id);
        $this->assertTrue((bool) $remaining->is_default, 'a person who still belongs somewhere always has a default');
    }

    #[Test]
    public function removing_a_non_default_school_does_not_move_the_default(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();

        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        $this->assertTrue((bool) MasjidUser::where('user_id', $teacher->id)->sole()->is_default);
    }

    // ---------------------------------------------------------------- T1.14

    #[Test]
    public function removing_the_last_membership_retires_the_login_and_ends_its_sessions(): void
    {
        $solo = $this->teacherAt($this->biss, [$this->seventh]);
        $solo->createToken('phone');
        $solo->createToken('laptop');

        $this->deleteJson($this->bissBase()."/teachers/{$solo->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $solo->id]);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $solo->id)->count());
        $this->assertSame(0, MasjidUser::where('user_id', $solo->id)->count());
    }

    #[Test]
    public function a_teacher_who_owns_the_organisation_is_not_removed_from_it_here(): void
    {
        // Same-type teachers cannot own one today; the check exists so the door is
        // already safe when they can (TeamController::destroy says the same).
        $teacher = $this->teacherAt($this->biss, [$this->seventh]);
        Masjid::withoutGlobalScopes()->whereKey($this->biss->id)->update(['user_id' => $teacher->id]);
        // The BISS admin no longer owns it, so with the gate open they need the
        // membership row that ownership used to stand in for.
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $this->bissAdmin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertStatus(409);

        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->count());
    }

    // ------------------------------------------------------------ the owner's case

    #[Test]
    public function the_owners_case_an_alrazi_teacher_is_added_to_biss_islamic_studies_and_can_work_in_both(): void
    {
        // User 15's shape: an Al-Razi teacher, default membership there. A BISS
        // admin invites his address for 7th and 8th grade, Islamic Studies.
        $moneeb = $this->teacherAt($this->alrazi, [$this->alraziClass], ['email' => 'moneeb@hopetechapps.test']);

        $this->postJson($this->bissBase().'/teachers', [
            'name' => 'Moneeb', 'email' => 'moneeb@hopetechapps.test',
            'class_ids' => [$this->seventh->id, $this->eighth->id],
            'class_subjects' => [$this->seventh->id => ['islamic_studies'], $this->eighth->id => ['islamic_studies']],
        ])->assertCreated();

        // He signs in as himself: both schools in the picker, Al-Razi the default.
        Sanctum::actingAs($moneeb->fresh(), ['staff']);
        $memberships = collect($this->getJson('/api/teacher/user')->assertOk()->json('data.memberships'))->keyBy('masjid_id');
        $this->assertSame(['Al-Razi', 'BISS'], [$memberships[$this->alrazi->id]['masjid']['name'], $memberships[$this->biss->id]['masjid']['name']]);

        // He picks BISS: 7th and 8th grade, and only those.
        $groups = $this->getJson("/api/teacher/masjids/{$this->biss->id}/groups")->assertOk();
        $this->assertEqualsCanonicalizing([$this->seventh->id, $this->eighth->id], collect($groups->json('data'))->pluck('id')->all());
        $this->getJson("/api/teacher/masjids/{$this->biss->id}/school")->assertJsonPath('data.name', 'BISS');

        // Al-Razi's classes still list under Al-Razi.
        $this->assertSame([$this->alraziClass->id], collect($this->getJson("/api/teacher/masjids/{$this->alrazi->id}/groups")->json('data'))->pluck('id')->all());

        // Islamic Studies owns no tab: he may not open the Qur'an or Arabic ones.
        $this->getJson("/api/teacher/masjids/{$this->biss->id}/groups/{$this->seventh->id}/hifz")->assertForbidden();
        $this->getJson("/api/teacher/masjids/{$this->biss->id}/groups/{$this->seventh->id}/letters")->assertForbidden();
    }

    // ---------------------------------------------------------------- L1

    #[Test]
    public function the_default_flag_is_derived_from_the_users_rows_never_asserted(): void
    {
        // Behaviour: a person who already has a default gets a non-default row (a
        // hardcoded `true` is a unique-index 500 here); a person with none gets one.
        $withDefault = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $this->postJson($this->bissBase().'/teachers', $this->payload($withDefault->email, [$this->seventh]))->assertCreated();
        $this->assertSame(1, MasjidUser::where('user_id', $withDefault->id)->where('is_default', true)->count());

        $this->postJson($this->bissBase().'/teachers', $this->payload('fresh.face@example.test', [$this->seventh]))->assertCreated();
        $fresh = User::where('email', 'fresh.face@example.test')->firstOrFail();
        $this->assertTrue((bool) MasjidUser::where('user_id', $fresh->id)->sole()->is_default);
    }

    #[Test]
    public function source_pin_the_user_row_is_locked_before_the_default_is_derived(): void
    {
        // A SOURCE PIN, not a concurrency test. SQLite ignores row locks and the
        // suite runs on one connection, so nothing here can show two requests
        // serialising: it shows only that the controller's source takes the lock
        // before it derives `is_default`. Whether two schools attaching the same
        // default-less person at once really serialise on MySQL is Unknown, needs a
        // two-connection test on MySQL (not written). The risk it guards: both
        // would compute "no default" and both write one, and
        // `masjid_user_default_unique` would turn the second into a 500.
        $source = file_get_contents(app_path('Http/Controllers/AdminDashboard/TeachersController.php'));

        $this->assertSame(2, substr_count($source, 'lockForUpdate()'), 'the lookup in store() and the lock in destroy() must both hold the user row');
        $this->assertLessThan(
            strpos($source, "'is_default' => ! MasjidUser::where('user_id', \$user->id)"),
            strpos($source, '->lockForUpdate()'),
            'the lock must be taken before is_default is derived'
        );
    }


    #[Test]
    public function gap_v1_a_second_admin_of_this_school_is_not_editable_removable_or_invitable_as_a_teacher(): void
    {
        $other = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Second Admin', 'phone' => '+15551230000']);
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $other->id, 'role' => 'masjid-admin', 'is_default' => true]);

        $this->putJson($this->bissBase()."/teachers/{$other->id}", ['name' => 'Renamed', 'phone' => '+15559999999', 'class_ids' => [$this->seventh->id]])->assertNotFound();
        $this->postJson($this->bissBase()."/teachers/{$other->id}/invite")->assertNotFound();
        $this->deleteJson($this->bissBase()."/teachers/{$other->id}")->assertNotFound();
        $this->getJson($this->bissBase()."/teachers/{$other->id}")->assertNotFound();

        $this->assertSame('Second Admin', $other->fresh()->name);
        $this->assertNull(User::withTrashed()->find($other->id)->deleted_at);
        $this->assertSame(1, MasjidUser::where('user_id', $other->id)->count());
    }

    #[Test]
    public function gap_d6_removing_a_non_default_school_with_three_schools_keeps_exactly_one_default(): void
    {
        [$gamma, $gammaAdmin] = $this->makeSchoolWithAdmin('Gamma');
        $gammaClass = $this->makeClass($gamma, 'G1');
        $teacher = $this->teacherAt($gamma, [$gammaClass]);          // default = gamma (highest id)
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        Sanctum::actingAs($this->alraziAdmin, ['staff']);
        $this->postJson($this->alraziBase().'/teachers', $this->payload($teacher->email, [$this->alraziClass]))->assertCreated();

        Sanctum::actingAs($this->bissAdmin, ['staff']);
        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->where('is_default', true)->count());
        $this->assertTrue((bool) MasjidUser::where('user_id', $teacher->id)->where('masjid_id', $gamma->id)->value('is_default'));
    }

    #[Test]
    public function gap_d5_removing_the_default_promotes_the_LOWEST_remaining_school(): void
    {
        [$gamma] = $this->makeSchoolWithAdmin('Gamma');
        $gammaClass = $this->makeClass($gamma, 'G1');
        $teacher = $this->teacherAt($this->biss, [$this->seventh]);   // default = biss
        Sanctum::actingAs($this->alraziAdmin, ['staff']);
        $this->postJson($this->alraziBase().'/teachers', $this->payload($teacher->email, [$this->alraziClass]))->assertCreated();
        Sanctum::actingAs(User::find($gamma->user_id), ['staff']);
        $this->postJson("/api/admin/masjids/{$gamma->id}/teachers", $this->payload($teacher->email, [$gammaClass]))->assertCreated();

        Sanctum::actingAs($this->bissAdmin, ['staff']);
        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        $this->assertSame([$this->alrazi->id], MasjidUser::where('user_id', $teacher->id)->where('is_default', true)->pluck('masjid_id')->map(fn ($i): int => (int) $i)->all());
    }

    #[Test]
    public function gap_d4_the_promoted_default_is_never_an_archived_school(): void
    {
        [$gamma] = $this->makeSchoolWithAdmin('Gamma');
        $gammaClass = $this->makeClass($gamma, 'G1');
        $teacher = $this->teacherAt($this->biss, [$this->seventh]);
        Sanctum::actingAs($this->alraziAdmin, ['staff']);
        $this->postJson($this->alraziBase().'/teachers', $this->payload($teacher->email, [$this->alraziClass]))->assertCreated();
        Sanctum::actingAs(User::find($gamma->user_id), ['staff']);
        $this->postJson("/api/admin/masjids/{$gamma->id}/teachers", $this->payload($teacher->email, [$gammaClass]))->assertCreated();
        $this->alrazi->delete(); // archived, lowest id

        Sanctum::actingAs($this->bissAdmin, ['staff']);
        $this->deleteJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk();

        $this->assertTrue((bool) MasjidUser::where('user_id', $teacher->id)->where('masjid_id', $gamma->id)->value('is_default'));
    }

    #[Test]
    public function gap_a1_adding_a_teacher_who_is_already_a_member_here_with_no_classes_is_a_422_not_a_500(): void
    {
        $teacher = $this->teacherAt($this->biss, []);   // membership here, no group_staff
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertStatus(422);
        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->count());
    }

    #[Test]
    public function gap_a2_a_legacy_teacher_with_classes_here_but_no_membership_row_is_already_here(): void
    {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15550004444']);
        $this->seventh->staff()->attach($teacher->id, ['masjid_id' => $this->biss->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->eighth]))->assertStatus(422);
        $this->assertSame(0, MasjidUser::where('user_id', $teacher->id)->count());
    }

    #[Test]
    public function gap_g1_a_person_with_memberships_but_no_default_gets_a_default_on_attach(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        MasjidUser::where('user_id', $teacher->id)->update(['is_default' => false]);

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();

        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->where('is_default', true)->count());
    }

    #[Test]
    public function gap_e3_a_malformed_email_is_refused(): void
    {
        $this->postJson($this->bissBase().'/teachers', $this->payload('not-an-email', [$this->seventh]))->assertStatus(422);
        $this->assertSame(0, User::where('email', 'not-an-email')->count());
    }

    #[Test]
    public function gap_s3_a_failing_mail_transport_after_the_commit_does_not_turn_an_attach_into_a_500(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        $this->assertSame(2, MasjidUser::where('user_id', $teacher->id)->count());
    }

    #[Test]
    public function gap_s4_a_unique_index_loss_on_create_is_retried_once(): void
    {
        $fired = 0;
        User::creating(function (User $u) use (&$fired) {
            if ($u->email === 'race@example.test' && $fired++ === 0) {
                DB::table('users')->insert(['name' => 'Winner', 'email' => 'race@example.test', 'phone' => '', 'type' => 'Teacher', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->postJson($this->bissBase().'/teachers', $this->payload('race@example.test', [$this->seventh]))->assertCreated();
        $this->assertSame(1, User::where('email', 'race@example.test')->count());
    }

    #[Test]
    public function gap_b2_an_archived_other_school_does_not_make_a_teacher_shared(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass], ['phone' => '+15550001111']);
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        $this->alrazi->delete();

        $this->getJson($this->bissBase()."/teachers/{$teacher->id}")->assertOk()->assertJsonPath('data.shared', false)->assertJsonPath('data.phone', '+15550001111');
    }

    #[Test]
    public function gap_u4_a_stored_name_with_stray_spaces_can_still_have_its_classes_edited(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass], ['name' => 'Padded Name ']);
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();

        $this->putJson($this->bissBase()."/teachers/{$teacher->id}", ['name' => 'Padded Name', 'class_ids' => [$this->seventh->id, $this->eighth->id]])->assertOk();
    }

    // ------------------------------------------------- lens fixes: locking and email match

    #[Test]
    public function the_email_match_uses_the_unique_index_on_mysql_and_lower_on_sqlite(): void
    {
        // LOWER(email) on MySQL cannot use users_email_unique, so a locking read
        // on it scans, and locks, the whole users table. utf8mb4_unicode_ci already
        // compares case-insensitively there.
        $mysql = User::query()->whereEmailIs('A@B.test', 'mysql')->toSql();
        $this->assertStringNotContainsStringIgnoringCase('lower(', $mysql);
        $this->assertStringContainsString('"email" = ?', str_replace('`', '"', $mysql));

        $sqlite = User::query()->whereEmailIs('A@B.test', 'sqlite');
        $this->assertStringContainsStringIgnoringCase('lower(email) = ?', $sqlite->toSql());
        $this->assertSame(['a@b.test'], $sqlite->getBindings());
    }

    #[Test]
    public function source_pin_the_lookup_takes_no_lock_runs_outside_the_transaction_and_only_the_found_row_is_locked(): void
    {
        // A SOURCE PIN, not a concurrency test: SQLite ignores row locks and
        // snapshots and the suite has one connection, so it cannot prove any
        // serialisation or deadlock behaviour; it pins what the source says, and the
        // InnoDB behaviour below is reasoned, not measured (Unknown on MySQL).
        // (1) A locking read that matches NOTHING (a brand-new address) takes gap
        // locks on InnoDB, and two schools adding two different new people deadlock at
        // INSERT. (2) A plain read INSIDE the transaction would fix the REPEATABLE READ
        // snapshot before the wait for another school's row lock, so is_default would be
        // derived from rows older than the commit waited for.
        $source = file_get_contents(app_path('Http/Controllers/AdminDashboard/TeachersController.php'));

        // The lookup reads CANDIDATES and re-checks them exactly (ContactIdentity::sameAddress),
        // because users.email is utf8mb4_unicode_ci in production: still no lock, still before
        // the transaction.
        $lookup = strpos($source, "whereEmailIs(\$email)->get(['id', 'email'])");
        $this->assertNotFalse($lookup, 'the email lookup must take no lock');
        $this->assertDoesNotMatchRegularExpression('/whereEmailIs\([^;]*lockForUpdate/s', $source);
        $this->assertLessThan(strpos($source, 'return DB::transaction(function () use ($request, $masjidId, $classes, $email, $foundId)'), $lookup, 'the lookup must run BEFORE the transaction opens');
        $this->assertMatchesRegularExpression('/whereKey\(\$foundId\)->lockForUpdate\(\)/', $source, 'the found row is locked by key');
    }

    #[Test]
    public function a_deadlock_victim_on_create_is_retried_once_not_a_500(): void
    {
        $fired = 0;
        User::creating(function (User $u) use (&$fired) {
            if ($u->email === 'deadlock@example.test' && $fired++ === 0) {
                throw new QueryException(
                    'mysql',
                    'insert into `users` (`email`) values (?)',
                    ['deadlock@example.test'],
                    new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
                );
            }
        });

        $this->postJson($this->bissBase().'/teachers', $this->payload('deadlock@example.test', [$this->seventh]))->assertCreated();

        $this->assertSame(1, User::where('email', 'deadlock@example.test')->count());
        $this->assertSame(2, $fired, 'exactly one retry');
    }

    #[Test]
    public function any_other_database_error_on_create_is_not_swallowed_by_the_retry(): void
    {
        User::creating(function (User $u) {
            if ($u->email === 'broken@example.test') {
                throw new QueryException('mysql', 'insert', [], new \PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error'));
            }
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        $this->postJson($this->bissBase().'/teachers', $this->payload('broken@example.test', [$this->seventh]));
    }

    // ------------------------------------------------- lens fixes: Team & Access payload

    #[Test]
    public function team_and_access_marks_a_shared_teacher_and_shows_this_schools_last_opened(): void
    {
        $teacher = $this->teacherAt($this->alrazi, [$this->alraziClass]);
        $teacher->createToken('phone');
        $this->postJson($this->bissBase().'/teachers', $this->payload($teacher->email, [$this->seventh]))->assertCreated();
        $solo = $this->teacherAt($this->biss, [$this->eighth]);

        $team = collect($this->getJson($this->bissBase().'/team')->assertOk()->json('data.people'))->keyBy('user_id');

        $this->assertTrue($team[$teacher->id]['shared']);
        $this->assertFalse($team[$solo->id]['shared']);
        // A token is a sign-in at ANY school, so it never becomes this school's date: nothing
        // has opened BISS as this teacher yet, whatever they did at Al-Razi.
        $this->assertNull($team[$teacher->id]['last_seen_at']);
        $this->assertNull($team[$solo->id]['last_seen_at']);
        $this->assertArrayNotHasKey('last_sign_in_at', $team[$teacher->id]);
    }

    #[Test]
    public function gap_b5_a_second_school_does_not_hide_an_administrators_phone_and_shows_only_this_offices_last_opened(): void
    {
        // An administrator of two offices is the offices' own colleague: the phone is
        // shown (as it now is for a shared teacher), and each office sees its own
        // membership's last-opened.
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550005555']);
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        MasjidUser::create(['masjid_id' => $this->alrazi->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => false]);
        DB::table('masjid_user')->where('user_id', $admin->id)->where('masjid_id', $this->biss->id)->update(['last_seen_at' => '2026-09-03 09:00:00']);
        DB::table('masjid_user')->where('user_id', $admin->id)->where('masjid_id', $this->alrazi->id)->update(['last_seen_at' => '2026-09-04 09:00:00']);

        $response = $this->getJson($this->bissBase().'/team')->assertOk();
        $row = collect($response->json('data.people'))->firstWhere('user_id', $admin->id);

        $this->assertSame('+15550005555', $row['phone']);
        $this->assertSame(\Illuminate\Support\Carbon::parse('2026-09-03 09:00:00')->toIso8601String(), $row['last_seen_at']);
        $this->assertFalse($row['shared']);
        $this->assertStringNotContainsString('2026-09-04', $response->getContent());
    }

    // ---------------------------------------------------------------- helpers

    private function bissBase(): string
    {
        return "/api/admin/masjids/{$this->biss->id}";
    }

    private function alraziBase(): string
    {
        return "/api/admin/masjids/{$this->alrazi->id}";
    }

    /** @param  list<Group>  $classes */
    private function payload(string $email, array $classes): array
    {
        return [
            'name' => 'Typed Name',
            'email' => $email,
            'class_ids' => array_map(fn (Group $g): int => (int) $g->id, $classes),
        ];
    }

    /**
     * A live Teacher with a default membership at $school and the given classes.
     *
     * @param  list<Group>  $classes
     */
    private function teacherAt(Masjid $school, array $classes, array $overrides = []): User
    {
        $teacher = User::factory()->create(array_merge([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ], $overrides));

        MasjidUser::create([
            'masjid_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true,
        ]);

        foreach ($classes as $class) {
            $class->staff()->attach($teacher->id, [
                'masjid_id' => $school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
            ]);
        }

        return $teacher;
    }

    /** Keys and value TYPES of a decoded JSON tree, dropping the values that legitimately differ. */
    private function shape(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(fn ($v) => $this->shape($v), $node);
        }

        return gettype($node);
    }

    private function makeClass(Masjid $school, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $school->id, 'kind' => Group::KIND_CLASS,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name),
        ]);
    }

    /** @return array{0: Masjid, 1: User} */
    private function makeSchoolWithAdmin(string $name): array
    {
        $school = Masjid::create([
            'name' => $name,
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $school->user_id = $admin->id;
        $school->save();

        return [$school, $admin];
    }
}
