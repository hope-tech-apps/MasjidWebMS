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
}
