<?php

namespace Tests\Feature;

use App\Models\ArabicLetterProgress;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Arabic\ArabicCurriculum as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The letter tracker over HTTP: a teacher marking, a class moving stage, and a
 * parent watching without being able to mark.
 *
 * The tab carries two alphabets — the Arabic qāʿidah and the English A–Z the
 * school asked for — through the same four routes, so the guarantees at the
 * bottom of this file are all about the two tracks staying separate: separate
 * syllabus, separate cells, separate percentage.
 */
class ArabicLetterTrackerTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Group $class;
    private User $teacher;
    private GroupMembership $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
            'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->teacher->id;
        $this->masjid->save();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
            'arabic_stage' => C::STAGE_SHORT_VOWELS,
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'first_name' => 'Amina']);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        Sanctum::actingAs($this->teacher);
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/groups/{$this->class->id}".$path;
    }

    #[Test]
    public function a_students_tracker_shows_every_letter_and_only_the_stages_drills(): void
    {
        $response = $this->getJson($this->url("/members/{$this->student->id}/letters"))->assertOk();

        // All 28 letters always — the grid never changes size, so a child can
        // learn where their letter sits.
        $response->assertJsonCount(28, 'data.letters');
        $response->assertJsonPath('data.stage.id', C::STAGE_SHORT_VOWELS);

        // At short vowels: bare + fatha + kasra + damma.
        $this->assertSame(28 * 4, $response->json('data.totals.total'));
        $this->assertSame(0, $response->json('data.totals.mastered'));

        $ba = collect($response->json('data.letters'))->firstWhere('id', 'ba');
        $this->assertSame(['ba', 'ba.fatha', 'ba.kasra', 'ba.damma'], array_column($ba['drills'], 'id'));

        // Four shapes for a connecting letter, two for one that never joins.
        $this->assertCount(4, $ba['positions']);
        $this->assertCount(2, collect($response->json('data.letters'))->firstWhere('id', 'dal')['positions']);
    }

    #[Test]
    public function a_teacher_marks_a_drill_and_the_totals_follow(): void
    {
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba.fatha', 'status' => C::STATUS_MASTERED,
        ])->assertOk()->assertJsonPath('data.totals.mastered', 1);

        $row = ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->student->id)->firstOrFail();

        $this->assertSame('ba.fatha', $row->drill_id);
        $this->assertSame($this->teacher->id, $row->marked_by_user_id);
        $this->assertNotNull($row->mastered_at);
    }

    #[Test]
    public function marking_the_same_drill_twice_does_not_mint_a_second_cell(): void
    {
        foreach ([C::STATUS_LEARNING, C::STATUS_MASTERED, C::STATUS_LEARNING] as $status) {
            $this->putJson($this->url("/members/{$this->student->id}/letters"), [
                'drill_id' => 'ba', 'status' => $status,
            ])->assertOk();
        }

        $this->assertSame(1, ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->student->id)->count());
    }

    #[Test]
    public function a_drill_from_a_later_stage_is_refused(): void
    {
        // The class is on short vowels; tanween is not part of its denominator,
        // so a tick there would sit in a cell no screen shows.
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba.dammatan', 'status' => C::STATUS_MASTERED,
        ])->assertStatus(422);

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    #[Test]
    public function moving_the_class_forward_widens_the_syllabus_without_losing_work(): void
    {
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba.fatha', 'status' => C::STATUS_MASTERED,
        ])->assertOk();

        $this->putJson($this->url('/letters/stage'), ['stage' => C::STAGE_TANWEEN])
            ->assertOk()
            ->assertJsonPath('data.stage.id', C::STAGE_TANWEEN);

        $tracker = $this->getJson($this->url("/members/{$this->student->id}/letters"))->assertOk();

        $this->assertSame(28 * 9, $tracker->json('data.totals.total'));
        // The work already done is still there.
        $this->assertSame(1, $tracker->json('data.totals.mastered'));
    }

    #[Test]
    public function moving_the_class_back_hides_later_work_without_deleting_it(): void
    {
        $this->putJson($this->url('/letters/stage'), ['stage' => C::STAGE_TANWEEN])->assertOk();
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba.dammatan', 'status' => C::STATUS_MASTERED,
        ])->assertOk();

        $this->putJson($this->url('/letters/stage'), ['stage' => C::STAGE_SHORT_VOWELS])->assertOk();

        // Out of scope, so out of sight — but the row survives and returns
        // intact when the class moves on again.
        $narrow = $this->getJson($this->url("/members/{$this->student->id}/letters"))->assertOk();
        $this->assertSame(0, $narrow->json('data.totals.mastered'));
        $this->assertSame(1, ArabicLetterProgress::withoutMasjidScope()->count());

        $this->putJson($this->url('/letters/stage'), ['stage' => C::STAGE_TANWEEN])->assertOk();
        $this->assertSame(1, $this->getJson($this->url("/members/{$this->student->id}/letters"))
            ->json('data.totals.mastered'));
    }

    #[Test]
    public function the_class_overview_never_reads_over_one_hundred_percent(): void
    {
        // A drill mastered at a wider stage still counts as mastered, but it is
        // not in a narrower stage's denominator — so the count is clamped.
        $this->putJson($this->url('/letters/stage'), ['stage' => C::STAGE_MADD])->assertOk();
        foreach (['ba', 'ba.fatha', 'ba.madd_alif'] as $drill) {
            $this->putJson($this->url("/members/{$this->student->id}/letters"), [
                'drill_id' => $drill, 'status' => C::STATUS_MASTERED,
            ])->assertOk();
        }

        $this->putJson($this->url('/letters/stage'), ['stage' => C::STAGE_LETTERS])->assertOk();

        $overview = $this->getJson($this->url('/letters'))->assertOk();
        $student = $overview->json('data.students.0');

        $this->assertSame(28, $overview->json('data.total'));
        $this->assertLessThanOrEqual(1.0, $student['completion']);
        $this->assertLessThanOrEqual(28, $student['mastered']);
    }

    #[Test]
    public function the_overview_carries_each_students_avatar(): void
    {
        $this->student->contact->forceFill([
            'avatar_character' => 'ameera', 'avatar_tone' => 'tone2', 'avatar_color' => 'green',
        ])->save();

        $this->getJson($this->url('/letters'))
            ->assertOk()
            ->assertJsonPath('data.students.0.contact.avatar.color', 'green');
    }

    #[Test]
    public function an_unknown_drill_id_is_refused(): void
    {
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'not_a_letter.fatha', 'status' => C::STATUS_MASTERED,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------ the English track

    #[Test]
    public function the_english_track_lists_twenty_six_letters_and_fifty_two_case_drills(): void
    {
        $response = $this->getJson($this->url("/members/{$this->student->id}/letters?alphabet=english"))
            ->assertOk();

        $response->assertJsonCount(26, 'data.letters');
        $response->assertJsonPath('data.alphabet', 'english');
        // The direction rides in the payload so a client never infers it.
        $response->assertJsonPath('data.direction', 'ltr');

        // The class sits at short vowels, which is a qāʿidah stage and means
        // nothing here: English has ONE stage and every letter is in it.
        $response->assertJsonPath('data.stage.id', 'letters');
        // 26 capitals + 26 lower case: the overall figure a parent reads is /52.
        $this->assertSame(52, $response->json('data.totals.total'));
        $this->assertSame(0, $response->json('data.totals.mastered'));

        // The two runs, each counted on its own; their sum is the overall one.
        $this->assertSame([
            ['id' => 'upper', 'label' => 'Capitals', 'mastered' => 0, 'total' => 26],
            ['id' => 'lower', 'label' => 'Lower case', 'mastered' => 0, 'total' => 26],
        ], $response->json('data.set_totals'));
        $this->assertSame(['upper', 'lower'], array_column($response->json('data.sets'), 'id'));

        $a = collect($response->json('data.letters'))->firstWhere('id', 'a');
        $this->assertSame([
            ['id' => 'upper', 'text' => 'A'],
            ['id' => 'lower', 'text' => 'a'],
        ], $a['positions']);

        // Two drills per letter, one per case, and the phonics cue a teacher reads
        // off the card. The ids are `a.upper` / `a.lower`, never `A` / `a`.
        $this->assertSame(['a.upper', 'a.lower'], array_column($a['drills'], 'id'));
        $this->assertSame(['upper', 'lower'], array_column($a['drills'], 'set'));
        $this->assertSame('a as in apple', $a['drills'][0]['sound']);
        $this->assertNull($a['drills'][0]['arabic_name']);
    }

    #[Test]
    public function the_arabic_track_has_no_sets_and_no_set_totals(): void
    {
        // T-004.2 must not turn the qāʿidah's four letter FORMS into sets.
        $response = $this->getJson($this->url("/members/{$this->student->id}/letters"))->assertOk();

        $this->assertSame([], $response->json('data.sets'));
        $this->assertSame([], $response->json('data.set_totals'));
        $this->assertSame(28 * 4, $response->json('data.totals.total'));

        $drill = $response->json('data.letters.0.drills.0');
        $this->assertArrayHasKey('set', $drill);
        $this->assertNull($drill['set']);
    }

    #[Test]
    public function marking_one_case_moves_only_that_sets_count_and_leaves_the_other_case_alone(): void
    {
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'b.upper', 'status' => C::STATUS_MASTERED, 'alphabet' => 'english',
        ])->assertOk();

        $tracker = $this->getJson($this->url("/members/{$this->student->id}/letters?alphabet=english"))
            ->assertOk();

        $this->assertSame(1, $tracker->json('data.totals.mastered'));
        $this->assertSame(1, $tracker->json('data.set_totals.0.mastered'));
        $this->assertSame(0, $tracker->json('data.set_totals.1.mastered'));

        $b = collect($tracker->json('data.letters'))->firstWhere('id', 'b');
        $this->assertSame(['mastered', 'not_started'], array_column($b['drills'], 'status'));
        // Half the letter is done, which is "learning" for the tile of the LETTER.
        $this->assertSame('learning', $b['status']);
    }

    #[Test]
    public function a_stale_tab_posting_the_old_bare_letter_is_told_to_reload_not_shown_a_bare_422(): void
    {
        // A teacher's tab left open across the deploy still sends `a`. It must be
        // refused (nothing may be written under an id the tracker never reads)
        // but with a message that says what happened and what to do.
        $response = $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'a', 'status' => C::STATUS_MASTERED, 'alphabet' => 'english',
        ])->assertStatus(422);

        $this->assertSame('stale_page', $response->json('code'));
        $this->assertStringContainsString('reload the page', strtolower($response->json('message')));
        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());

        // A genuinely unknown id keeps the ordinary refusal, not the reload prompt.
        $typo = $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'aa', 'status' => C::STATUS_MASTERED, 'alphabet' => 'english',
        ])->assertStatus(422);
        $this->assertNull($typo->json('code'));
    }

    #[Test]
    public function a_single_latin_letter_posted_to_the_arabic_track_gets_the_ordinary_refusal_not_the_reload_prompt(): void
    {
        // `a` is the retired ENGLISH id. On the Arabic track it is just an invalid drill: telling that teacher
        // "English capitals and lower case are now tracked separately, reload" would send her looking for a change
        // that has nothing to do with what she tapped.
        foreach ([[], ['alphabet' => 'arabic']] as $extra) {
            $response = $this->putJson($this->url("/members/{$this->student->id}/letters"), $extra + [
                'drill_id' => 'a', 'status' => C::STATUS_MASTERED,
            ])->assertStatus(422);

            $this->assertNull($response->json('code'));
            $this->assertStringNotContainsString('reload', strtolower((string) $response->json('message')));
            $this->assertStringContainsString('not part of what this class is working on', $response->json('message'));
        }

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_arabic_class_overview_counts_only_drills_inside_the_stage_it_divides_by(): void
    {
        // THE LATENT ARABIC OVER-COUNT. The overview's numerator was every
        // mastered Arabic row in the class; its denominator was the stage's
        // syllabus. A letter-GROUP drill (valid at any stage, never part of the
        // syllabus) or a drill from a later stage was counted on top, and the
        // min($count,$total) clamp only hid it once the bar hit 100%.
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba', 'status' => C::STATUS_MASTERED,
        ])->assertOk();
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => C::groupDrills(C::GROUP_HALQ)[0], 'status' => C::STATUS_MASTERED,
        ])->assertOk();

        $overview = $this->getJson($this->url('/letters'))->assertOk()->json('data.students.0');

        // Only `ba` is in the syllabus at this stage, so the child has ONE.
        $this->assertSame(1, $overview['mastered']);
    }

    #[Test]
    public function an_alphabet_the_tracker_does_not_know_is_refused(): void
    {
        // A plain varchar column: a typo must not mint a third track whose rows
        // no screen will ever show again.
        $this->getJson($this->url("/members/{$this->student->id}/letters?alphabet=englsih"))
            ->assertStatus(422);
    }

    #[Test]
    public function english_mastery_never_moves_the_arabic_totals_or_the_arabic_percentage(): void
    {
        // THE REGRESSION THIS TEST EXISTS FOR: the class overview counts
        // mastered rows for the class in one grouped query. Before English
        // existed that query needed no alphabet filter. Unfiltered, 26 English
        // ticks land in the Arabic count — and the min($count, $total) clamp
        // hides the overflow by pinning the bar at 100%, so a parent reads a
        // finished qāʿidah that is one drill in.
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba.fatha', 'status' => C::STATUS_MASTERED,
        ])->assertOk();

        $before = $this->getJson($this->url('/letters'))->assertOk()->json('data.students.0');
        $this->assertSame(1, $before['mastered']);

        foreach (range('a', 'z') as $letter) {
            foreach (['upper', 'lower'] as $case) {
                $this->putJson($this->url("/members/{$this->student->id}/letters"), [
                    'drill_id' => "{$letter}.{$case}", 'status' => C::STATUS_MASTERED, 'alphabet' => 'english',
                ])->assertOk();
            }
        }

        $arabic = $this->getJson($this->url("/members/{$this->student->id}/letters"))->assertOk();
        $this->assertSame(28 * 4, $arabic->json('data.totals.total'));
        $this->assertSame(1, $arabic->json('data.totals.mastered'));

        $after = $this->getJson($this->url('/letters'))->assertOk()->json('data.students.0');
        $this->assertSame($before['mastered'], $after['mastered']);
        $this->assertSame($before['completion'], $after['completion']);

        // And the English side is genuinely full, so the numbers above are not
        // simply a mark that never landed.
        $english = $this->getJson($this->url('/letters?alphabet=english'))->assertOk();
        $this->assertSame(52, $english->json('data.total'));
        $this->assertSame(52, $english->json('data.students.0.mastered'));
        $this->assertEquals(1.0, $english->json('data.students.0.completion'));
    }

    #[Test]
    public function a_drill_belongs_to_one_alphabet_and_is_refused_on_the_other(): void
    {
        // `ba` is a qāʿidah drill and nothing at all on the English track.
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'ba', 'status' => C::STATUS_MASTERED, 'alphabet' => 'english',
        ])->assertStatus(422);

        // And the reverse — `a` is not a letter of the Arabic alphabet.
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'a', 'status' => C::STATUS_MASTERED, 'alphabet' => 'arabic',
        ])->assertStatus(422);

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_unknown_english_drill_is_refused(): void
    {
        $this->putJson($this->url("/members/{$this->student->id}/letters"), [
            'drill_id' => 'aa', 'status' => C::STATUS_MASTERED, 'alphabet' => 'english',
        ])->assertStatus(422);

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_class_stage_cannot_be_set_from_the_english_track(): void
    {
        // groups.arabic_stage is the qāʿidah's ladder. English has one stage, so
        // there is nothing to set — and writing here from an English screen
        // would move the class's ARABIC denominator without saying so.
        $this->putJson($this->url('/letters/stage'), [
            'stage' => C::STAGE_LETTERS, 'alphabet' => 'english',
        ])->assertStatus(422);

        $this->assertSame(C::STAGE_SHORT_VOWELS, $this->class->fresh()->arabic_stage);
    }
}
