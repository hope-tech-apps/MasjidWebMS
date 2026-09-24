<?php

namespace Tests\Feature;

use App\Models\ArabicLetterProgress;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Letters\EnglishCurriculum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Three shortcuts the BISS teachers asked for on 2026-09-21:
 *
 *   - mark every letter drill mastered for a child who already knows them;
 *   - record a whole surah without typing its āyāt;
 *   - see each student's running points total.
 *
 * Each is pinned by what it must NOT do as much as by what it does: the bulk
 * mark leaves mastered history and notes alone and stays inside the class's
 * stage; the whole-surah entry is an ordinary full range from the server's own
 * index, not a flag; the totals are the same number the per-student summary
 * reports, in roster order, and only for the class's teachers. The subject gates
 * hold on the new routes as on the old.
 */
class TeacherClassShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $class;
    private GroupMembership $esraa;
    private GroupMembership $yusuf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = Masjid::create([
            'name' => 'BISS Test '.uniqid(), 'email' => 'biss-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = $this->makeTeacher();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => 'first-second',
        ]);

        $this->esraa = $this->enrol('Esraa');
        $this->yusuf = $this->enrol('Yusuf');

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    // ================================================================ LETTERS

    #[Test]
    public function mark_all_defaults_to_the_stage_the_button_names_and_leaves_earlier_stages_alone(): void
    {
        $this->assign(null);
        $this->class->forceFill(['arabic_stage' => ArabicCurriculum::STAGE_SHORT_VOWELS])->save();

        // The reported bug (teacher, 2026-09-24): the confirmation reads "Mark
        // all N remaining <stage> drills", and this used to resolve to the
        // CUMULATIVE syllabus — so a class on Short Vowels had its twenty-eight
        // bare letters marked too, work the teacher had not asked about. The
        // default is now exactly the drills that stage introduces.
        $own = ArabicCurriculum::stageDrills(ArabicCurriculum::STAGE_SHORT_VOWELS);
        $cumulative = ArabicCurriculum::syllabus(ArabicCurriculum::STAGE_SHORT_VOWELS);

        $this->assertCount(28 * 3, $own);
        $this->assertCount(28 * 4, $cumulative);

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'))
            ->assertOk()
            ->assertJsonPath('meta.changed', count($own))
            // The denominator is untouched: the bar still counts the whole stage.
            ->assertJsonPath('data.totals.total', count($cumulative))
            ->assertJsonPath('data.totals.mastered', count($own));

        $rows = ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->esraa->id)->get();

        $this->assertEqualsCanonicalizing($own, $rows->pluck('drill_id')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->status === ArabicCurriculum::STATUS_MASTERED));
        $this->assertTrue($rows->every(fn ($r) => $r->mastered_at !== null));
        $this->assertTrue($rows->every(fn ($r) => (int) $r->marked_by_user_id === $this->teacher->id));
        $this->assertTrue($rows->every(fn ($r) => $r->alphabet === 'arabic'));

        // No bare-letter drill was written, and nothing from the next stage.
        $this->assertSame(0, $rows->filter(fn ($r) => ! str_contains($r->drill_id, '.'))->count());
        $this->assertSame(0, $rows->filter(fn ($r) => str_contains($r->drill_id, '.sukun'))->count());

        // The other child is untouched.
        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->yusuf->id)->count());
    }

    #[Test]
    public function mark_all_with_the_everything_scope_takes_the_whole_cumulative_syllabus(): void
    {
        $this->assign(null);
        $this->class->forceFill(['arabic_stage' => ArabicCurriculum::STAGE_MADD])->save();

        // The child who genuinely knows it all — the case the feature was built
        // for. It is still available, but a teacher now has to ask for it.
        $cumulative = ArabicCurriculum::syllabus(ArabicCurriculum::STAGE_MADD);

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), ['scope' => 'everything'])
            ->assertOk()
            ->assertJsonPath('meta.changed', count($cumulative))
            ->assertJsonPath('data.totals.mastered', count($cumulative));

        $rows = ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->esraa->id)->get();

        $this->assertEqualsCanonicalizing($cumulative, $rows->pluck('drill_id')->all());
    }

    #[Test]
    public function mark_all_on_a_letter_group_touches_that_group_and_nothing_else(): void
    {
        $this->assign(null);

        $throat = ArabicCurriculum::groupDrills(ArabicCurriculum::GROUP_HALQ);

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), [
            'scope' => 'group', 'group' => ArabicCurriculum::GROUP_HALQ,
        ])
            ->assertOk()
            ->assertJsonPath('meta.changed', count($throat))
            // The group carries its OWN total and stays out of the stage bar.
            ->assertJsonPath('data.totals.mastered', 0)
            ->assertJsonPath('data.groups.0.id', ArabicCurriculum::GROUP_HALQ)
            ->assertJsonPath('data.groups.0.totals.mastered', count($throat));

        $rows = ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->esraa->id)->get();

        $this->assertEqualsCanonicalizing($throat, $rows->pluck('drill_id')->all());
    }

    #[Test]
    public function mark_all_refuses_a_group_this_alphabet_does_not_have(): void
    {
        $this->assign(null);

        // A–Z has no sounding groups at all, so naming one is not a typo to be
        // shrugged off — it would silently write Arabic drills onto the English
        // track if the scope were ignored.
        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), [
            'scope' => 'group', 'group' => ArabicCurriculum::GROUP_HALQ, 'alphabet' => 'english',
        ])->assertUnprocessable();

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), [
            'scope' => 'group', 'group' => 'makhraj_of_the_moon',
        ])->assertUnprocessable();

        // `group` is required when the scope says group.
        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), ['scope' => 'group'])
            ->assertUnprocessable();

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    #[Test]
    public function mark_all_keeps_mastered_history_and_every_note(): void
    {
        $this->assign(null);
        $other = $this->makeTeacher();
        $firstMastered = now()->subMonths(2)->startOfSecond();

        // Mastered in July by somebody else, with a note.
        $this->cell('alif', ArabicCurriculum::STATUS_MASTERED, [
            'mastered_at' => $firstMastered, 'marked_by_user_id' => $other->id, 'note' => 'knew it on day one',
        ]);
        // Still learning, with a note.
        $this->cell('ba', ArabicCurriculum::STATUS_LEARNING, ['note' => 'mixes it up with ta']);

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'))
            ->assertOk()
            ->assertJsonPath('meta.changed', count(ArabicCurriculum::syllabus(null)) - 1);

        $alif = $this->row('alif');
        $this->assertSame(ArabicCurriculum::STATUS_MASTERED, $alif->status);
        $this->assertSame($firstMastered->toIso8601String(), $alif->mastered_at->toIso8601String());
        $this->assertSame($other->id, (int) $alif->marked_by_user_id);
        $this->assertSame('knew it on day one', $alif->note);

        $ba = $this->row('ba');
        $this->assertSame(ArabicCurriculum::STATUS_MASTERED, $ba->status);
        $this->assertSame('mixes it up with ta', $ba->note);
        $this->assertNotNull($ba->mastered_at);

        // A second press changes nothing and says so.
        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'))
            ->assertOk()
            ->assertJsonPath('meta.changed', 0);
    }

    #[Test]
    public function mark_all_on_the_english_track_leaves_the_arabic_track_alone(): void
    {
        $this->assign(null);

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), ['alphabet' => 'english'])
            ->assertOk()
            ->assertJsonPath('data.alphabet', 'english')
            ->assertJsonPath('data.totals.mastered', count(EnglishCurriculum::syllabus(null)));

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->esraa->id)->where('alphabet', 'arabic')->count());

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'), ['alphabet' => 'klingon'])
            ->assertUnprocessable();
    }

    #[Test]
    public function mark_all_is_refused_to_a_teacher_who_does_not_teach_arabic(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $this->putJson($this->url('/members/'.$this->esraa->id.'/letters/master-all'))
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not teach Arabic in this class.');

        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    #[Test]
    public function mark_all_cannot_reach_a_child_in_another_class(): void
    {
        $this->assign(null);
        $elsewhere = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Other', 'slug' => 'other',
        ]);
        $stranger = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $elsewhere->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $this->school->id])->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->putJson($this->url('/members/'.$stranger->id.'/letters/master-all'))->assertNotFound();
        $this->assertSame(0, ArabicLetterProgress::withoutMasjidScope()->count());
    }

    // ================================================================= HIFDH

    #[Test]
    public function a_whole_surah_is_recorded_as_its_full_range_from_the_servers_index(): void
    {
        $this->assign(null);

        // Form-encoded, as the SPA sends it: the checkbox arrives as a string.
        $this->post($this->url('/hifz'), [
            'membership_id' => $this->esraa->id, 'kind' => 'sabak',
            'from_surah' => 78, 'to_surah' => 78, 'whole_surah' => 'true', 'quality' => 'good',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.from.ayah', 1)
            ->assertJsonPath('data.to.surah', 78)
            ->assertJsonPath('data.to.ayah', 40)
            ->assertJsonPath('data.ayahs', 40)
            ->assertJsonPath('data.whole_surah', true);

        $entry = HifzEntry::withoutMasjidScope()->sole();
        $this->assertSame([78, 1, 78, 40], [
            (int) $entry->from_surah, (int) $entry->from_ayah, (int) $entry->to_surah, (int) $entry->to_ayah,
        ]);

        // With no to_surah either — the checkbox and a surah are enough.
        $this->postJson($this->url('/hifz'), [
            'membership_id' => $this->esraa->id, 'kind' => 'manzil',
            'from_surah' => 2, 'whole_surah' => true, 'quality' => 'good',
        ])->assertCreated()->assertJsonPath('data.to.ayah', 286);
    }

    #[Test]
    public function a_range_that_happens_to_be_whole_reads_as_whole_and_a_part_does_not(): void
    {
        $this->assign(null);

        $this->postJson($this->url('/hifz'), $this->hifz(['from_surah' => 1, 'from_ayah' => 1, 'to_surah' => 1, 'to_ayah' => 7]))
            ->assertCreated()->assertJsonPath('data.whole_surah', true);

        $this->postJson($this->url('/hifz'), $this->hifz(['from_surah' => 1, 'from_ayah' => 1, 'to_surah' => 1, 'to_ayah' => 6]))
            ->assertCreated()->assertJsonPath('data.whole_surah', false);
    }

    #[Test]
    public function whole_surah_with_a_contradicting_range_is_refused_rather_than_guessed(): void
    {
        $this->assign(null);

        $this->postJson($this->url('/hifz'), $this->hifz([
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 10, 'whole_surah' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['to_ayah'], 'data');

        $this->postJson($this->url('/hifz'), $this->hifz([
            'from_surah' => 78, 'to_surah' => 79, 'whole_surah' => true,
        ]))->assertUnprocessable();

        // Garbage in the flag is a validation error, not silently "false".
        $this->postJson($this->url('/hifz'), $this->hifz([
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 10, 'whole_surah' => 'maybe',
        ]))->assertUnprocessable();

        $this->assertSame(0, HifzEntry::withoutMasjidScope()->count());
    }

    #[Test]
    public function whole_surah_is_refused_to_a_teacher_who_does_not_teach_quran(): void
    {
        $this->assign([GroupStaff::SUBJECT_ARABIC]);

        $this->postJson($this->url('/hifz'), [
            'membership_id' => $this->esraa->id, 'kind' => 'sabak',
            'from_surah' => 78, 'whole_surah' => true, 'quality' => 'good',
        ])->assertForbidden()->assertJsonPath('message', "You do not teach Qur'an in this class.");

        $this->assertSame(0, HifzEntry::withoutMasjidScope()->count());
    }

    // ================================================================ POINTS

    #[Test]
    public function each_students_total_is_the_net_the_summary_reports_negatives_included(): void
    {
        $this->assign([GroupStaff::SUBJECT_ISLAMIC_STUDIES]); // points are shared by every subject

        $this->award($this->esraa, 3);
        $this->award($this->esraa, 2);
        $this->award($this->esraa, -1, BehaviorSkill::POLARITY_NEGATIVE);
        $this->award($this->yusuf, 1);
        // A revoked award is in no total.
        $this->award($this->yusuf, 5)->delete();

        $res = $this->getJson($this->url('/awards/totals'))->assertOk();

        // Roster order, never points order.
        $this->assertSame(
            [$this->esraa->id, $this->yusuf->id],
            array_column($res->json('data.students'), 'membership_id')
        );
        $res->assertJsonPath('data.students.0.points', 4)
            ->assertJsonPath('data.students.0.awards', 3)
            ->assertJsonPath('data.students.1.points', 1)
            ->assertJsonPath('data.class.points', 5)
            ->assertJsonPath('data.class.awards', 4);

        // No rank, no position — nothing that orders children against each other.
        $this->assertSame(
            ['membership_id', 'contact', 'awards', 'points'],
            array_keys($res->json('data.students.0'))
        );

        // The SAME number the per-student summary (and the family summary built
        // the same way) reports.
        foreach ([$this->esraa, $this->yusuf] as $i => $m) {
            $this->assertSame(
                $this->getJson($this->url('/members/'.$m->id.'/awards/summary'))->json('data.totals.points'),
                $res->json("data.students.{$i}.points")
            );
        }
    }

    #[Test]
    public function a_student_with_no_awards_reads_zero_and_the_totals_are_refused_outside_the_class(): void
    {
        $this->assign(null);

        $this->getJson($this->url('/awards/totals'))->assertOk()
            ->assertJsonPath('data.students.0.points', 0)
            ->assertJsonPath('data.class.points', 0);

        // A teacher who does not lead this class gets nothing.
        Sanctum::actingAs($this->makeTeacher(), ['staff']);
        $this->getJson($this->url('/awards/totals'))->assertForbidden();
    }

    // =============================================================== helpers

    private function makeTeacher(): User
    {
        $u = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $u->id, 'role' => 'teacher', 'is_default' => true,
        ]);

        return $u;
    }

    private function enrol(string $first): GroupMembership
    {
        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => $first]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function assign(?array $subjects): void
    {
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'subjects' => $subjects,
            'assigned_at' => now(),
        ]);
    }

    private function cell(string $drill, string $status, array $extra = []): void
    {
        ArabicLetterProgress::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->esraa->id, 'alphabet' => 'arabic',
            'drill_id' => $drill, 'status' => $status,
        ], $extra));
    }

    private function row(string $drill): ArabicLetterProgress
    {
        return ArabicLetterProgress::withoutMasjidScope()
            ->where('group_membership_id', $this->esraa->id)->where('alphabet', 'arabic')
            ->where('drill_id', $drill)->sole();
    }

    private function award(GroupMembership $m, int $points, string $polarity = BehaviorSkill::POLARITY_POSITIVE): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $m->masjid_id, 'group_id' => $m->group_id, 'group_membership_id' => $m->id,
            'skill_label' => $polarity === BehaviorSkill::POLARITY_POSITIVE ? 'Participation' : 'Disruption',
            'skill_polarity' => $polarity, 'points' => $points,
        ]);
    }

    private function hifz(array $range): array
    {
        return ['membership_id' => $this->esraa->id, 'kind' => 'sabak', 'quality' => 'good'] + $range;
    }

    private function url(string $path): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}".rtrim($path, '/');
    }
}
