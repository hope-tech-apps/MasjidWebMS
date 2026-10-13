<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Mail\StaffAddedToOrganisation;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\Groups\ScheduledSendGate;
use App\Services\Schools\AttendanceRegister;
use App\Support\CapabilityWriter;
use App\Support\OrganisationAccess;
use App\Support\TenantContext;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OneLoginWorld;
use Tests\TestCase;

/**
 * The office doors, for a person who is to hold a role at each of two organisations.
 *
 * Two doors attach an existing login of the OTHER scoped kind, and only while the
 * roles switch is on: the Teachers door takes a live lunch-staff login, and the
 * lunch-staff door takes a live teacher's. Each writes one `masjid_user` row at its
 * own organisation and nothing else: not `users` (the kind the login was created
 * with stays), not the password, not a session. The person is told they were added.
 *
 * One test per row of the two branch tables (TeachersController, LunchStaffController),
 * each asserting the sentence, the rows written and the mail sent or not sent. Then
 * the screens that list and act on PEOPLE, which must go by what a person is at
 * THIS organisation: the two doors' own lists, Team & Access, Administrators, the
 * SuperAdmin's access list, the timetable's teacher list, the attendance marker and
 * the scheduled-send gate.
 *
 * An administrator's address stays refused at both doors, with the sentences they
 * always had. That is a decision, not an omission: one login has one global set of
 * permissions (spatie teams are off), so an administrator made a teacher at another
 * school would carry `manage contacts` into its classes.
 *
 * The fixtures open `tenancy.multi_membership`, as TeacherAttachTest does: attaching
 * a person who already belongs somewhere is a new cross-organisation grant, and the
 * tests of that gate set it shut themselves.
 */
class OneLoginTwoRolesDoorsTest extends TestCase
{
    use OneLoginWorld;
    use RefreshDatabase;

    private const TAKEN = ['status' => 'failed', 'data' => ['email' => ['The email has already been taken.']]];

    private const CANNOT_ADD = 'This email already has a Manara login that can\'t be added as a teacher. Contact Manara support.';

    /** A lunch login of the masjid and nothing else, and a teacher of the school and nothing else. */
    private User $kitchen;

    private User $tutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        config(['tenancy.roles_per_organisation' => true, 'tenancy.multi_membership' => true]);

        $this->buildOneLoginWorld();

        $this->kitchen = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Stored Kitchen Name');
        $this->giveRole($this->kitchen, $this->masjid, 'lunch-staff', true);

        $this->tutor = $this->makeLogin('Teacher', 'Stored Tutor Name');
        $this->giveRole($this->tutor, $this->school, 'teacher', true);
        $this->lead($this->tutor, $this->otherClass);

        app(TenantContext::class)->forgetTenant();

        Mail::fake();
    }

    // ============================================================ the Teachers door

    #[Test]
    public function the_teachers_door_gives_a_lunch_login_the_teacher_role_here_and_writes_nothing_else(): void
    {
        $this->kitchen->createToken('phone');
        $before = $this->kitchen->fresh()->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $response = $this->postJson($this->adminBase($this->school).'/teachers', [
            'name' => 'Typed Name',
            'email' => $this->kitchen->email,
            'phone' => '+15559990000',
            'class_ids' => [$this->class->id],
        ])->assertCreated();

        // The one sentence every success answers with, and data built from what was
        // TYPED: neither says the address already had a login.
        $response->assertJsonPath('message', 'Invitation sent to '.$this->kitchen->email.'.')
            ->assertJsonPath('data.id', $this->kitchen->id)
            ->assertJsonPath('data.name', 'Typed Name');

        // One new row: a teacher's, at the school, and NOT the default.
        $rows = MasjidUser::where('user_id', $this->kitchen->id)->orderBy('masjid_id')->get();
        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff', true], [$this->school->id, 'teacher', false]],
            $rows->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );
        $this->assertSame([$this->class->id], $this->classesLedBy($this->kitchen, $this->school));

        // `users` is not written at all: the kind it was created with, its name, its
        // phone, its password, its sessions.
        $this->assertSame($before, $this->kitchen->fresh()->getAttributes(), 'the users row is untouched by an attach');
        $this->assertSame(User::TYPE_LUNCH_STAFF, $this->kitchen->fresh()->homeType());
        $this->assertSame(['lunch-staff'], $this->kitchen->fresh()->getRoleNames()->all(), 'and its one bridged role did not move');
        $this->assertSame(1, $this->kitchen->tokens()->count());

        // Told they were added. No password link, no set-password token.
        Mail::assertNotSent(AccountAccessMail::class);
        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $this->kitchen->email)->count());
        Mail::assertSent(StaffAddedToOrganisation::class, function (StaffAddedToOrganisation $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Use the switch at the top of the page', $html);
            $this->assertStringNotContainsString('school menu', $html);
            $this->assertStringNotContainsString('token=', $html);

            return $mail->hasTo($this->kitchen->email)
                && $mail->orgName === 'Al-Razi School'
                && $mail->roleLabel === 'teacher'
                && $mail->classNames === ['Library']
                && $mail->otherRole === true;
        });

        // And it works: one login, both areas, each at its own organisation only.
        $this->actingAsPerson($this->kitchen);
        $this->getJson($this->teacherBase($this->school).'/groups')->assertOk()->assertJsonPath('data.0.id', $this->class->id);
        $this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();
        $this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus')->assertForbidden();
        $this->getJson($this->teacherBase($this->masjid).'/groups')->assertForbidden();
    }

    #[Test]
    public function the_teachers_door_does_the_same_at_a_school_that_uses_class_subjects(): void
    {
        // The school's other code path (its methods carry a `WithClassSubjects`
        // suffix). The cross-kind attach is written in that path once and reached
        // from both, so a school with class subjects ON must behave the same.
        $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--enable' => true])->assertSuccessful();
        app(TenantContext::class)->forgetTenant();

        $this->actingAsPerson($this->schoolAdmin);

        $this->postJson($this->adminBase($this->school).'/teachers', [
            'name' => 'Typed Name', 'email' => $this->kitchen->email, 'class_ids' => [$this->class->id],
            // A school with class subjects must say which she teaches; null is "all".
            'class_subject_ids' => [$this->class->id => null],
        ])->assertCreated()->assertJsonPath('message', 'Invitation sent to '.$this->kitchen->email.'.');

        $this->assertSame('teacher', MasjidUser::where('masjid_id', $this->school->id)->where('user_id', $this->kitchen->id)->value('role'));
        $this->assertSame(User::TYPE_LUNCH_STAFF, $this->kitchen->fresh()->homeType());
        Mail::assertSent(StaffAddedToOrganisation::class, fn (StaffAddedToOrganisation $mail) => $mail->otherRole === true);
        Mail::assertNotSent(AccountAccessMail::class);
    }

    #[Test]
    public function with_the_switch_off_the_teachers_door_refuses_a_lunch_login_as_it_always_did(): void
    {
        config(['tenancy.roles_per_organisation' => false]);
        $before = $this->kitchen->fresh()->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $this->addTeacher($this->school, $this->kitchen->email)
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'message' => self::CANNOT_ADD, 'data' => ['email' => [self::CANNOT_ADD]]]);

        $this->assertNothingWasWrittenFor($this->kitchen, $before, rows: 1);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function loginsTheTeachersDoorNeverAttaches(): array
    {
        return [
            'an administrator' => ['MasjidAdmin', false],
            'an archived administrator' => ['MasjidAdmin', true],
            'a SuperAdmin' => ['SuperAdmin', false],
            'an archived SuperAdmin' => ['SuperAdmin', true],
            'a member' => ['User', false],
            'an archived member' => ['User', true],
            // A lunch login that was retired is not brought back as a teacher.
            'an archived lunch login' => [User::TYPE_LUNCH_STAFF, true],
        ];
    }

    #[Test]
    #[DataProvider('loginsTheTeachersDoorNeverAttaches')]
    public function the_teachers_door_still_refuses_every_other_login_with_the_switch_on(string $type, bool $archived): void
    {
        $other = $this->makeLogin($type, 'Somebody Else');
        if ($archived) {
            $other->delete();
        }
        $before = User::withTrashed()->findOrFail($other->id)->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $this->addTeacher($this->school, $other->email)
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'message' => self::CANNOT_ADD, 'data' => ['email' => [self::CANNOT_ADD]]]);

        $this->assertNothingWasWrittenFor($other, $before, rows: 0);
    }

    #[Test]
    public function the_teachers_door_refuses_a_lunch_login_that_runs_lunch_at_this_same_organisation(): void
    {
        // The school has Friday lunch and its own lunch person. One login holds ONE
        // role at an organisation: `masjid_user` has one row per person per place.
        $schoolKitchen = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'School Kitchen');
        $this->giveRole($schoolKitchen, $this->school, 'lunch-staff', true);
        app(TenantContext::class)->forgetTenant();
        $before = $schoolKitchen->fresh()->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $sentence = $schoolKitchen->email.' already has Friday lunch access here. One login can hold one role at an organisation.';

        $this->addTeacher($this->school, $schoolKitchen->email)
            ->assertStatus(422)
            ->assertJsonPath('data.email.0', $sentence);

        $this->assertNothingWasWrittenFor($schoolKitchen, $before, rows: 1);
        $this->assertSame('lunch-staff', MasjidUser::where('user_id', $schoolKitchen->id)->value('role'));
    }

    #[Test]
    public function the_teachers_door_tells_a_lunch_login_already_teaching_here_to_use_edit(): void
    {
        $this->actingAsPerson($this->schoolAdmin);

        $this->addTeacher($this->school, $this->shamina->email)
            ->assertStatus(422)
            ->assertJsonPath('data.email.0', $this->shamina->email.' is already a teacher at this school. Use Edit to change their classes.');

        $this->assertSame(2, MasjidUser::where('user_id', $this->shamina->id)->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function with_the_membership_gate_shut_the_teachers_door_answers_a_lunch_login_as_it_always_did(): void
    {
        // The same gate that stops a teacher being added to a second school: this is
        // a new cross-organisation grant too, so with the gate shut it is not made.
        // And it is refused in the words this door always had for a lunch login:
        // "switched off" would tell a school that the address is one the door
        // attaches, which every other login that cannot be added is not told.
        config(['tenancy.multi_membership' => false]);
        $before = $this->kitchen->fresh()->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $this->addTeacher($this->school, $this->kitchen->email)
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'message' => self::CANNOT_ADD, 'data' => ['email' => [self::CANNOT_ADD]]]);

        $this->assertNothingWasWrittenFor($this->kitchen, $before, rows: 1);
        Mail::assertNothingSent();

        // A teacher from another school keeps the sentence that gate always gave a
        // teacher: nothing about the existing two-school path moved.
        $elsewhereTeacher = $this->makeLogin('Teacher', 'Teacher Of Elsewhere');
        $this->giveRole($elsewhereTeacher, $this->elsewhere, 'teacher', true);
        app(TenantContext::class)->forgetTenant();
        $this->actingAsPerson($this->schoolAdmin);

        $this->addTeacher($this->school, $elsewhereTeacher->email)
            ->assertStatus(422)
            ->assertJsonPath('data.email.0', 'Adding an existing login to a second school is switched off.');
    }

    #[Test]
    public function the_teachers_screen_lists_edits_and_removes_her_like_any_teacher(): void
    {
        $this->actingAsPerson($this->schoolAdmin);
        $base = $this->adminBase($this->school).'/teachers';

        // Listed, with her class.
        $listed = collect($this->getJson($base)->assertOk()->json('data'))->firstWhere('id', $this->shamina->id);
        $this->assertNotNull($listed, 'the school\'s own teacher is on its Teachers screen');
        $this->assertSame(['Library'], array_column($listed['classes'], 'name'));

        // The edit form opens, and says her name and phone are shared.
        $this->getJson("{$base}/{$this->shamina->id}")->assertOk()
            ->assertJsonPath('data.shared', true)
            ->assertJsonPath('data.class_ids', [$this->class->id]);

        // Her classes can be changed; her name, which another organisation also holds, cannot.
        $this->putJson("{$base}/{$this->shamina->id}", ['name' => $this->shamina->name, 'class_ids' => [$this->class->id, $this->otherClass->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$this->class->id, $this->otherClass->id], $this->classesLedBy($this->shamina, $this->school));
        $this->putJson("{$base}/{$this->shamina->id}", ['name' => 'Renamed By The School', 'class_ids' => [$this->class->id]])->assertStatus(422);
        $this->assertSame('Shamina Lunch First', $this->shamina->fresh()->name);

        // No set-password link for a person who has a password and another place to be signed in.
        $this->postJson("{$base}/{$this->shamina->id}/invite")->assertStatus(422);
        Mail::assertNotSent(AccountAccessMail::class);

        // A lunch login with no teacher row here is still nobody on this screen.
        $this->getJson("{$base}/{$this->kitchen->id}")->assertNotFound();
        $this->deleteJson("{$base}/{$this->kitchen->id}")->assertNotFound();
        $this->assertSame(1, MasjidUser::where('user_id', $this->kitchen->id)->count());
    }

    #[Test]
    public function a_teacher_who_runs_lunch_at_the_masjid_is_not_a_teacher_on_the_masjids_teachers_screen(): void
    {
        // The mirror, from the other side. His row at the masjid is a lunch row, and
        // the masjid's Teachers screen addresses people by id.
        $this->actingAsPerson($this->masjidAdmin);
        $base = $this->adminBase($this->masjid).'/teachers';
        $lunchClass = $this->masjidClass();

        $this->getJson("{$base}/{$this->mirror->id}")->assertNotFound();
        $this->putJson("{$base}/{$this->mirror->id}", ['name' => 'Renamed', 'class_ids' => [$lunchClass->id]])->assertNotFound();
        $this->postJson("{$base}/{$this->mirror->id}/invite")->assertNotFound();
        $this->deleteJson("{$base}/{$this->mirror->id}")->assertNotFound();

        $this->assertSame('Mirror Teacher First', $this->mirror->fresh()->name);
        $this->assertSame(2, MasjidUser::where('user_id', $this->mirror->id)->count(), 'his lunch row was not removed through the Teachers screen');
        $this->assertSame([], $this->classesLedBy($this->mirror, $this->masjid));
        Mail::assertNothingSent();
    }

    // ========================================================= the lunch-staff door

    #[Test]
    public function the_lunch_door_creates_a_new_login_as_before_and_answers_with_the_one_sentence(): void
    {
        $this->actingAsPerson($this->masjidAdmin);

        $response = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', [
            'name' => 'Umm Khalid', 'email' => 'new-kitchen@example.invalid', 'phone' => '+17045550123',
        ])->assertCreated();

        $created = User::where('email', 'new-kitchen@example.invalid')->firstOrFail();

        $response->assertExactJson([
            'status' => 'success',
            'message' => 'Lunch access added for new-kitchen@example.invalid. They have been emailed how to sign in.',
            'data' => ['id' => $created->id, 'name' => 'Umm Khalid', 'email' => 'new-kitchen@example.invalid', 'phone' => '+17045550123', 'invited' => true],
        ]);

        $this->assertSame(User::TYPE_LUNCH_STAFF, $created->homeType());
        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff', true]],
            MasjidUser::where('user_id', $created->id)->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );
        Mail::assertSent(AccountAccessMail::class);
        Mail::assertNotSent(StaffAddedToOrganisation::class);
    }

    #[Test]
    public function the_lunch_door_gives_a_teacher_lunch_access_here_and_writes_nothing_else(): void
    {
        $this->tutor->createToken('phone');
        $before = $this->tutor->fresh()->getAttributes();

        $this->actingAsPerson($this->masjidAdmin);

        $response = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', [
            'name' => 'Typed Name', 'email' => $this->tutor->email, 'phone' => '+15559990000',
        ])->assertCreated();

        // The SAME sentence as a brand-new login gets, and what was typed: the reply
        // does not say the address had a login, and hands over no stored name or phone.
        $response->assertExactJson([
            'status' => 'success',
            'message' => 'Lunch access added for '.$this->tutor->email.'. They have been emailed how to sign in.',
            'data' => ['id' => $this->tutor->id, 'name' => 'Typed Name', 'email' => $this->tutor->email, 'phone' => '+15559990000', 'invited' => true],
        ]);

        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff', false], [$this->school->id, 'teacher', true]],
            MasjidUser::where('user_id', $this->tutor->id)->orderBy('masjid_id')->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );

        $this->assertSame($before, $this->tutor->fresh()->getAttributes(), 'the users row is untouched by an attach');
        $this->assertSame('Teacher', $this->tutor->fresh()->homeType());
        $this->assertSame(['teacher'], $this->tutor->fresh()->getRoleNames()->all());
        $this->assertSame(1, $this->tutor->tokens()->count());

        Mail::assertNotSent(AccountAccessMail::class);
        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $this->tutor->email)->count());
        Mail::assertSent(StaffAddedToOrganisation::class, function (StaffAddedToOrganisation $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('added you as a member of the Friday lunch team', $html);
            $this->assertStringContainsString('Use the switch at the top of the page', $html);
            $this->assertStringNotContainsString('token=', $html);

            return $mail->hasTo($this->tutor->email)
                && $mail->orgName === 'Burlington Masjid'
                && $mail->classNames === []
                && $mail->otherRole === true;
        });

        $this->actingAsPerson($this->tutor);
        $this->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();
        $this->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        $this->getJson($this->teacherBase($this->masjid).'/groups')->assertForbidden();
        $this->getJson($this->lunchBase($this->school).'/jummah-lunch/menus')->assertForbidden();
    }

    #[Test]
    public function with_the_switch_off_the_lunch_door_is_exactly_what_it_was(): void
    {
        config(['tenancy.roles_per_organisation' => false]);
        $before = $this->tutor->fresh()->getAttributes();

        $this->actingAsPerson($this->masjidAdmin);

        // A teacher's address: taken, by validation, like every address with a login.
        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->tutor->email])
            ->assertStatus(422)
            ->assertExactJson(self::TAKEN);
        $this->assertNothingWasWrittenFor($this->tutor, $before, rows: 1);

        // A new address: today's sentence, and today's row.
        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Umm Khalid', 'email' => 'kitchen-off@example.invalid'])
            ->assertCreated()
            ->assertJsonPath('message', 'Lunch access created. An invitation to set their password is on its way to kitchen-off@example.invalid.');

        $created = User::where('email', 'kitchen-off@example.invalid')->firstOrFail();
        $this->assertTrue((bool) MasjidUser::where('user_id', $created->id)->where('role', 'lunch-staff')->value('is_default'));
    }

    #[Test]
    public function the_lunch_door_refuses_a_teacher_of_this_same_organisation(): void
    {
        // The school has Friday lunch. Its own teacher cannot also be its lunch staff
        // with one login: one row, one role, per organisation.
        $before = $this->tutor->fresh()->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $this->postJson($this->adminBase($this->school).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->tutor->email])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['email' => [
                $this->tutor->email.' is already a teacher at this organisation. One login can hold one role at an organisation.',
            ]]]);

        $this->assertNothingWasWrittenFor($this->tutor, $before, rows: 1);
        $this->assertSame('teacher', MasjidUser::where('user_id', $this->tutor->id)->value('role'));
    }

    #[Test]
    public function with_the_membership_gate_shut_the_lunch_door_says_taken_as_it_does_for_every_address_with_a_login(): void
    {
        // With the gate shut a person who belongs somewhere is not attached. Before
        // one login could hold two roles this door said "taken" for EVERY address
        // with a login. A sentence of its own here ("...is switched off") told any
        // office with Friday lunch that the address was a teacher's somewhere,
        // without attaching anything and without the person being emailed.
        config(['tenancy.multi_membership' => false]);

        $this->actingAsPerson($this->masjidAdmin);

        $anAdministrators = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->schoolAdmin->email])
            ->assertStatus(422);
        $this->assertSame(self::TAKEN, $anAdministrators->json());

        // A teacher of a school, and a person created as lunch staff who now only
        // teaches (her lunch access here was removed): the same bytes.
        MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->shamina->id)->delete();

        foreach ([$this->tutor, $this->shamina] as $belongsSomewhere) {
            $before = $belongsSomewhere->fresh()->getAttributes();

            $refused = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $belongsSomewhere->email])
                ->assertStatus(422);

            $this->assertSame($anAdministrators->getContent(), $refused->getContent(), "{$belongsSomewhere->name}: not one byte says what kind of login the address has");
            $this->assertNothingWasWrittenFor($belongsSomewhere, $before, rows: 1);
        }

        Mail::assertNothingSent();

        // A login that belongs NOWHERE crosses no organisation, so it needs no gate
        // (the meaning the gate has at the Teachers door).
        $nowhere = $this->makeLogin('Teacher', 'Teacher Of Nowhere');
        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $nowhere->email])->assertCreated();
    }

    /** @return array<string, array{0: string, 1: bool, 2: bool}> the kind, whether archived, whether it runs lunch at another organisation */
    public static function loginsTheLunchDoorNeverAttaches(): array
    {
        return [
            // A lunch login that still runs lunch somewhere: one login, lunch at ONE organisation.
            'lunch staff of another organisation' => [User::TYPE_LUNCH_STAFF, false, true],
            'archived lunch staff' => [User::TYPE_LUNCH_STAFF, true, false],
            'an administrator' => ['MasjidAdmin', false, false],
            'an archived administrator' => ['MasjidAdmin', true, false],
            'a SuperAdmin' => ['SuperAdmin', false, false],
            'a member' => ['User', false, false],
            // A retired teacher is not brought back as lunch staff.
            'an archived teacher' => ['Teacher', true, false],
        ];
    }

    #[Test]
    #[DataProvider('loginsTheLunchDoorNeverAttaches')]
    public function the_lunch_door_tells_every_other_login_the_address_is_taken_in_validations_own_words(string $type, bool $archived, bool $runsLunchElsewhere): void
    {
        $other = $this->makeLogin($type, 'Somebody Else');
        if ($runsLunchElsewhere) {
            $this->giveRole($other, $this->elsewhere, 'lunch-staff', true);
            app(TenantContext::class)->forgetTenant();
        }
        if ($archived) {
            $other->delete();
        }
        $before = User::withTrashed()->findOrFail($other->id)->getAttributes();

        $this->actingAsPerson($this->masjidAdmin);

        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $other->email])
            ->assertStatus(422)
            ->assertExactJson(self::TAKEN);

        $this->assertNothingWasWrittenFor($other, $before, rows: $runsLunchElsewhere ? 1 : 0);
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function lunch_access_taken_from_a_person_who_also_teaches_can_be_given_back_at_the_lunch_door(string $who): void
    {
        // Removing lunch access from a person who also teaches does not retire their
        // login: it is their teacher's login too. For a login CREATED as lunch staff
        // (the real case) that used to be one-way: the lunch door answered "taken"
        // for every lunch-staff login and Team & Access refuses every address that
        // has one, so the masjid could not put back what it had removed, by a
        // rotation or by a wrong click. Only a hand-written row could.
        $token = $this->{$who}->createToken('login-token', ['staff'])->plainTextToken;
        $staff = $this->adminBase($this->masjid).'/jummah-lunch/staff';

        $this->actingAsPerson($this->masjidAdmin);
        $this->deleteJson("{$staff}/".$this->{$who}->id)->assertOk();
        $this->assertContains($this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->getStatusCode(), [401, 403]);

        $before = $this->{$who}->fresh()->getAttributes();
        Mail::fake();

        $this->actingAsPerson($this->masjidAdmin);
        $this->postJson($staff, ['name' => 'Typed Name', 'email' => $this->{$who}->email, 'phone' => '+15559990000'])
            ->assertCreated()
            ->assertExactJson([
                'status' => 'success',
                'message' => 'Lunch access added for '.$this->{$who}->email.'. They have been emailed how to sign in.',
                'data' => ['id' => $this->{$who}->id, 'name' => 'Typed Name', 'email' => $this->{$who}->email, 'phone' => '+15559990000', 'invited' => true],
            ]);

        // One row back, a lunch row, here; the school's row is still the default the
        // removal made it. `users` is not written: not the kind the login was created
        // as, not the name or phone the office typed, not the password, not a session.
        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff', false], [$this->school->id, 'teacher', true]],
            MasjidUser::where('user_id', $this->{$who}->id)->orderBy('masjid_id')->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );
        $this->assertSame($before, $this->{$who}->fresh()->getAttributes(), 'the users row is untouched by the attach');
        $this->assertSame(1, $this->{$who}->tokens()->count());

        // Told they were added, with no password link: they have a password.
        Mail::assertNotSent(AccountAccessMail::class);
        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $this->{$who}->email)->count());
        Mail::assertSent(StaffAddedToOrganisation::class, fn (StaffAddedToOrganisation $mail) => $mail->hasTo($this->{$who}->email)
            && $mail->orgName === 'Burlington Masjid'
            && $mail->roleLabel === 'member of the Friday lunch team'
            && $mail->otherRole === true);

        // The session she already had opens the board again, and both areas are hers.
        $this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();
        $this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        $this->withBearer($token)->getJson($this->lunchBase($this->school).'/jummah-lunch/menus')->assertForbidden();
        $this->assertCount(2, $this->withBearer($token)->getJson('/api/lunch/user')->assertOk()->json('data.areas'));

        // Still one role at an organisation, and lunch at one organisation: the
        // school she teaches at cannot also make her its lunch staff, and a second
        // masjid is told what every taken address is told.
        $this->actingAsPerson($this->schoolAdmin);
        $this->postJson($this->adminBase($this->school).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->{$who}->email])
            ->assertStatus(422)
            ->assertExactJson(self::TAKEN);

        $elsewhereAdmin = $this->makeOwnerOf($this->elsewhere);
        app(TenantContext::class)->forgetTenant();
        $this->actingAsPerson($elsewhereAdmin);
        $this->postJson($this->adminBase($this->elsewhere).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->{$who}->email])
            ->assertStatus(422)
            ->assertExactJson(self::TAKEN);

        $this->assertSame(2, MasjidUser::where('user_id', $this->{$who}->id)->count());
    }

    #[Test]
    public function a_person_who_only_teaches_is_told_she_is_a_teacher_here_when_her_own_school_tries_to_make_her_its_lunch_staff(): void
    {
        // Created as lunch staff, lunch access removed, still the school's teacher.
        // The school has Friday lunch; one login holds ONE role at an organisation.
        MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->shamina->id)->delete();
        $before = $this->shamina->fresh()->getAttributes();

        $this->actingAsPerson($this->schoolAdmin);

        $this->postJson($this->adminBase($this->school).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->shamina->email])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['email' => [
                $this->shamina->email.' is already a teacher at this organisation. One login can hold one role at an organisation.',
            ]]]);

        $this->assertNothingWasWrittenFor($this->shamina, $before, rows: 1);
        $this->assertSame('teacher', MasjidUser::where('user_id', $this->shamina->id)->value('role'));
        Mail::assertNothingSent();
    }

    #[Test]
    public function with_the_switch_off_a_lunch_login_that_only_teaches_is_still_told_taken(): void
    {
        // The give-back above is part of the feature. Switched off, the door is the
        // create it always was and validation refuses every address with a login.
        MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->shamina->id)->delete();
        config(['tenancy.roles_per_organisation' => false]);
        $before = $this->shamina->fresh()->getAttributes();

        $this->actingAsPerson($this->masjidAdmin);

        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->shamina->email])
            ->assertStatus(422)
            ->assertExactJson(self::TAKEN);

        $this->assertNothingWasWrittenFor($this->shamina, $before, rows: 1);
    }

    #[Test]
    public function a_teacher_who_already_runs_lunch_somewhere_is_told_the_same_and_the_two_bodies_cannot_be_told_apart(): void
    {
        // The mirror already runs lunch at the masjid. Validation looks past a live
        // teacher's or lunch-staff login, so THIS refusal is the controller's; a
        // login runs lunch at one organisation. It must be byte for byte what
        // validation says of any taken address (an administrator's, here), or the
        // difference would say "this one is a teacher somewhere".
        $before = $this->mirror->fresh()->getAttributes();

        $this->actingAsPerson($this->masjidAdmin);
        $fromTheController = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->mirror->email])
            ->assertStatus(422);
        $fromValidation = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->schoolAdmin->email])
            ->assertStatus(422);

        $this->assertSame(self::TAKEN, $fromValidation->json());
        $this->assertSame($fromValidation->json(), $fromTheController->json());
        $this->assertSame($fromValidation->getContent(), $fromTheController->getContent());

        // The masjid's own lunch person, and a person created as lunch staff who
        // runs lunch here: the controller's too now, and the same bytes again.
        foreach ([$this->kitchen, $this->shamina] as $runsLunchHere) {
            $again = $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $runsLunchHere->email])
                ->assertStatus(422);
            $this->assertSame($fromValidation->getContent(), $again->getContent(), $runsLunchHere->name);
        }

        // And at a second organisation that has lunch: still one lunch organisation.
        $this->actingAsPerson($this->schoolAdmin);
        $elsewhereAdmin = $this->makeOwnerOf($this->elsewhere);
        app(TenantContext::class)->forgetTenant();
        $this->actingAsPerson($elsewhereAdmin);
        $this->postJson($this->adminBase($this->elsewhere).'/jummah-lunch/staff', ['name' => 'Typed Name', 'email' => $this->mirror->email])
            ->assertStatus(422)
            ->assertExactJson(self::TAKEN);

        $this->assertNothingWasWrittenFor($this->mirror, $before, rows: 2);
    }

    #[Test]
    public function each_lunch_screen_lists_the_people_who_run_lunch_there_and_nobody_else(): void
    {
        $this->actingAsPerson($this->masjidAdmin);
        $atMasjid = collect($this->getJson($this->adminBase($this->masjid).'/jummah-lunch/staff')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->shamina->id, $this->mirror->id, $this->kitchen->id], $atMasjid, 'lunch people of the masjid, whichever kind their login was created as');

        // At the school, where she TEACHES, she is not lunch staff: not listed, and
        // not editable or removable by id (the school has lunch, so the door is open).
        $this->actingAsPerson($this->schoolAdmin);
        $this->assertSame([], $this->getJson($this->adminBase($this->school).'/jummah-lunch/staff')->assertOk()->json('data'));
        foreach ([$this->shamina, $this->mirror, $this->tutor] as $teacher) {
            $this->putJson($this->adminBase($this->school).'/jummah-lunch/staff/'.$teacher->id, ['name' => 'Renamed'])->assertNotFound();
            $this->postJson($this->adminBase($this->school).'/jummah-lunch/staff/'.$teacher->id.'/invite')->assertNotFound();
            $this->deleteJson($this->adminBase($this->school).'/jummah-lunch/staff/'.$teacher->id)->assertNotFound();
        }
        $this->assertSame(2, MasjidUser::where('user_id', $this->shamina->id)->count());

        // And each Teachers screen lists the people who teach there.
        $this->actingAsPerson($this->schoolAdmin);
        $teachersAtSchool = collect($this->getJson($this->adminBase($this->school).'/teachers')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->shamina->id, $this->mirror->id, $this->tutor->id], $teachersAtSchool);
        $this->actingAsPerson($this->masjidAdmin);
        $this->assertSame([], $this->getJson($this->adminBase($this->masjid).'/teachers')->assertOk()->json('data'));

        // An administrator is never resolved as lunch staff, whatever a row says.
        $odd = $this->makeLogin('MasjidAdmin', 'Office With A Lunch Row');
        $this->giveRole($odd, $this->masjid, 'lunch-staff', true);
        app(TenantContext::class)->forgetTenant();
        $this->actingAsPerson($this->masjidAdmin);
        $this->deleteJson($this->adminBase($this->masjid).'/jummah-lunch/staff/'.$odd->id)->assertNotFound();
        $this->assertNotContains($odd->id, collect($this->getJson($this->adminBase($this->masjid).'/jummah-lunch/staff')->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function the_lunch_screen_cannot_rename_or_re_invite_a_person_another_organisation_shares(): void
    {
        $this->actingAsPerson($this->masjidAdmin);
        $base = $this->adminBase($this->masjid).'/jummah-lunch/staff';
        $sentence = 'This person\'s name and phone are shared with another Manara organisation; ask them or Manara support to change them.';

        foreach ([$this->shamina, $this->mirror] as $shared) {
            $name = $shared->name;
            $phone = $shared->fresh()->phone;

            $this->putJson("{$base}/{$shared->id}", ['name' => 'Renamed By The Masjid'])
                ->assertStatus(422)->assertJsonPath('data.name.0', $sentence);
            $this->putJson("{$base}/{$shared->id}", ['name' => $name, 'phone' => '+15550000000'])
                ->assertStatus(422)->assertJsonPath('data.phone.0', $sentence);

            // The name as it is on file and no phone: nothing to refuse, nothing written.
            $this->putJson("{$base}/{$shared->id}", ['name' => $name])->assertOk()->assertJsonPath('data.name', $name);
            $this->assertSame([$name, $phone], [$shared->fresh()->name, $shared->fresh()->phone]);

            // What the Edit form really sends when the office presses Save without
            // changing anything: the name AND the phone this screen's own list gave
            // it. That used to be refused under the phone field, as if the office had
            // typed a new one.
            $listed = collect($this->getJson($base)->assertOk()->json('data'))->firstWhere('id', $shared->id);
            $this->assertSame($phone, $listed['phone'], 'the list shows this office the phone on file');
            $this->putJson("{$base}/{$shared->id}", ['name' => $listed['name'], 'phone' => $listed['phone']])
                ->assertOk()
                ->assertJsonPath('data.phone', $phone);
            $this->assertSame([$name, $phone], [$shared->fresh()->name, $shared->fresh()->phone]);
            $this->assertSame($shared->fresh()->updated_at->toISOString(), $shared->updated_at->toISOString(), 'and nothing was written');

            // A set-password link, once used, would reset the password and end every
            // session, at the school too.
            $this->postJson("{$base}/{$shared->id}/invite")
                ->assertStatus(422)
                ->assertJsonPath('message', 'They already have a Manara login. They can use "Forgot password" on the sign-in page.');
        }

        Mail::assertNotSent(AccountAccessMail::class);

        // A lunch login that belongs only here is edited and re-invited as before.
        $this->putJson("{$base}/{$this->kitchen->id}", ['name' => 'Kitchen Renamed', 'phone' => '+15551112222'])->assertOk();
        $this->assertSame(['Kitchen Renamed', '+15551112222'], [$this->kitchen->fresh()->name, $this->kitchen->fresh()->phone]);
        $this->postJson("{$base}/{$this->kitchen->id}/invite")->assertOk();
        Mail::assertSent(AccountAccessMail::class);
    }

    #[Test]
    public function two_doors_attaching_the_same_person_leave_exactly_one_default(): void
    {
        // `is_default` is derived under the lock on the user row at both doors (one
        // default per user is a database constraint). A teacher with NO organisation
        // is given lunch access first (it becomes the default), then a school adds
        // them (it must not).
        $nowhere = $this->makeLogin('Teacher', 'Teacher Of Nowhere');

        $this->actingAsPerson($this->masjidAdmin);
        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Typed', 'email' => $nowhere->email])->assertCreated();

        $this->actingAsPerson($this->schoolAdmin);
        $this->addTeacher($this->school, $nowhere->email)->assertCreated();

        $rows = MasjidUser::where('user_id', $nowhere->id)->orderBy('masjid_id')->get();
        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff', true], [$this->school->id, 'teacher', false]],
            $rows->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );

        // The reverse order, for a lunch login created by the lunch door itself.
        $this->actingAsPerson($this->masjidAdmin);
        $this->postJson($this->adminBase($this->masjid).'/jummah-lunch/staff', ['name' => 'Fresh', 'email' => 'fresh@example.invalid'])->assertCreated();
        $this->actingAsPerson($this->schoolAdmin);
        $this->addTeacher($this->school, 'fresh@example.invalid')->assertCreated();

        $fresh = User::where('email', 'fresh@example.invalid')->firstOrFail();
        $this->assertSame(1, MasjidUser::where('user_id', $fresh->id)->where('is_default', true)->count());
        $this->assertSame($this->masjid->id, (int) MasjidUser::where('user_id', $fresh->id)->where('is_default', true)->value('masjid_id'));
    }

    // ================================================== taking a role away, per door

    #[Test]
    #[DataProvider('bothPeople')]
    public function removing_the_teacher_role_ends_the_teacher_area_at_once_and_leaves_lunch_signed_in(string $who): void
    {
        // ONE bearer token, used on both sides of the removal.
        $token = $this->{$who}->createToken('login-token', ['staff'])->plainTextToken;
        $this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->assertOk();

        $this->actingAsPerson($this->schoolAdmin);
        $this->deleteJson($this->adminBase($this->school).'/teachers/'.$this->{$who}->id)->assertOk();

        // The school's rows are gone; the masjid's is untouched, and is now the default.
        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff', true]],
            MasjidUser::where('user_id', $this->{$who}->id)->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );
        $this->assertSame([], $this->classesLedBy($this->{$who}, $this->school));
        $this->assertNull($this->{$who}->fresh()->deleted_at);

        // The token still exists, because it is also her lunch session...
        $this->assertSame(1, $this->{$who}->tokens()->count());

        // ...and the teacher area is over on the very next request with it.
        $this->assertContains($this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->getStatusCode(), [401, 403]);
        $this->assertGateRefused($this->withBearer($token)->getJson('/api/teacher/user'), 'the teacher area\'s /user after the removal');

        // Lunch is exactly as it was, signed in, with nothing left to switch to.
        $this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();
        $lunch = $this->withBearer($token)->getJson('/api/lunch/user')->assertOk()->assertJsonPath('data.masjid.id', $this->masjid->id);
        $this->assertArrayNotHasKey('areas', $lunch->json('data'));
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function removing_lunch_access_ends_the_lunch_area_at_once_and_leaves_the_teacher_area_signed_in(string $who): void
    {
        $token = $this->{$who}->createToken('login-token', ['staff'])->plainTextToken;
        $this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->assertOk();

        $this->actingAsPerson($this->masjidAdmin);
        $this->deleteJson($this->adminBase($this->masjid).'/jummah-lunch/staff/'.$this->{$who}->id)
            ->assertOk()
            ->assertJsonPath('message', 'Lunch access removed.');

        $this->assertSame(
            [[$this->school->id, 'teacher', true]],
            MasjidUser::where('user_id', $this->{$who}->id)->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all(),
            'the school\'s row is untouched and is the default now'
        );
        $this->assertNull($this->{$who}->fresh()->deleted_at, 'the login is not retired: it still teaches');
        $this->assertSame(1, $this->{$who}->tokens()->count(), 'and it is not signed out of the school');
        $this->assertSame([$this->class->id], $this->classesLedBy($this->{$who}, $this->school));

        $this->assertContains($this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->getStatusCode(), [401, 403]);
        $this->assertGateRefused($this->withBearer($token)->getJson('/api/lunch/user'), 'the lunch area\'s /user after the removal');

        $this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        $teacher = $this->withBearer($token)->getJson('/api/teacher/user')->assertOk()->assertJsonPath('data.masjid.id', $this->school->id);
        $this->assertArrayNotHasKey('areas', $teacher->json('data'));
    }

    #[Test]
    #[DataProvider('bothPeople')]
    public function removing_the_last_role_retires_the_login_and_deletes_its_tokens(string $who): void
    {
        $token = $this->{$who}->createToken('login-token', ['staff'])->plainTextToken;

        // Either order of the two removals ends the same way: try both.
        foreach ([['school', 'masjid'], ['masjid', 'school']] as $index => $order) {
            if ($index === 1) {
                // Put the second person through the other order.
                $who = $who === 'shamina' ? 'mirror' : 'shamina';
                $token = $this->{$who}->createToken('login-token', ['staff'])->plainTextToken;
            }

            foreach ($order as $step => $place) {
                $this->actingAsPerson($place === 'school' ? $this->schoolAdmin : $this->masjidAdmin);
                $url = $place === 'school'
                    ? $this->adminBase($this->school).'/teachers/'.$this->{$who}->id
                    : $this->adminBase($this->masjid).'/jummah-lunch/staff/'.$this->{$who}->id;
                $this->deleteJson($url)->assertOk();

                if ($step === 0) {
                    $this->assertSame(1, $this->{$who}->tokens()->count(), 'one role left: still signed in');
                }
            }

            $this->assertSame(0, MasjidUser::where('user_id', $this->{$who}->id)->count());
            $this->assertSame(0, $this->{$who}->tokens()->count(), 'belongs nowhere: every session ended');
            $this->assertNotNull(User::withTrashed()->findOrFail($this->{$who}->id)->deleted_at, 'and the login is retired');
            $this->withBearer($token)->getJson('/api/lunch/user')->assertUnauthorized();
            $this->withBearer($token)->getJson('/api/teacher/user')->assertUnauthorized();
        }
    }

    // ============================================================== Team & Access

    #[Test]
    public function team_and_access_labels_each_person_by_what_they_are_at_that_organisation(): void
    {
        $this->actingAsPerson($this->schoolAdmin);
        $atSchool = collect($this->getJson($this->adminBase($this->school).'/team')->assertOk()->json('data.people'))->keyBy('user_id');

        foreach ([$this->shamina, $this->mirror, $this->tutor] as $teacher) {
            $this->assertSame('teacher', $atSchool[$teacher->id]['access'], "{$teacher->name} is a teacher at the school");
            $this->assertFalse($atSchool[$teacher->id]['removable'], 'teachers are removed on the Teachers screen');
            $this->assertSame(1, $atSchool[$teacher->id]['classes']);
        }
        $this->assertSame('admin', $atSchool[$this->schoolAdmin->id]['access']);

        $this->actingAsPerson($this->masjidAdmin);
        $atMasjid = collect($this->getJson($this->adminBase($this->masjid).'/team')->assertOk()->json('data.people'))->keyBy('user_id');

        foreach ([$this->shamina, $this->mirror, $this->kitchen] as $lunch) {
            $this->assertSame('jummah_lunch', $atMasjid[$lunch->id]['access'], "{$lunch->name} is lunch-only at the masjid");
            $this->assertTrue($atMasjid[$lunch->id]['removable']);
            $this->assertNull($atMasjid[$lunch->id]['classes']);
        }
    }

    #[Test]
    public function team_and_access_treats_a_lunch_login_that_teaches_here_as_the_teacher_she_is(): void
    {
        // Every guard a teacher has on this screen. Before, they asked users.type, and
        // a teacher whose login was created as lunch staff would have passed all three:
        // the removal would have deleted her teacher row and every session she has.
        $token = $this->shamina->createToken('login-token', ['staff'])->plainTextToken;

        $this->actingAsPerson($this->schoolAdmin);
        $base = $this->adminBase($this->school).'/team/'.$this->shamina->id;

        $this->patchJson($base, ['access' => 'admin'])
            ->assertStatus(422)->assertJsonPath('data.user_id.0', 'Teachers are managed on the Teachers screen, with their classes.');
        $this->postJson($base.'/invite')
            ->assertStatus(422)->assertJsonPath('data.user_id.0', 'Teachers are managed on the Teachers screen, with their classes.');
        $this->deleteJson($base)
            ->assertStatus(422)->assertJsonPath('data.user_id.0', 'Teachers are removed on the Teachers screen, with their classes.');

        $this->assertSame(User::TYPE_LUNCH_STAFF, $this->shamina->fresh()->homeType());
        $this->assertSame(2, MasjidUser::where('user_id', $this->shamina->id)->count());
        $this->assertSame(1, $this->shamina->tokens()->count());
        $this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        Mail::assertNothingSent();
    }

    #[Test]
    public function team_and_access_at_the_masjid_cannot_retype_or_re_invite_her_and_its_removal_keeps_her_school_session(): void
    {
        $token = $this->shamina->createToken('login-token', ['staff'])->plainTextToken;

        $this->actingAsPerson($this->masjidAdmin);
        $base = $this->adminBase($this->masjid).'/team/'.$this->shamina->id;

        // users.type is one value for everything: changing it here would change it at
        // the school. Today's refusal for any login that belongs elsewhere.
        $this->patchJson($base, ['access' => 'admin'])->assertStatus(409);
        $this->assertSame(User::TYPE_LUNCH_STAFF, $this->shamina->fresh()->homeType());

        $this->postJson($base.'/invite')
            ->assertStatus(422)
            ->assertJsonPath('data.user_id.0', 'They already have a Manara login. They can use "Forgot password" on the sign-in page.');
        Mail::assertNotSent(AccountAccessMail::class);

        // Removing her lunch access here: the school is untouched, session included.
        $this->deleteJson($base)->assertOk();

        $this->assertSame(
            [[$this->school->id, 'teacher', true]],
            MasjidUser::where('user_id', $this->shamina->id)->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );
        $this->assertNull($this->shamina->fresh()->deleted_at);
        $this->assertSame(1, $this->shamina->tokens()->count());
        $this->withBearer($token)->getJson($this->teacherBase($this->school).'/groups')->assertOk();
        $this->assertContains($this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->getStatusCode(), [401, 403]);

        // A lunch login that belongs ONLY here is removed as it always was: signed
        // out and retired.
        $this->kitchen->createToken('phone');
        $this->actingAsPerson($this->masjidAdmin);
        $this->deleteJson($this->adminBase($this->masjid).'/team/'.$this->kitchen->id)->assertOk();
        $this->assertSame(0, $this->kitchen->tokens()->count());
        $this->assertNotNull(User::withTrashed()->findOrFail($this->kitchen->id)->deleted_at);

        // And Team still refuses an address that has a login, in its own words.
        $this->postJson($this->adminBase($this->masjid).'/team', ['name' => 'Typed', 'email' => $this->tutor->email, 'access' => 'jummah_lunch'])
            ->assertStatus(422)
            ->assertJsonPath('data.email.0', 'That email already has a login. Use a different address, or ask Manara to move the existing login.');
        $this->assertSame(1, MasjidUser::where('user_id', $this->tutor->id)->count());
    }

    /** @return array<string, array{0: string}> the kind the login was created as; either way its ONE row is the masjid's lunch row */
    public static function loginsWhoseOnlyRowIsTheMasjidsLunchRow(): array
    {
        return [
            // The Teachers door attaches this one while the roles switch is on.
            'created as lunch staff' => [User::TYPE_LUNCH_STAFF],
            // And this one always could be attached: to the Teachers door it is an
            // existing teacher from somewhere else (its school removed it after
            // the masjid gave it lunch).
            'created as a teacher' => ['Teacher'],
        ];
    }

    #[Test]
    #[DataProvider('loginsWhoseOnlyRowIsTheMasjidsLunchRow')]
    public function a_retype_decided_while_a_school_attaches_the_same_person_cannot_end_with_an_administrator_of_that_school(string $createdAs): void
    {
        // The one place a teacher's or lunch-staff login can become an administrator
        // is Change access, and it is refused for a login that belongs to another
        // organisation: an administrator binds through ANY live row, so a retyped
        // login would administer every organisation it holds a row at.
        //
        // That refusal used to be decided from a read made before the transaction and
        // without a lock, and the attach doors hold the user row's lock while they
        // write their row. So a school adding this person at the same moment was
        // invisible to the check, the retype waited for the school's commit and then
        // went ahead: `users.type = MasjidAdmin`, with a `teacher` row at the school.
        //
        // One process cannot interleave two requests. The other door's COMMIT is
        // stood in for by writing its row at the moment this request opens its
        // transaction, which is after the early check and before the lock: exactly
        // what the lock, once it is granted, lets the re-read see.
        $person = $this->makeLogin($createdAs, 'One Row At The Masjid');
        $this->giveRole($person, $this->masjid, 'lunch-staff', true);
        app(TenantContext::class)->forgetTenant();

        $token = $person->createToken('login-token', ['staff'])->plainTextToken;
        $before = $person->fresh()->getAttributes();

        $this->actingAsPerson($this->masjidAdmin);

        $attached = false;
        Event::listen(TransactionBeginning::class, function () use (&$attached, $person): void {
            if ($attached) {
                return;
            }
            $attached = true;

            DB::table('masjid_user')->insert([
                'masjid_id' => $this->school->id, 'user_id' => $person->id, 'role' => 'teacher',
                'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $this->patchJson($this->adminBase($this->masjid).'/team/'.$person->id, ['access' => 'admin'])
                ->assertStatus(409)
                ->assertJsonPath('data.user_id.0', 'This login also belongs to another organisation, and its access applies to both. Change it with Manara.');
        } finally {
            Event::forget(TransactionBeginning::class);
        }

        $this->assertTrue($attached, 'the request opened a transaction, so the other door\'s row was written inside the window');

        // Nothing about the login moved: not its kind, not its bridged role, not the
        // role of its row here, not its session.
        $this->assertSame($before, $person->fresh()->getAttributes());
        $this->assertSame($createdAs, $person->fresh()->homeType());
        $this->assertSame([User::TYPE_ROLE_MAP[$createdAs]], $person->fresh()->getRoleNames()->all());
        $this->assertSame(
            [[$this->masjid->id, 'lunch-staff'], [$this->school->id, 'teacher']],
            MasjidUser::where('user_id', $person->id)->orderBy('masjid_id')->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role])->all()
        );
        $this->assertSame(1, $person->tokens()->count());

        // And what the race used to buy is refused: no admin surface at the school
        // that made this person a teacher, nor at the masjid.
        foreach ([$this->school, $this->masjid] as $organisation) {
            $this->assertGateRefused($this->withBearer($token)->getJson($this->adminBase($organisation)), "the admin area at {$organisation->name}");
        }
    }

    #[Test]
    public function change_access_still_moves_a_login_that_belongs_only_here_and_ends_its_sessions(): void
    {
        // The lock changes when the decision is made, not what it is.
        $this->kitchen->createToken('phone');

        $this->actingAsPerson($this->masjidAdmin);

        $this->patchJson($this->adminBase($this->masjid).'/team/'.$this->kitchen->id, ['access' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.access', 'admin');

        $this->assertSame('MasjidAdmin', $this->kitchen->fresh()->homeType());
        $this->assertSame('masjid-admin', MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->kitchen->id)->value('role'));
        $this->assertSame(0, $this->kitchen->tokens()->count());

        // And back again.
        $this->patchJson($this->adminBase($this->masjid).'/team/'.$this->kitchen->id, ['access' => 'jummah_lunch'])
            ->assertOk()
            ->assertJsonPath('data.access', 'jummah_lunch');
        $this->assertSame(User::TYPE_LUNCH_STAFF, $this->kitchen->fresh()->homeType());
        $this->assertSame('lunch-staff', MasjidUser::where('masjid_id', $this->masjid->id)->where('user_id', $this->kitchen->id)->value('role'));
    }

    #[Test]
    public function a_removal_decided_while_a_school_attaches_the_same_person_does_not_retire_the_schools_new_teacher(): void
    {
        // Team's removal retires a login that belongs nowhere else. It read "nowhere
        // else" inside a transaction that never locked the user row, so on MySQL its
        // snapshot was taken before a school's attach committed, the archive then
        // waited for that commit, and the school was left with an archived teacher
        // nobody could sign in as or add again.
        //
        // SQLite has no snapshot to show that with, so two things are pinned: the
        // outcome when the school's row is there by the time the transaction reads
        // (kept signed in, not retired, the school made the default), and the ORDER
        // that makes the read see it on MySQL: the transaction's first statement is
        // the user row, read for update.
        $token = $this->kitchen->createToken('login-token', ['staff'])->plainTextToken;

        $this->actingAsPerson($this->masjidAdmin);

        $attached = false;
        Event::listen(TransactionBeginning::class, function () use (&$attached): void {
            if ($attached) {
                return;
            }
            $attached = true;

            DB::table('masjid_user')->insert([
                'masjid_id' => $this->school->id, 'user_id' => $this->kitchen->id, 'role' => 'teacher',
                'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::flushQueryLog();
            DB::enableQueryLog();
        });

        try {
            $this->deleteJson($this->adminBase($this->masjid).'/team/'.$this->kitchen->id)->assertOk();
            $inTheTransaction = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            Event::forget(TransactionBeginning::class);
        }

        $this->assertTrue($attached);
        $this->assertSame('select * from "users" where "users"."id" = ? limit 1', $inTheTransaction[0], 'the lock on the user row comes before any read the decision rests on');

        $this->assertNull($this->kitchen->fresh()?->deleted_at, 'she is the school\'s teacher now: not retired');
        $this->assertSame(1, $this->kitchen->tokens()->count(), 'and not signed out of the school');
        $this->assertSame(
            [[$this->school->id, 'teacher', true]],
            MasjidUser::where('user_id', $this->kitchen->id)->get()->map(fn (MasjidUser $r) => [(int) $r->masjid_id, $r->role, (bool) $r->is_default])->all()
        );
        $this->assertContains($this->withBearer($token)->getJson($this->lunchBase($this->masjid).'/jummah-lunch/menus')->getStatusCode(), [401, 403]);
    }

    #[Test]
    public function both_of_team_and_accesss_decisions_take_the_user_rows_lock_as_the_first_statement_of_their_transaction(): void
    {
        // On SQLite `lockForUpdate()` compiles to nothing, so the tests above cannot
        // tell a locking read from a plain one. The attach doors serialise on this
        // lock (TeachersController, LunchStaffController); a Team decision that did
        // not take it would race them again, silently. Read from the source, as the
        // doors' own tripwires are (DualMembershipIsolationTest).
        $source = (string) file_get_contents(app_path('Http/Controllers/AdminDashboard/TeamController.php'));

        foreach (['public function update(' => 'public function invite(', 'public function destroy(' => 'private function boundMasjid('] as $from => $to) {
            $method = substr($source, (int) strpos($source, $from), (int) strpos($source, $to) - (int) strpos($source, $from));
            $transaction = substr($method, (int) strpos($method, 'DB::transaction('));

            $this->assertNotSame('', $transaction, "{$from} decides inside a transaction");

            $firstStatement = strtok((string) preg_replace('~^\s*(//|\*|/\*).*$~m', '', substr($transaction, (int) strpos($transaction, '{') + 1)), ';');

            $this->assertStringContainsString('lockedLogin(', (string) $firstStatement, "{$from} the first statement of the transaction is the lock");
        }

        $this->assertMatchesRegularExpression(
            '/function lockedLogin\(User \$user\): User\s*\{\s*\$locked = User::withTrashed\(\)->whereKey\(\$user->id\)->lockForUpdate\(\)->first\(\);/',
            $source
        );
    }

    // ============================================ the other readers of a person's kind

    #[Test]
    public function the_administrators_screen_sends_her_to_the_screen_of_what_she_is_here(): void
    {
        $this->actingAsPerson($this->schoolAdmin);
        $this->deleteJson($this->adminBase($this->school).'/administrators/'.$this->shamina->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only administrators are removed here. Remove them on the Teachers screen.');

        $this->actingAsPerson($this->masjidAdmin);
        $this->deleteJson($this->adminBase($this->masjid).'/administrators/'.$this->shamina->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only administrators are removed here. Remove them on the Team & Access screen.');
        $this->deleteJson($this->adminBase($this->masjid).'/administrators/'.$this->mirror->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only administrators are removed here. Remove them on the Team & Access screen.');

        $this->assertSame(2, MasjidUser::where('user_id', $this->shamina->id)->count());
    }

    #[Test]
    public function the_access_list_shows_her_as_each_thing_where_she_is_each(): void
    {
        $access = OrganisationAccess::forUsers(User::whereIn('id', [
            $this->shamina->id, $this->mirror->id, $this->kitchen->id, $this->tutor->id, $this->schoolAdmin->id,
        ])->get());

        $at = fn (User $user): array => collect($access[$user->id])->pluck('access', 'masjid_id')->all();

        $this->assertEquals([$this->masjid->id => 'jummah_lunch', $this->school->id => 'teacher'], $at($this->shamina));
        $this->assertEquals([$this->masjid->id => 'jummah_lunch', $this->school->id => 'teacher'], $at($this->mirror));
        $this->assertSame([$this->masjid->id => 'jummah_lunch'], $at($this->kitchen));
        $this->assertSame([$this->school->id => 'teacher'], $at($this->tutor));
        $this->assertSame([$this->school->id => 'admin'], $at($this->schoolAdmin));
    }

    #[Test]
    public function the_timetable_can_name_her_and_switching_it_back_on_does_not_end_her_meeting(): void
    {
        // The real case: Library is a timetable assignment. The timetable's teacher
        // list asked for users.type Teacher AND a `teacher` row; she has only the row.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));

        $this->school->forceFill(['capability_overrides' => [
            'jummah_lunch' => true, 'school_calendar' => true, 'school_calendar_terms' => true, 'school_timetable' => true,
        ]])->save();
        $year = SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => 'Practice year',
            'first_day' => '2026-09-07', 'last_day' => '2027-06-25', 'meeting_weekdays' => [1, 2, 3, 4, 5],
        ]);

        // People who must NOT be offered: the school's own lunch person, and a lunch
        // login that teaches at ANOTHER school.
        $schoolKitchen = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'School Kitchen');
        $this->giveRole($schoolKitchen, $this->school, 'lunch-staff', true);
        $teachesElsewhere = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Teaches Elsewhere');
        $this->giveRole($teachesElsewhere, $this->elsewhere, 'teacher', true);
        app(TenantContext::class)->forgetTenant();

        $this->actingAsPerson($this->schoolAdmin);
        $base = $this->adminBase($this->school)."/timetable/years/{$year->id}";

        $set = $this->postJson($base.'/sets', ['name' => 'Regular', 'periods' => [
            ['name' => 'P1', 'starts_at' => '08:00', 'ends_at' => '09:00', 'kind' => 'teaching', 'position' => 1],
        ]])->assertCreated()->json('data');
        $this->putJson($base.'/days', ['group_id' => null, 'days' => [['weekday' => 1, 'period_set_id' => $set['id']]]])->assertOk();

        $offered = array_column($this->getJson($base.'/setup')->assertOk()->json('data.teachers'), 'id');
        $this->assertEqualsCanonicalizing([$this->shamina->id, $this->mirror->id, $this->tutor->id], $offered, 'the teachers of this school, whichever kind their login was created as');

        $meeting = fn (int $teacherId) => $this->postJson($base.'/meetings', [
            'group_id' => $this->class->id, 'period_id' => $set['periods'][0]['id'], 'weekday' => 1,
            'kind' => 'activity', 'activity_name' => 'Library', 'teacher_ids' => [$teacherId], 'effective_from' => '2026-10-10',
        ]);

        $meeting($schoolKitchen->id)->assertNotFound();
        $meeting($teachesElsewhere->id)->assertNotFound();
        $meeting($this->kitchen->id)->assertNotFound();

        $id = $meeting($this->shamina->id)->assertCreated()->json('data.id');

        // Switched off and back on: the pass that ends meetings whose teacher is no
        // longer one leaves hers alone.
        CapabilityWriter::apply($this->school->fresh(), ['school_timetable' => false], $this->schoolAdmin->id);
        $super = $this->makeLogin('SuperAdmin', 'A Super');
        $this->actingAsPerson($super);

        $this->patchJson($this->adminBase($this->school).'/capabilities/school_timetable', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.timetable_ended_meetings', 0);
        $this->assertNull(DB::table('timetable_meetings')->where('id', $id)->value('effective_until'));

        // She sees it on her own schedule, in the teacher area.
        $this->actingAsPerson($this->shamina);
        $schedule = $this->getJson($this->teacherBase($this->school).'/schedule?week=2026-10-12')->assertOk();
        $this->assertStringContainsString('Library', $schedule->getContent());
    }

    #[Test]
    public function a_mark_or_a_message_by_her_is_a_teachers_and_never_the_offices(): void
    {
        // The attendance marker: "Office:" is for an administrator's mark.
        $this->assertSame('Shamina', AttendanceRegister::marker('Shamina', User::TYPE_LUNCH_STAFF));
        $this->assertSame('Shamina', AttendanceRegister::marker('Shamina', 'Teacher'));
        $this->assertSame('Office: Amina', AttendanceRegister::marker('Amina', 'MasjidAdmin'));
        $this->assertSame('Office: Amina', AttendanceRegister::marker('Amina', 'SuperAdmin'));
        $this->assertSame('Former staff member', AttendanceRegister::marker(null, null));

        // A message she scheduled for a class she no longer leads is held for the
        // reason a teacher's is. She leads Library; she never led Grade 5.
        $gate = app(ScheduledSendGate::class);
        $library = Group::withoutMasjidScope()->findOrFail($this->class->id);
        $gradeFive = Group::withoutMasjidScope()->findOrFail($this->otherClass->id);

        foreach ([$this->shamina, $this->mirror, $this->tutor] as $teacher) {
            $leads = in_array($library->id, $this->classesLedBy($teacher, $this->school), true) ? $library : $gradeFive;
            $doesNotLead = $leads->is($library) ? $gradeFive : $library;

            $this->assertNull($gate->authorRefusal($teacher->id, $leads), "{$teacher->name} leads this class");
            $this->assertSame('The author no longer teaches this class.', $gate->authorRefusal($teacher->id, $doesNotLead), "{$teacher->name} does not lead this class");
        }

        // A lunch login that was never a teacher has no standing in a class either.
        $this->assertSame('The author no longer teaches this class.', $gate->authorRefusal($this->kitchen->id, $library));
    }

    // ---------------------------------------------------------------- helpers

    private function addTeacher(Masjid $school, string $email)
    {
        return $this->postJson($this->adminBase($school).'/teachers', [
            'name' => 'Typed Name',
            'email' => $email,
            'class_ids' => [$this->class->id],
        ]);
    }

    /** @return list<int> */
    private function classesLedBy(User $user, Masjid $organisation): array
    {
        return GroupStaff::withoutMasjidScope()
            ->where('user_id', $user->id)->where('masjid_id', $organisation->id)
            ->orderBy('group_id')->pluck('group_id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /** A refusal wrote nothing: not the login, not a row, not a class, not a mail. */
    private function assertNothingWasWrittenFor(User $user, array $attributesBefore, int $rows): void
    {
        $this->assertSame($attributesBefore, User::withTrashed()->findOrFail($user->id)->getAttributes(), 'the users row is untouched');
        $this->assertSame($rows, MasjidUser::where('user_id', $user->id)->count(), 'no membership was written');
        Mail::assertNothingSent();
    }

    private function masjidClass(): Group
    {
        $class = $this->makeClass($this->masjid, 'Weekend Circle');
        app(TenantContext::class)->forgetTenant();

        return $class;
    }
}
