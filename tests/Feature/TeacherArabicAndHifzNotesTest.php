<?php

namespace Tests\Feature;

use App\Models\ArabicDailyNote;
use App\Models\ArabicLetterProgress;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three places a teacher may now write words about a child (2026-09-16).
 *
 * The owner asked for notes on each hifz update, notes on each Arabic letter,
 * and a general daily note on a child's Arabic progress. Two of the three
 * needed no new endpoint — the hifz note was already accepted by
 * StoreHifzEntryRequest and simply had no UI, and the per-drill note rides the
 * existing mark upsert. Only the daily note is new.
 *
 * What these tests are really pinning is the ABSENT-KEY rule: a client that
 * does not mention a note must never be treated as clearing one. Every client
 * written before today sends no note at all, and the first time an older screen
 * marked a drill it would otherwise erase what a teacher had typed.
 */
class TeacherArabicAndHifzNotesTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $mine;
    private Group $notMine;
    private GroupMembership $student;
    private string $guardianEmail;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeSchool();

        $this->teacher = User::factory()->create([
            'type' => 'Teacher',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        // A teacher owns no masjid; their school is a masjid_user membership.
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->mine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $this->notMine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 5', 'slug' => 'grade-5',
        ]);

        // The teacher leads only `mine`.
        $this->mine->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->mine->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        // A student with real contact details, and a guardian with a distinctive
        // email — neither must ever reach a teacher payload.
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => 'Amina', 'last_name' => 'Yusuf',
            'email' => 'amina.child@example.test', 'phone' => '+15551230001',
        ]);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->guardianEmail = 'parent.secret@example.test';
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => 'Huda', 'last_name' => 'Yusuf',
            'email' => $this->guardianEmail, 'phone' => '+15559998888',
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    #[Test]
    public function a_teacher_may_write_a_note_on_a_drill_and_clear_it_deliberately(): void
    {
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/letters";

        $this->putJson($url, [
            'drill_id' => $this->firstDrillId(),
            'status' => 'mastered',
            'note' => 'Held the madd for the full count today.',
        ])->assertOk();

        $row = ArabicLetterProgress::withoutGlobalScopes()
            ->where('group_membership_id', $this->student->id)->firstOrFail();
        $this->assertSame('Held the madd for the full count today.', $row->note);

        // A PRESENT but empty note is a deliberate clear.
        $this->putJson($url, [
            'drill_id' => $this->firstDrillId(),
            'status' => 'mastered',
            'note' => '   ',
        ])->assertOk();

        $this->assertNull($row->fresh()->note);
    }

    #[Test]
    public function an_older_client_that_sends_no_note_does_not_erase_one(): void
    {
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/letters";

        $this->putJson($url, [
            'drill_id' => $this->firstDrillId(),
            'status' => 'learning',
            'note' => 'Reverses sīn and shīn when tired.',
        ])->assertOk();

        // The shape every client shipped before today sends: no `note` key.
        $this->putJson($url, [
            'drill_id' => $this->firstDrillId(),
            'status' => 'mastered',
        ])->assertOk();

        $row = ArabicLetterProgress::withoutGlobalScopes()
            ->where('group_membership_id', $this->student->id)->firstOrFail();

        $this->assertSame('mastered', $row->status, 'the status the client did send must still be written');
        $this->assertSame(
            'Reverses sīn and shīn when tired.',
            $row->note,
            'an absent note key must not be read as a request to clear the note'
        );
    }

    #[Test]
    public function a_drill_note_is_readable_again_and_not_only_writable(): void
    {
        // The first cut of this feature stored the note and returned it from
        // nowhere. Every write answered 200, the row was correct, and the
        // teacher's next visit showed an empty box — so the only way to find
        // out what she had written about a child was to read the database.
        // Writes that cannot be read back are this module's recurring failure,
        // and they always answer 200 while they do it.
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/letters";
        $drill = $this->firstDrillId();

        $written = $this->putJson($url, [
            'drill_id' => $drill,
            'status' => 'learning',
            'note' => 'Confuses ṣād with sīn when she is tired.',
        ])->assertOk();

        $this->assertSame(
            'Confuses ṣād with sīn when she is tired.',
            $this->drillNote($written->json('data'), $drill),
            'the response to the write that stored the note must carry it back'
        );

        // And on a FRESH read, which is the visit that actually mattered.
        $reread = $this->getJson($url)->assertOk();

        $this->assertSame(
            'Confuses ṣād with sīn when she is tired.',
            $this->drillNote($reread->json('data'), $drill),
            'reopening the child must show what the teacher wrote about this drill'
        );

        // A drill nobody wrote about carries the key, explicitly null. An absent
        // key and a null one read the same in PHP and very differently in a
        // screen that decides whether to show an empty editor.
        $untouched = collect(data_get($reread->json('data'), 'letters.*.drills.*'))
            ->first(fn (array $d) => $d['id'] !== $drill);

        $this->assertArrayHasKey('note', $untouched);
        $this->assertNull($untouched['note']);
    }

    #[Test]
    public function the_tracker_every_realm_reads_carries_the_note_including_the_familys(): void
    {
        // WHAT THIS DOES AND DOES NOT SAY.
        //
        // `LetterTracker::forStudent` is the one assembler behind the teacher
        // screen, the admin console AND `Family\ArabicLettersController`, so
        // adding the note to it puts the note on the family ENDPOINT. That is
        // deliberate and it matches the hifz note, which
        // `Family\HifzEntriesController` includes on the stated ground that "a
        // record a parent cannot read the detail of is not a record they have
        // been given". Two notes about the same child, written by the same
        // teacher in the same week, should not have two different audiences, and
        // if that is ever revisited it must be revisited for both — which is
        // what a failing test here forces somebody to do.
        //
        // It does NOT mean a parent sees the note in the app today.
        // `FamilyClass.vue` renders letter CHIPS and never drills, so there is
        // nowhere in that screen for a per-drill note to appear. This pins the
        // record, not the rendering.
        $this->putJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/letters",
            ['drill_id' => $this->firstDrillId(), 'status' => 'learning', 'note' => 'Needs the shape drilled at home.']
        )->assertOk();

        $this->assertSame(
            'Needs the shape drilled at home.',
            data_get(
                \App\Support\Letters\LetterTracker::for('arabic')
                    ->forStudent($this->mine->fresh(), $this->student->fresh()),
                'letters.0.drills.0.note'
            ),
            'the tracker every realm reads must carry the note'
        );
    }

    #[Test]
    public function the_screen_is_told_the_note_limits_rather_than_inventing_them(): void
    {
        // `TeacherClass.vue` hardcoded the hifz quality list and one of its four
        // values existed nowhere in PHP; `repeat` was unreachable for two and a
        // half weeks and nothing failed loudly. A maxlength the screen invents
        // fails the same quiet way — higher than the validator's, it becomes a
        // 422 the teacher reads as the app losing what she typed.
        $meta = $this->getJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/letters"
        )->assertOk()->json('meta');

        $this->assertSame(
            (int) config('groups.arabic.max_note_length'),
            $meta['max_note_length']
        );
        $this->assertSame(
            (int) config('groups.arabic.max_daily_note_length'),
            $meta['max_daily_note_length']
        );
    }

    #[Test]
    public function the_daily_note_is_an_upsert_so_a_second_save_corrects_rather_than_duplicates(): void
    {
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/arabic-notes";

        $this->putJson($url, ['session_date' => '2026-09-16', 'note' => 'First write.'])->assertOk();
        $this->putJson($url, ['session_date' => '2026-09-16', 'note' => 'Corrected.'])->assertOk();

        $notes = ArabicDailyNote::withoutGlobalScopes()
            ->where('group_membership_id', $this->student->id)->get();

        $this->assertCount(1, $notes, 'a teacher writing twice about one day is correcting, not recording two days');
        $this->assertSame('Corrected.', $notes->first()->note);
    }

    #[Test]
    public function a_daily_note_cannot_be_dated_in_the_future(): void
    {
        $this->putJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/arabic-notes",
            ['session_date' => now()->addDay()->toDateString(), 'note' => 'A lesson that has not happened.']
        )->assertStatus(422);
    }

    #[Test]
    public function deleting_a_daily_note_removes_the_row_rather_than_blanking_it(): void
    {
        $base = "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/arabic-notes";

        $id = $this->putJson($base, ['session_date' => '2026-09-16', 'note' => 'Written then removed.'])
            ->assertOk()->json('data.id');

        $this->deleteJson($base.'/'.$id)->assertOk();

        // Absence of a row means nobody wrote. A blank row would assert that a
        // teacher wrote nothing, which is a different and untrue claim.
        $this->assertDatabaseMissing('arabic_daily_notes', ['id' => $id]);
    }

    #[Test]
    public function a_teacher_cannot_write_a_daily_note_for_a_class_they_do_not_lead(): void
    {
        $other = GroupMembership::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->notMine->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $this->school->id])->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->putJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->notMine->id}/members/{$other->id}/arabic-notes",
            ['session_date' => '2026-09-16', 'note' => 'Not my class.']
        )->assertForbidden();

        $this->assertDatabaseMissing('arabic_daily_notes', ['group_membership_id' => $other->id]);
    }

    #[Test]
    public function a_hifz_entry_carries_the_teachers_note(): void
    {
        $this->postJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz",
            [
                // `membership_id`, which is what StoreHifzEntryRequest asks for.
                'membership_id' => $this->student->id,
                'kind' => 'sabak',
                'from_surah' => 114, 'from_ayah' => 1,
                'to_surah' => 114, 'to_ayah' => 6,
                'quality' => 'excellent',
                'note' => 'Entered from the office; not heard in a single sitting.',
            ]
        )->assertSuccessful();

        $this->assertDatabaseHas('hifz_entries', [
            'group_membership_id' => $this->student->id,
            'note' => 'Entered from the office; not heard in a single sitting.',
        ]);
    }

    /** The first drill the class's current stage actually contains. */
    /** One drill's note out of a tracker payload, by drill id. */
    private function drillNote(array $payload, string $drillId): ?string
    {
        foreach (data_get($payload, 'letters.*.drills.*') as $drill) {
            if (($drill['id'] ?? null) === $drillId) {
                return $drill['note'] ?? null;
            }
        }

        $this->fail("drill {$drillId} is not in the tracker payload at all");
    }

    private function firstDrillId(): string
    {
        $payload = $this->getJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/letters"
        )->assertOk()->json('data');

        return data_get($payload, 'letters.0.drills.0.id')
            ?? data_get($payload, 'letters.0.drills.0.drill_id');
    }

    private function makeSchool(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
