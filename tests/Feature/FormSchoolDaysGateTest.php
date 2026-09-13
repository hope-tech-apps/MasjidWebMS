<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may build a calendar-sourced question, and what a family may pick.
 *
 *  - The builder is offered `options_sources` only by an organisation with the
 *    school calendar (or a SuperAdmin), and a NEW sourced question is refused
 *    without it — a masjid, or a school like Al-Razi that has not switched it on.
 *  - A question already stored survives the organisation losing the capability:
 *    the form stays editable.
 *  - The same choice twice is not two choices.
 */
class FormSchoolDaysGateTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $biss;     // a school with the calendar switched on
    private Masjid $alRazi;   // a school without it
    private Masjid $masjid;   // not a school at all

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        app(TenantContext::class)->forgetTenant();
        $this->travelTo(Carbon::parse('2026-10-20 16:00:00'));

        $this->biss = $this->makeMasjid('school', ['school_calendar' => true]);
        $this->alRazi = $this->makeMasjid('school');
        $this->masjid = $this->makeMasjid('masjid');

        foreach ([$this->biss, $this->alRazi] as $school) {
            SchoolYear::create([
                'masjid_id' => $school->id, 'label' => '2026–27',
                'first_day' => '2026-10-11', 'last_day' => '2026-12-20',
            ]);
        }
    }

    // ---------------------------------------------------------- duplicates

    #[Test]
    public function the_same_choice_twice_is_refused_even_when_two_are_asked_for(): void
    {
        $form = $this->storedForm($this->biss, [self::cleaning(['minSelections' => 2, 'maxSelections' => 2])]);

        $errors = $this->submit($form, ['cleaning' => ['2026-10-25', '2026-10-25']])->assertUnprocessable()->json('data');
        $this->assertContains('Each choice can be picked only once.', array_merge($errors['cleaning.0'] ?? [], $errors['cleaning.1'] ?? []));

        $this->submit($form, ['cleaning' => ['2026-10-25', '2026-11-01']])->assertOk();

        // A typed list too.
        $typed = $this->storedForm($this->biss, [self::typed()]);
        $errors = $this->submit($typed, ['shift' => ['am', 'am']])->assertUnprocessable()->json('data');
        $this->assertContains('Each choice can be picked only once.', array_merge($errors['shift.0'] ?? [], $errors['shift.1'] ?? []));
    }

    // ------------------------------------------------------ the builder palette

    #[Test]
    public function the_builder_is_offered_the_calendar_only_where_it_is_switched_on(): void
    {
        $this->actingAsAdminOf($this->biss);
        $this->getJson($this->formsUrl($this->biss, '/field-types'))->assertOk()
            ->assertJsonPath('options_sources.0.key', 'school_meeting_days')
            ->assertJsonPath('options_sources.0.available', true);

        $this->actingAsAdminOf($this->alRazi);
        $this->getJson($this->formsUrl($this->alRazi, '/field-types'))->assertOk()->assertJsonMissingPath('options_sources');

        $this->actingAsAdminOf($this->masjid);
        $this->getJson($this->formsUrl($this->masjid, '/field-types'))->assertOk()->assertJsonMissingPath('options_sources');

        // The platform operator passes, as at every capability gate.
        $this->actingAsSuperAdmin();
        $this->getJson($this->formsUrl($this->alRazi, '/field-types'))->assertOk()
            ->assertJsonPath('options_sources.0.key', 'school_meeting_days');
    }

    // ------------------------------------------------------------ saving

    #[Test]
    public function a_new_calendar_question_is_refused_without_the_capability(): void
    {
        $refusal = 'The school calendar is not switched on for this organisation, so a question cannot take its choices from it.';

        foreach ([$this->alRazi, $this->masjid] as $org) {
            $this->actingAsAdminOf($org);

            $this->postJson($this->formsUrl($org), $this->document([self::cleaning()]))
                ->assertUnprocessable()->assertJsonPath('data.schema.0', $refusal);
            $this->assertSame(0, Form::where('masjid_id', $org->id)->count());

            // Nothing else about building a form changes.
            $this->postJson($this->formsUrl($org), $this->document([self::typed()]))->assertCreated();
        }

        $this->actingAsAdminOf($this->biss);
        $this->postJson($this->formsUrl($this->biss), $this->document([self::cleaning()]))->assertCreated();

        $this->actingAsSuperAdmin();
        $this->postJson($this->formsUrl($this->alRazi), $this->document([self::cleaning()]))->assertCreated();
    }

    #[Test]
    public function a_stored_calendar_question_survives_the_organisation_losing_the_capability(): void
    {
        $form = $this->storedForm($this->biss, [self::cleaning()]);

        $this->biss->forceFill(['capability_overrides' => ['school_calendar' => false]])->save();
        $this->actingAsAdminOf($this->biss);
        $url = $this->formsUrl($this->biss, "/{$form->id}");

        // The stored question is left alone: the whole document, or just a rename.
        $this->putJson($url, ['name' => 'Renamed'] + $this->document([self::cleaning(), self::typed()]))->assertOk();
        $this->putJson($url, ['name' => 'Renamed again'])->assertOk();

        // A SECOND sourced question is new, and is refused.
        $this->putJson($url, $this->document([self::cleaning(), self::cleaning(['name' => 'cleaningAgain'])]))
            ->assertUnprocessable()->assertJsonPath('data.schema.0', fn (string $m) => str_contains($m, 'not switched on'));

        $this->assertSame('Renamed again', $form->fresh()->name);
    }

    // ------------------------------------------------------------- helpers

    private static function cleaning(array $overrides = []): array
    {
        return array_merge([
            'name' => 'cleaning', 'label' => 'Cleaning Sundays',
            'type' => 'checkboxGroup', 'optionsSource' => 'school_meeting_days',
        ], $overrides);
    }

    private static function typed(): array
    {
        return ['name' => 'shift', 'label' => 'Shift', 'type' => 'checkboxGroup', 'options' => [
            ['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon'],
        ]];
    }

    /** @param array<int,array<string,mixed>> $questions */
    private function document(array $questions): array
    {
        return [
            'slug' => 'registration-'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Registration',
            'schema' => ['sections' => [[
                'id' => 'family', 'title' => 'Family',
                'fields' => array_merge([['name' => 'parentName', 'label' => 'Parent', 'type' => 'text', 'required' => true]], $questions),
            ]]],
            'settings' => ['identity' => ['name' => 'parentName']],
        ];
    }

    /** @param array<int,array<string,mixed>> $questions */
    private function storedForm(Masjid $org, array $questions): Form
    {
        return Form::create(['masjid_id' => $org->id] + $this->document($questions));
    }

    private function submit(Form $form, array $answers)
    {
        return $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => ['parentName' => 'Amal Yusuf'] + $answers,
        ], ['masjid-id' => (string) $form->masjid_id]);
    }

    private function formsUrl(Masjid $org, string $path = ''): string
    {
        return "/api/admin/masjids/{$org->id}/forms".$path;
    }

    private function actingAsAdminOf(Masjid $org): void
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        Sanctum::actingAs($user->fresh());
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]));
    }

    private function makeMasjid(string $orgType, array $capabilities = []): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Gate Org '.uniqid(),
            'email' => 'gate'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => $orgType,
            'timezone' => 'America/New_York',
        ]);

        if ($capabilities !== []) {
            $masjid->forceFill(['capability_overrides' => $capabilities])->save();
        }

        return $masjid;
    }
}
