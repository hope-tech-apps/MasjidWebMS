<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Support\FormNotifier;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The office email names a calendar-sourced answer the way a person says it —
 * "Sunday, October 25, 2026" — never as the stored "2026-10-25", including after
 * that Sunday is closed.
 *
 * WHAT THE EMAIL CARRIES. FormNotifier::people() puts only number, dropdown and
 * choose-one answers on a person's line; a choose-any (checkboxGroup) answer is
 * not in the office email at all, raw or labelled — "the full answers are kept
 * in the admin". So the label is pinned on a calendar-sourced choose-one
 * question, and the choose-any answer is pinned as absent rather than leaking
 * as an ISO date.
 */
class FormSchoolDaysNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();
        $this->travelTo(Carbon::parse('2026-10-20 16:00:00'));

        $this->school = Masjid::create([
            'name' => 'Sunday School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'school',
            'timezone' => 'America/New_York',
        ]);

        $this->year = SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => '2026–27',
            'first_day' => '2026-10-11', 'last_day' => '2026-12-20',
        ]);
    }

    #[Test]
    public function the_office_email_names_the_chosen_sunday_in_words_even_after_it_closes(): void
    {
        Mail::fake();

        $form = Form::create([
            'masjid_id' => $this->school->id,
            'slug' => 'registration-'.uniqid(),
            'name' => 'Sunday School Registration',
            'schema' => ['sections' => [[
                'id' => 'family', 'title' => 'Your family',
                'fields' => [
                    ['name' => 'parentName', 'label' => 'Parent name', 'type' => 'text', 'required' => true],
                    ['name' => 'cleaningSunday', 'label' => 'Cleaning Sunday', 'type' => 'radio', 'optionsSource' => 'school_meeting_days'],
                    ['name' => 'extraSundays', 'label' => 'Extra Sundays', 'type' => 'checkboxGroup', 'optionsSource' => 'school_meeting_days'],
                ],
            ]]],
            'settings' => [
                'identity' => ['name' => 'parentName'],
                'notifyEmails' => ['office@example.com'],
            ],
        ]);

        $this->postJson("/api/v1/forms/{$form->id}/responses", ['data' => [
            'parentName' => 'Amal Yusuf',
            'cleaningSunday' => '2026-10-25',
            'extraSundays' => ['2026-11-08'],
        ]], ['masjid-id' => (string) $this->school->id])->assertOk();

        $html = null;
        Mail::assertQueued(FormResponseSubmitted::class, function (FormResponseSubmitted $mail) use (&$html) {
            $html = $mail->hasTo('office@example.com') ? $mail->render() : $html;

            return $mail->hasTo('office@example.com');
        });

        $this->assertStringContainsString('Cleaning Sunday Sunday, October 25, 2026', $html);
        $this->assertStringNotContainsString('2026-10-25', $html);
        $this->assertStringNotContainsString('2026-11-08', $html, 'a choose-any answer is not in the office email, and never as a raw date');

        // The Sunday is then called off. Anything the office is sent about this
        // registration from now on still reads it in words, marked by nothing raw.
        SchoolClosure::create([
            'masjid_id' => $this->school->id, 'school_year_id' => $this->year->id,
            'closed_on' => '2026-10-25', 'reason' => 'Snow day',
        ]);

        $people = FormNotifier::people($form->fresh(), FormResponse::where('form_id', $form->id)->firstOrFail());

        $this->assertSame('Amal Yusuf', $people[0]['name']);
        $this->assertSame('Cleaning Sunday Sunday, October 25, 2026', $people[0]['detail']);
    }
}
