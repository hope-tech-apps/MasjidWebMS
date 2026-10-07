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

    // ------------------------------------------------------------------
    // Rewording the note on a recitation (owner, 2026-10-07). The list never
    // showed the note and nothing could change it; PUT .../hifz/{entry_id}
    // rewrites the NOTE and nothing else.
    // ------------------------------------------------------------------

    #[Test]
    public function a_teacher_may_reword_the_note_on_a_recitation_and_reads_it_back(): void
    {
        $entry = $this->recitation('Struggled with the waqf on ayah 3.');

        $this->putJson($this->hifzUrl($entry), ['note' => 'Fixed the waqf on ayah 3 today.'])
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.note', 'Fixed the waqf on ayah 3 today.');

        $this->assertSame('Fixed the waqf on ayah 3 today.', $entry->fresh()->note);

        // The list the teacher's screen draws carries the new words.
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/hifz")
            ->assertOk()
            ->assertJsonPath('data.data.0.note', 'Fixed the waqf on ayah 3 today.');
    }

    #[Test]
    public function a_note_can_be_added_to_a_recitation_that_had_none_and_cleared_deliberately(): void
    {
        $entry = $this->recitation(null);

        $this->putJson($this->hifzUrl($entry), ['note' => 'Revise at home.'])->assertOk();
        $this->assertSame('Revise at home.', $entry->fresh()->note);

        // PRESENT and blank is the deliberate clear, as for every note here.
        $this->putJson($this->hifzUrl($entry), ['note' => '   '])
            ->assertOk()
            ->assertJsonPath('data.note', null);
        $this->assertNull($entry->fresh()->note);
    }

    #[Test]
    public function a_request_that_does_not_mention_the_note_changes_nothing(): void
    {
        $entry = $this->recitation('Keep this.');

        $this->putJson($this->hifzUrl($entry), ['quality' => 'repeat'])->assertStatus(422);

        $this->assertSame('Keep this.', $entry->fresh()->note);
        $this->assertSame('excellent', $entry->fresh()->quality);
    }

    #[Test]
    public function only_the_note_is_rewritten_never_what_was_heard(): void
    {
        $entry = $this->recitation('First words.');
        $before = $entry->fresh()->only([
            'kind', 'from_surah', 'from_ayah', 'to_surah', 'to_ayah', 'quality',
            'major_mistakes', 'minor_mistakes', 'heard_by_user_id', 'group_membership_id', 'corrected_by_user_id',
        ]);
        $heardAt = $entry->fresh()->recited_at->toIso8601String();

        $this->putJson($this->hifzUrl($entry), [
            'note' => 'Second words.',
            // None of these may ride along.
            'kind' => 'manzil', 'quality' => 'repeat', 'from_surah' => 2, 'from_ayah' => 1,
            'to_surah' => 2, 'to_ayah' => 286, 'major_mistakes' => 9, 'recited_at' => '2026-01-01',
            'membership_id' => 999999, 'heard_by_user_id' => 999999,
        ])->assertOk();

        $after = $entry->fresh();
        $this->assertSame('Second words.', $after->note);
        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame($heardAt, $after->recited_at->toIso8601String());
        $this->assertFalse($after->trashed());
    }

    #[Test]
    public function a_note_longer_than_the_limit_is_refused_and_the_old_one_stays(): void
    {
        $entry = $this->recitation('Short.');
        $max = (int) config('groups.hifz.max_note_length', 1000);

        $this->putJson($this->hifzUrl($entry), ['note' => str_repeat('a', $max + 1)])
            ->assertStatus(422);

        $this->assertSame('Short.', $entry->fresh()->note);

        $this->putJson($this->hifzUrl($entry), ['note' => str_repeat('a', $max)])->assertOk();
    }

    #[Test]
    public function a_teacher_who_did_not_hear_it_but_teaches_quran_in_the_class_may_reword_it_and_the_edit_is_logged_without_the_words(): void
    {
        $entry = $this->recitation('Typed by the first teacher.');

        $second = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $second->id, 'role' => 'teacher', 'is_default' => true,
        ]);
        $this->mine->staff()->attach($second->id, [
            'masjid_id' => $this->mine->masjid_id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
        Sanctum::actingAs($second, ['staff']);

        \Illuminate\Support\Facades\Log::spy();

        $this->putJson($this->hifzUrl($entry), ['note' => 'Reworded by the second teacher.'])
            ->assertOk()
            // Who HEARD it does not change because somebody else reworded the note.
            ->assertJsonPath('data.heard_by.id', $this->teacher->id);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($entry, $second): bool {
                return $message === 'Hifdh note edited'
                    && $context['entry_id'] === $entry->id
                    && $context['by_user_id'] === $second->id
                    && $context['heard_by_user_id'] === $this->teacher->id
                    && ! str_contains(json_encode($context), 'Reworded')
                    && ! str_contains(json_encode($context), 'Typed by');
            })->once();
    }

    #[Test]
    public function saving_the_same_note_again_succeeds_and_logs_no_edit(): void
    {
        $entry = $this->recitation('Unchanged.');

        \Illuminate\Support\Facades\Log::spy();

        $this->putJson($this->hifzUrl($entry), ['note' => 'Unchanged.'])->assertOk();

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_teacher_limited_to_another_subject_cannot_reword_a_hifz_note(): void
    {
        $entry = $this->recitation('Qur\'an teacher\'s words.');

        GroupStaff::query()->where('group_id', $this->mine->id)
            ->update(['subjects' => json_encode([GroupStaff::SUBJECT_ARABIC])]);

        $this->putJson($this->hifzUrl($entry), ['note' => 'Arabic teacher was here.'])->assertForbidden();

        $this->assertSame('Qur\'an teacher\'s words.', $entry->fresh()->note);
    }

    #[Test]
    public function a_note_in_a_class_the_teacher_does_not_lead_cannot_be_reworded_by_either_address(): void
    {
        $other = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->notMine->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $this->school->id])->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $theirs = \App\Models\HifzEntry::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->notMine->id, 'group_membership_id' => $other->id,
            'heard_by_user_id' => $this->teacher->id, 'kind' => 'sabak',
            'from_surah' => 114, 'from_ayah' => 1, 'to_surah' => 114, 'to_ayah' => 6,
            'quality' => 'good', 'note' => 'Another class.',
        ]);

        // Through its own class: the teacher does not lead it.
        $this->putJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->notMine->id}/hifz/{$theirs->id}",
            ['note' => 'Not my class.']
        )->assertForbidden();

        // Through the class the teacher DOES lead: the entry is not in it, so it is a miss.
        $this->putJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz/{$theirs->id}",
            ['note' => 'Not my class.']
        )->assertNotFound();

        $this->assertSame('Another class.', $theirs->fresh()->note);
    }

    #[Test]
    public function a_struck_recitation_has_no_note_to_reword(): void
    {
        $entry = $this->recitation('Struck.');

        $this->deleteJson($this->hifzUrl($entry))->assertOk();

        $this->putJson($this->hifzUrl($entry), ['note' => 'Written where nobody reads.'])->assertNotFound();

        $this->assertSame('Struck.', \App\Models\HifzEntry::withTrashed()->findOrFail($entry->id)->note);
    }

    // ------------------------------------------------------------------
    // Correcting a recorded line (owner, 2026-10-07): POST .../hifz/{id}/correct.
    // In place, with the line as it stood kept as a struck copy.
    // ------------------------------------------------------------------

    /** The line `recitation()` records, as a correction body, with overrides. */
    private function line(array $over = []): array
    {
        return $over + [
            'kind' => 'sabak', 'from_surah' => 114, 'from_ayah' => 1, 'to_surah' => 114, 'to_ayah' => 6,
            'quality' => 'excellent', 'note' => null,
        ];
    }

    private function correctUrl(\App\Models\HifzEntry $entry): string
    {
        return $this->hifzUrl($entry).'/correct';
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\HifzEntry> the struck rows of the student */
    private function struck(): \Illuminate\Support\Collection
    {
        return \App\Models\HifzEntry::withoutGlobalScopes()->onlyTrashed()
            ->where('group_membership_id', $this->student->id)->orderBy('id')->get();
    }

    #[Test]
    public function a_correction_changes_the_line_in_place_and_keeps_the_line_as_it_stood_as_a_struck_copy(): void
    {
        $entry = $this->recitation('First words.');
        $heardAt = $entry->recited_at->toIso8601String();
        $createdAt = $entry->created_at->toIso8601String();

        $this->postJson($this->correctUrl($entry), $this->line([
            'quality' => 'fair', 'to_ayah' => 4, 'note' => 'First words.',
        ]))
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.quality', 'fair')
            ->assertJsonPath('data.to.ayah', 4)
            ->assertJsonPath('data.note', 'First words.')
            ->assertJsonPath('data.heard_by.id', $this->teacher->id);

        $now = $entry->fresh();
        $this->assertSame('fair', $now->quality);
        $this->assertSame(4, $now->to_ayah);
        // The day was not sent: the moment it was heard is untouched, to the second.
        $this->assertSame($heardAt, $now->recited_at->toIso8601String());
        $this->assertSame($createdAt, $now->created_at->toIso8601String());
        $this->assertNull($now->corrected_by_user_id);

        // The history: one struck copy holding what the line said before, and who corrected it.
        $struck = $this->struck();
        $this->assertCount(1, $struck);
        $this->assertSame('excellent', $struck[0]->quality);
        $this->assertSame(6, $struck[0]->to_ayah);
        $this->assertSame('First words.', $struck[0]->note);
        $this->assertSame($this->teacher->id, $struck[0]->corrected_by_user_id);
        $this->assertSame($this->teacher->id, $struck[0]->heard_by_user_id);
        $this->assertSame($heardAt, $struck[0]->recited_at->toIso8601String());

        // The teacher's list holds the one corrected line and nothing of the copy.
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/hifz")
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.quality', 'fair');
    }

    #[Test]
    public function correcting_the_earlier_of_two_lines_heard_at_the_same_moment_does_not_move_the_childs_position(): void
    {
        // Two new-memorization lines of one day, entered for an earlier date: both
        // carry the same instant, so only their ids order them.
        $day = '2026-10-05';
        $first = $this->postJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz", [
            'membership_id' => $this->student->id, 'kind' => 'sabak', 'quality' => 'good',
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 10, 'recited_at' => $day,
        ])->assertSuccessful()->json('data.id');
        $this->postJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz", [
            'membership_id' => $this->student->id, 'kind' => 'sabak', 'quality' => 'good',
            'from_surah' => 78, 'from_ayah' => 11, 'to_surah' => 78, 'to_ayah' => 20, 'recited_at' => $day,
        ])->assertSuccessful();

        $progress = "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/members/{$this->student->id}/hifz/progress";
        $this->getJson($progress)->assertOk()->assertJsonPath('data.current_position.ayah', 20);

        // Only the QUALITY of the earlier line changes.
        $this->postJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz/{$first}/correct",
            ['kind' => 'sabak', 'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 10, 'quality' => 'excellent', 'note' => null]
        )->assertOk()->assertJsonPath('data.id', $first);

        // The child is still at the end of the LATER line. (Striking and recording
        // again gave the earlier line a higher id and put the child back at 78:10.)
        $this->getJson($progress)->assertOk()
            ->assertJsonPath('data.current_position.surah', 78)
            ->assertJsonPath('data.current_position.ayah', 20);
    }

    #[Test]
    public function a_corrected_day_moves_the_line_and_a_day_in_the_future_is_refused(): void
    {
        $entry = $this->recitation(null);

        $this->postJson($this->correctUrl($entry), $this->line(['recited_at' => '2026-09-29T12:00:00Z']))
            ->assertOk()
            ->assertJsonPath('data.recited_at', '2026-09-29T12:00:00+00:00');
        $this->assertSame('2026-09-29 12:00:00', $entry->fresh()->recited_at->toDateTimeString());
        $this->assertCount(1, $this->struck());

        $this->postJson($this->correctUrl($entry), $this->line(['recited_at' => now()->addDay()->toIso8601String()]))
            ->assertStatus(422);
        $this->assertSame('2026-09-29 12:00:00', $entry->fresh()->recited_at->toDateTimeString());
        $this->assertCount(1, $this->struck(), 'a refused correction leaves no copy');
    }

    #[Test]
    public function when_only_the_note_differs_the_note_is_rewritten_and_no_copy_is_struck_and_an_unchanged_line_writes_nothing(): void
    {
        $entry = $this->recitation('Old words.');
        $stamp = $entry->fresh()->updated_at->toIso8601String();

        \Illuminate\Support\Facades\Log::spy();

        // Unchanged.
        $this->travel(5)->seconds();
        $this->postJson($this->correctUrl($entry), $this->line(['note' => 'Old words.']))
            ->assertOk()->assertJsonPath('meta.changed', [])->assertJsonPath('meta.note_changed', false);
        $this->assertSame($stamp, $entry->fresh()->updated_at->toIso8601String());
        $this->assertCount(0, $this->struck());
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');

        // Only the note.
        $this->postJson($this->correctUrl($entry), $this->line(['note' => 'New words.']))
            ->assertOk()->assertJsonPath('data.note', 'New words.')->assertJsonPath('meta.changed', []);
        $this->assertSame('New words.', $entry->fresh()->note);
        $this->assertCount(0, $this->struck());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c): bool => $m === 'Hifdh note edited' && ! str_contains(json_encode($c), 'words'))
            ->once();
    }

    #[Test]
    public function a_correction_is_held_to_the_mushaf_and_a_refused_one_changes_nothing(): void
    {
        $entry = $this->recitation('Kept.');

        // An-Nas has 6 ayahs.
        $this->postJson($this->correctUrl($entry), $this->line(['to_ayah' => 7, 'note' => 'Kept.']))->assertStatus(422);
        // The end before the start.
        $this->postJson($this->correctUrl($entry), $this->line(['from_ayah' => 5, 'to_ayah' => 2, 'note' => 'Kept.']))->assertStatus(422);
        // No note key at all.
        $body = $this->line(['quality' => 'repeat']);
        unset($body['note']);
        $this->postJson($this->correctUrl($entry), $body)->assertStatus(422);
        // Not a quality the school has.
        $this->postJson($this->correctUrl($entry), $this->line(['quality' => 'perfect', 'note' => 'Kept.']))->assertStatus(422);

        $now = $entry->fresh();
        $this->assertSame('excellent', $now->quality);
        $this->assertSame(6, $now->to_ayah);
        $this->assertSame('Kept.', $now->note);
        $this->assertCount(0, $this->struck());

        // "Whole surah" is filled in by the server, as on a new record.
        $this->postJson($this->correctUrl($entry), [
            'kind' => 'sabqi', 'from_surah' => 112, 'to_surah' => 112, 'whole_surah' => true, 'quality' => 'good', 'note' => 'Kept.',
        ])->assertOk()->assertJsonPath('data.whole_surah', true)->assertJsonPath('data.to.ayah', 4)->assertJsonPath('data.kind', 'sabqi');
    }

    #[Test]
    public function a_correction_cannot_move_a_line_to_another_student_or_rename_who_heard_it(): void
    {
        $entry = $this->recitation(null);
        $other = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $this->school->id])->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->postJson($this->correctUrl($entry), $this->line([
            'quality' => 'good', 'membership_id' => $other->id, 'group_membership_id' => $other->id,
            'heard_by_user_id' => 999999, 'group_id' => $this->notMine->id, 'masjid_id' => 999999,
        ]))->assertOk();

        $now = $entry->fresh();
        $this->assertSame('good', $now->quality);
        $this->assertSame($this->student->id, $now->group_membership_id);
        $this->assertSame($this->teacher->id, $now->heard_by_user_id);
        $this->assertSame($this->mine->id, $now->group_id);
        $this->assertSame($this->school->id, $now->masjid_id);
    }

    #[Test]
    public function a_correction_is_logged_by_field_names_only_and_keeps_the_same_fences_as_recording(): void
    {
        $entry = $this->recitation('Private words about a child.');

        \Illuminate\Support\Facades\Log::spy();
        $this->postJson($this->correctUrl($entry), $this->line(['quality' => 'repeat', 'note' => 'Private words about a child.']))->assertOk();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($entry): bool {
                return $message === 'Hifdh entry corrected'
                    && $context['entry_id'] === $entry->id
                    && $context['by_user_id'] === $this->teacher->id
                    && $context['changed'] === ['quality']
                    && is_int($context['struck_copy_id'])
                    && ! str_contains(json_encode($context), 'Private')
                    && ! str_contains(json_encode($context), 'repeat');
            })->once();

        // A struck entry is a miss.
        $struckId = $this->struck()[0]->id;
        $this->postJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz/{$struckId}/correct",
            $this->line()
        )->assertNotFound();

        // A teacher limited to another subject is refused, and nothing changes.
        GroupStaff::query()->where('group_id', $this->mine->id)
            ->update(['subjects' => json_encode([GroupStaff::SUBJECT_ARABIC])]);
        $this->postJson($this->correctUrl($entry), $this->line(['quality' => 'good']))->assertForbidden();
        $this->assertSame('repeat', $entry->fresh()->quality);

        // A class the teacher does not lead.
        $this->postJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->notMine->id}/hifz/{$entry->id}/correct",
            $this->line(['quality' => 'good'])
        )->assertForbidden();
    }

    /** One recitation for the student, recorded by the teacher through the real door. */
    private function recitation(?string $note): \App\Models\HifzEntry
    {
        $id = $this->postJson(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz",
            [
                'membership_id' => $this->student->id,
                'kind' => 'sabak',
                'from_surah' => 114, 'from_ayah' => 1,
                'to_surah' => 114, 'to_ayah' => 6,
                'quality' => 'excellent',
            ] + ($note === null ? [] : ['note' => $note])
        )->assertSuccessful()->json('data.id');

        return \App\Models\HifzEntry::withoutGlobalScopes()->findOrFail($id);
    }

    private function hifzUrl(\App\Models\HifzEntry $entry): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}/hifz/{$entry->id}";
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
