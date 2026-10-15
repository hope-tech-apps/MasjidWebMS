<?php

namespace Tests\Feature;

use App\Models\{Contact, Form, FormResponse, GroupMembership, Masjid, SchoolAdmissionMail, SchoolAdmissionSetting, SchoolApplication, SchoolApplicationHistory, SchoolReenrolment, SchoolReenrolmentAnswer, SchoolReenrolmentDeposit, User};
use App\Services\Schools\{SchoolApplications, SchoolReenrolments};
use App\Support\{SchoolAdmissionsMode, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Mail, Queue};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

class SchoolAdmissionsTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 14:00:00', 'UTC'));
        Queue::fake(); Mail::fake();
    }
    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }
    private function enable(Masjid $school, string $key = 'school_reenrolment'): void
    {
        $school->refresh()->forceFill(['capability_overrides' => array_merge($school->capability_overrides ?? [], [$key => true])])->save();
    }
    private function office(Masjid $school): User
    {
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15555550123', 'email' => 'office-login@example.invalid']);
        $school->forceFill(['user_id' => $user->id])->save(); Sanctum::actingAs($user); return $user;
    }
    private function campaign(Masjid $school, ?Form $deposit = null): SchoolReenrolment
    {
        $this->enable($school); $year = $this->year($school);
        return SchoolReenrolment::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'opens_on' => '2026-10-19', 'closes_on' => '2026-10-30', 'deposit_form_id' => $deposit?->id]);
    }
    private function url($child, $campaign): string
    {
        return str_replace('/attendance', '/reenrolment/'.$campaign->id, $this->familyUrl($child));
    }
    private function form(Masjid $school, bool $paying = false): Form
    {
        return Form::create(['masjid_id' => $school->id, 'name' => 'Practice registration', 'slug' => 'practice-registration', 'is_active' => true, 'schema' => ['sections' => [
            ['id' => 'guardian', 'fields' => [['name' => 'parentEmail', 'type' => 'email', 'label' => 'Guardian email'], ['name' => 'parentName', 'type' => 'text', 'label' => 'Guardian name']]],
            ['id' => 'children', 'repeatable' => true, 'fields' => [['name' => 'firstName', 'type' => 'text', 'label' => 'First name'], ['name' => 'lastName', 'type' => 'text', 'label' => 'Last name'], ['name' => 'grade', 'type' => 'text', 'label' => 'Grade']]],
        ]], 'settings' => ['identity' => ['email' => 'parentEmail', 'name' => 'parentName'], ...($paying ? ['fee' => ['amount' => 25], 'payment' => ['officePayment' => true]] : [])]]);
    }
    private function response(Form $form, string $first = 'Sample', string $grade = '3rd', string $when = '2026-10-19 10:00:00'): FormResponse
    {
        return FormResponse::create(['masjid_id' => $form->masjid_id, 'form_id' => $form->id, 'data' => ['parentEmail' => 'guardian@example.invalid', 'parentName' => 'Practice Guardian', 'children' => [['firstName' => $first, 'lastName' => 'Student', 'grade' => $grade]]], 'respondent_email' => 'guardian@example.invalid', 'respondent_name' => 'Practice Guardian', 'entry_count' => 1, 'submitted_at' => $when, 'status' => 'new']);
    }
    private function application(Form $form): void
    {
        $school = Masjid::findOrFail($form->masjid_id); $this->enable($school, 'school_applications');
        SchoolAdmissionSetting::create(['masjid_id' => $school->id, 'application_form_ids' => [$form->id]]);
    }

    #[Test]
    public function grants_default_off_and_are_hidden_and_disabled_noops_leave_no_override_or_ledger(): void
    {
        $school = $this->makeMasjid(); $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550124']); Sanctum::actingAs($super);
        foreach (['school_reenrolment', 'school_applications'] as $key) {
            $this->assertSame(['masjid' => false, 'school' => false, 'community' => false], config('capabilities.'.$key.'.defaults'));
            $this->assertArrayNotHasKey($key, $school->capabilities);
            $this->patchJson('/api/admin/masjids/'.$school->id.'/capabilities/'.$key, ['enabled' => false])->assertOk();
            $this->assertArrayNotHasKey($key, $school->fresh()->capability_overrides ?? []);
            $this->assertDatabaseMissing('masjid_capability_changes', ['capability' => $key]);
            foreach (['/api/admin/masjids/'.$school->id.'/capabilities', '/api/admin/studio/catalogue?org_type=school'] as $url) $this->assertStringNotContainsString($key, $this->getJson($url)->assertOk()->getContent());
        }
    }

    #[Test]
    public function family_guard_dates_and_shared_last_answer_are_enforced_and_off_is_hidden(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $child->update(['grade_label' => '3rd']);
        $served = $this->enrol($child->group); $served->update(['grade_label' => '4th']);
        $first = $this->guardian($child); $second = $this->guardian($child, Contact::factory()->create(['masjid_id' => $school->id, 'first_name' => 'Second', 'last_name' => 'Guardian', 'email' => 'second@example.invalid']));
        $this->family($first); $url = $this->url($child, $campaign);
        $this->getJson($url)->assertOk()->assertJsonPath('data.next_grade', '4th')->assertJsonPath('data.answer', null);
        $this->putJson($url, ['answer' => 'returning'])->assertOk()->assertJsonPath('data.answer', 'returning')->assertJsonPath('data.answered_by', 'Practice Guardian');
        $child->update(['grade_label' => '5th']);
        $this->family($second); $this->getJson($url)->assertOk()->assertJsonPath('data.answered_by', 'Practice Guardian')->assertJsonPath('data.next_grade', '4th');
        $this->putJson($url, ['answer' => 'not_returning'])->assertOk()->assertJsonPath('data.answered_by', 'Second Guardian');
        $this->assertDatabaseCount('school_reenrolment_answers', 1);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-31 12:00:00', 'UTC'));
        $this->putJson($url, ['answer' => 'returning'])->assertUnprocessable();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-18 12:00:00', 'UTC'));
        $this->putJson($url, ['answer' => 'returning'])->assertUnprocessable();
        app(TenantContext::class)->forgetTenant(); $school->forceFill(['capability_overrides' => []])->save();
        $this->getJson($url)->assertForbidden();
    }

    #[Test]
    public function another_familys_child_and_foreign_campaign_are_refused(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $other = $this->enrol($child->group); $guardian = $this->guardian($child);
        $foreignSchool = $this->makeMasjid('b'); $foreign = $this->campaign($foreignSchool);
        $this->family($guardian);
        foreach (['get', 'put'] as $verb) {
            $this->{$verb.'Json'}($this->url($other, $campaign), ['answer' => 'returning'])->assertForbidden();
            $this->{$verb.'Json'}($this->url($child, $foreign), ['answer' => 'returning'])->assertNotFound();
        }
        $this->assertDatabaseCount('school_reenrolment_answers', 0);
    }

    #[Test]
    public function office_records_paper_answer_and_next_grade_without_moving_the_child(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $office = $this->office($school);
        $url = '/api/admin/masjids/'.$school->id.'/reenrolment/'.$campaign->id;
        $this->putJson($url.'/children/'.$child->contact_id, ['answer' => 'returning', 'next_grade' => '5th'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.children.0.recorded_by_office', true)->assertJsonPath('data.children.0.next_grade', '5th');
        $this->assertDatabaseHas('school_reenrolment_answers', ['student_contact_id' => $child->contact_id, 'answered_by_user_id' => $office->id, 'answered_by_contact_id' => null]);
        $this->assertDatabaseCount('group_memberships', 1);
    }

    #[Test]
    public function deposit_is_hidden_on_a_free_form_and_uses_only_the_linked_child_and_year_payment(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $this->family($this->guardian($child));
        $this->putJson($this->url($child, $campaign), ['answer' => 'returning'])->assertOk()->assertJsonMissingPath('data.deposit');
        app(TenantContext::class)->forgetTenant(); $form->update(['settings' => array_merge($form->settings, ['fee' => ['amount' => 25], 'payment' => ['officePayment' => true]])]);
        $data = $this->getJson($this->url($child, $campaign))->assertOk()->json('data');
        $this->assertFalse($data['deposit']['paid']); $this->assertNotEmpty($data['deposit']['url']);
        $response = $this->response($form); $response->forceFill(['amount_due_minor' => 2500, 'payment_method' => 'office', 'payment_status' => 'paid', 'paid_at' => now(), 'currency' => 'USD', 'total_minor' => 2500])->save();
        $this->getJson($this->url($child, $campaign))->assertOk()->assertJsonPath('data.deposit.paid', false);
        SchoolReenrolmentDeposit::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'form_response_id' => $response->id]);
        $this->getJson($this->url($child, $campaign))->assertOk()->assertJsonPath('data.deposit.paid', true);
        $response->forceFill(['charge_flag' => 'refunded'])->save();
        $this->getJson($this->url($child, $campaign))->assertOk()->assertJsonPath('data.deposit.paid', false);
    }

    #[Test]
    public function reminders_are_office_only_unanswered_confirmed_guardians_grouped_logged_and_never_automatic(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $class = $this->classroom($school); $a = $this->enrol($class); $b = $this->enrol($class); $c = $this->enrol($class);
        $guardian = $this->guardian($a); $this->guardian($b, $guardian); $this->guardian($c, $guardian); $pending = $this->guardian($a, Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'pending@example.invalid']), 'self_asserted');
        $this->family($guardian); $this->putJson($this->url($c, $campaign), ['answer' => 'returning'])->assertOk();
        Queue::assertNothingPushed(); $this->assertDatabaseCount('school_admission_mails', 0);
        $this->office($school); $url = '/api/admin/masjids/'.$school->id.'/reenrolment/'.$campaign->id.'/reminders';
        $this->postJson($url)->assertOk()->assertJsonPath('data.queued', 1);
        $this->postJson($url)->assertOk()->assertJsonPath('data.queued', 0);
        $row = SchoolAdmissionMail::firstOrFail(); $this->assertSame($guardian->id, $row->guardian_contact_id); $this->assertCount(2, $row->children);
        Queue::assertPushed(\App\Jobs\SendSchoolAdmissionMail::class, 1);
        $job = unserialize(serialize(new \App\Jobs\SendSchoolAdmissionMail($row->id, $school->id, now()->addDay()->timestamp)));
        $job->handle(); $job->handle();
        $this->assertSame('sent', $row->fresh()->status); Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, 1);
    }

    #[Test]
    public function application_transitions_have_history_edited_text_one_email_per_guardian_and_correction_sends_none(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form); $response = $this->response($form); $office = $this->office($school);
        SchoolAdmissionSetting::where('masjid_id', $school->id)->update(['accepted_text' => 'Welcome to {school}. A place is offered for {children}.']);
        $url = '/api/admin/masjids/'.$school->id.'/applications/'.$response->id.'/status';
        $this->putJson($url, ['status' => 'accepted', 'note' => 'Staff only practice note'])->assertOk();
        $this->putJson($url, ['status' => 'accepted'])->assertOk();
        $this->assertDatabaseCount('school_application_histories', 1); $this->assertDatabaseCount('school_admission_mails', 1);
        $mail = SchoolAdmissionMail::firstOrFail(); $this->assertSame('Welcome to Practice School a. A place is offered for Sample Student.', $mail->body); $this->assertStringNotContainsString('Staff only', $mail->body);
        $this->assertSame('database', Queue::pushed(\App\Jobs\SendSchoolAdmissionMail::class)->first()->connection);
        $this->putJson($url, ['status' => 'wait_listed', 'without_email' => 'true', 'note' => 'Correction'])->assertOk();
        $this->assertDatabaseCount('school_application_histories', 2); $this->assertDatabaseCount('school_admission_mails', 1);
        $this->assertDatabaseHas('school_application_histories', ['changed_by_user_id' => $office->id, 'status' => 'wait_listed', 'emailed' => false]);
    }

    #[Test]
    public function waitlist_is_arrival_order_per_normalised_grade_and_later_acceptance_retains_history(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form);
        $later = $this->response($form, 'Later', 'Grade 3', '2026-10-19 10:00:00'); $earlier = $this->response($form, 'Earlier', '3rd', '2026-10-18 10:00:00'); $different = $this->response($form, 'Other', '4th', '2026-10-17 10:00:00');
        $this->office($school);
        foreach ([$later, $earlier, $different] as $r) $this->putJson('/api/admin/masjids/'.$school->id.'/applications/'.$r->id.'/status', ['status' => 'wait_listed', 'without_email' => true])->assertOk();
        $rows = $this->getJson('/api/admin/masjids/'.$school->id.'/applications')->assertOk()->json('data.children');
        $positions = collect($rows)->keyBy('response_id'); $this->assertSame(1, $positions[$earlier->id]['waitlist_position']); $this->assertSame(2, $positions[$later->id]['waitlist_position']); $this->assertSame(1, $positions[$different->id]['waitlist_position']);
        $this->putJson('/api/admin/masjids/'.$school->id.'/applications/'.$earlier->id.'/status', ['status' => 'accepted'])->assertOk();
        $this->assertDatabaseCount('school_application_histories', 4);
    }

    #[Test]
    public function accepted_roster_add_matches_normalised_guardian_email_confirms_edges_and_refuses_an_existing_child(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form); $response = $this->response($form); $class = $this->classroom($school); $office = $this->office($school);
        $guardian = Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'GUARDIAN@example.invalid']);
        $url = '/api/admin/masjids/'.$school->id.'/applications/'.$response->id;
        $this->postJson($url.'/roster', ['entry_index' => 0, 'group_id' => $class->id])->assertUnprocessable();
        $this->putJson($url.'/status', ['status' => 'accepted', 'without_email' => true])->assertOk();
        $this->postJson($url.'/roster', ['entry_index' => 0, 'group_id' => $class->id])->assertCreated();
        $this->assertDatabaseHas('group_memberships', ['contact_id' => $guardian->id, 'role' => 'guardian', 'provenance' => 'confirmed', 'confirmed_by_user_id' => $office->id]);
        $this->assertSame(2, Contact::count());
        $this->postJson($url.'/roster', ['entry_index' => 0, 'group_id' => $class->id])->assertUnprocessable();
        $again = $this->response($form); $this->putJson('/api/admin/masjids/'.$school->id.'/applications/'.$again->id.'/status', ['status' => 'accepted', 'without_email' => true])->assertOk();
        $otherClass = $this->classroom($school, 'Another class');
        $this->postJson('/api/admin/masjids/'.$school->id.'/applications/'.$again->id.'/roster', ['entry_index' => 0, 'group_id' => $otherClass->id])->assertUnprocessable();
        $this->assertDatabaseCount('group_memberships', 2);
    }

    #[Test]
    public function deposit_prefill_submits_through_existing_forms_and_only_office_settlement_marks_the_child_paid(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian);
        $url = $this->url($child, $campaign); $this->putJson($url, ['answer' => 'returning'])->assertOk();
        $page = $this->getJson($url.'/deposit')->assertOk()->json('data');
        $this->assertSame('Sample', $page['data']['children'][0]['firstName']); $this->assertSame($guardian->email, $page['data']['parentEmail']);
        $this->postJson($url.'/deposit/quote', ['data' => $page['data'], 'pay_with' => 'office'])->assertOk()->assertJsonPath('data.total_minor', 2500);
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $response = $this->postJson('/api/v1/forms/'.$form->id.'/responses', ['data' => $page['data'], 'reenrolment_token' => $page['token'], 'client_submission_key' => 'deposit-practice-submission-001', 'pay_with' => 'office'], ['masjid-id' => $school->id])->assertOk();
        $this->assertDatabaseCount('school_reenrolment_deposits', 1);
        $payment = FormResponse::firstOrFail(); $this->assertSame('office', $payment->payment_method); $this->assertSame(2500, $payment->amount_due_minor);
        $this->family($guardian); $this->getJson($url)->assertOk()->assertJsonPath('data.deposit.paid', false);
        $pageAgain = $this->getJson($url.'/deposit')->assertOk()->json('data'); $this->assertContains($payment->uuid, $pageAgain['response_uuids']);
        $this->assertStringContainsString('form_response='.$payment->uuid, $this->getJson($url)->assertOk()->json('data.deposit.url'));
        $office = $this->office($school); $payment->markExternalPaid($office, 'bank_transfer');
        $this->family($guardian); $this->getJson($url)->assertOk()->assertJsonPath('data.deposit.paid', true);
    }

    /** Walk finding: a deposit form without child first/last name questions left the family an empty, $0 form. */
    #[Test]
    public function a_deposit_form_must_ask_each_childs_first_and_last_name(): void
    {
        $school = $this->makeMasjid(); $office = $this->office($school);
        $form = \App\Models\Form::create(['masjid_id' => $school->id, 'name' => 'Full name only', 'slug' => 'full-name-'.uniqid(), 'schema' => ['sections' => [['id' => 'kids', 'title' => 'Children', 'repeatable' => true, 'fields' => [['name' => 'childName', 'label' => 'Child full name', 'type' => 'text']]]]]]);
        $year = $this->campaign($school, $this->form($school, true))->school_year_id;
        $this->actingAs($office)->putJson('/api/admin/masjids/'.$school->id.'/reenrolment', ['school_year_id' => $year, 'opens_on' => now()->toDateString(), 'closes_on' => now()->addWeek()->toDateString(), 'deposit_form_id' => $form->id])
            ->assertUnprocessable()->assertJsonFragment(['Choose a form that asks each child\'s first name and last name (as separate questions).']);
    }

    /** Walk finding: the screen posts multipart with nested data fields; a JSON string in `data` was refused. */
    #[Test]
    public function the_deposit_form_submits_as_the_screen_sends_it_multipart_with_nested_fields(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian);
        $url = $this->url($child, $campaign); $this->putJson($url, ['answer' => 'returning'])->assertOk();
        $page = $this->getJson($url.'/deposit')->assertOk()->json('data');
        $flat = json_decode(json_encode($page['data']), true);
        array_walk_recursive($flat, function (&$v) { $v = is_bool($v) ? ($v ? '1' : '0') : ($v === null ? '' : (string) $v); });
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->post('/api/v1/forms/'.$form->id.'/responses', ['data' => $flat, 'reenrolment_token' => $page['token'], 'client_submission_key' => 'deposit-practice-submission-002', 'pay_with' => 'office', 'cover_fees' => '0'], ['masjid-id' => $school->id, 'Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('school_reenrolment_deposits', 1);
    }

    #[Test]
    public function a_deposit_token_cannot_pay_for_edited_children_another_form_or_a_revoked_guardian(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian);
        $url = $this->url($child, $campaign); $this->putJson($url, ['answer' => 'returning'])->assertOk(); $page = $this->getJson($url.'/deposit')->assertOk()->json('data');
        $changed = $page['data']; $changed['children'][0]['firstName'] = 'Another';
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $payload = ['data' => $changed, 'reenrolment_token' => $page['token'], 'client_submission_key' => 'deposit-practice-submission-002', 'pay_with' => 'office'];
        $this->postJson('/api/v1/forms/'.$form->id.'/responses', $payload, ['masjid-id' => $school->id])->assertUnprocessable();
        $this->assertDatabaseCount('form_responses', 0);
        GroupMembership::where('contact_id', $guardian->id)->delete(); $payload['data'] = $page['data'];
        $this->postJson('/api/v1/forms/'.$form->id.'/responses', $payload, ['masjid-id' => $school->id])->assertForbidden();
        $this->assertDatabaseCount('form_responses', 0);
    }

    #[Test]
    public function a_deposit_card_return_is_bound_to_the_signed_family_path_and_trusted_app_origin(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian);
        $url = $this->url($child, $campaign); $this->putJson($url, ['answer' => 'returning'])->assertOk(); $page = $this->getJson($url.'/deposit')->assertOk()->json('data');
        config(['app.url' => 'https://portal.example.invalid', 'forms.payment_return_origins' => []]);
        $path = '/family/'.$school->id.'/deposit/'.$campaign->id.'/'.$child->group_id.'/'.$child->id;
        $request = \Illuminate\Http\Request::create('/', 'POST', ['reenrolment_token' => $page['token'], 'return_path' => $path]); $request->headers->set('Origin', 'https://portal.example.invalid');
        $this->assertSame('https://portal.example.invalid'.$path, \App\Support\FormPaymentReturn::base($request, $school->id));
        $request->headers->set('Origin', 'https://untrusted.example.invalid'); $this->assertNull(\App\Support\FormPaymentReturn::base($request, $school->id));
        $request->headers->set('Origin', 'https://portal.example.invalid'); $request->merge(['return_path' => '/other']); $this->assertNull(\App\Support\FormPaymentReturn::base($request, $school->id));
    }

    #[Test]
    public function family_shell_is_identical_off_and_lists_campaigns_only_with_its_own_switch_on(): void
    {
        $school = $this->makeMasjid(); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian);
        $url = '/api/family/masjids/'.$school->id.'/groups/'.$child->group_id;
        $before = $this->getJson($url)->assertOk()->json(); $this->assertArrayNotHasKey('reenrolment', $before['meta']);
        app(TenantContext::class)->forgetTenant(); $this->enable($school, 'school_applications');
        $this->assertSame($before, $this->getJson($url)->assertOk()->json());
        app(TenantContext::class)->forgetTenant(); $campaign = $this->campaign($school);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.reenrolment.0.id', $campaign->id);
    }

    #[Test]
    public function all_status_messages_use_both_guardian_addresses_and_plain_edited_text_without_office_notes(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $schema = $form->schema; $schema['sections'][0]['fields'][] = ['name' => 'parent2Email', 'type' => 'email', 'label' => 'Second guardian email']; $form->update(['schema' => $schema]);
        $this->application($form); $response = $this->response($form); $response->update(['data' => array_merge($response->data, ['parent2Email' => 'SECOND@example.invalid'])]); $this->office($school);
        $settings = ['accepted_text' => 'Accepted {children} at {school}.', 'wait_listed_text' => 'Waiting {children} at {school}.', 'declined_text' => 'Declined {children} at {school}.'];
        $base = '/api/admin/masjids/'.$school->id.'/applications'; $this->putJson($base.'/settings', $settings)->assertOk();
        foreach (['accepted', 'wait_listed', 'declined'] as $status) $this->putJson($base.'/'.$response->id.'/status', ['status' => $status, 'note' => 'Private office note'])->assertOk();
        $this->assertDatabaseCount('school_admission_mails', 6); $this->assertDatabaseCount('school_application_histories', 3);
        foreach (SchoolAdmissionMail::all() as $mail) { $this->assertStringNotContainsString('Private', $mail->body); $this->assertStringNotContainsString('{', $mail->body); (new \App\Jobs\SendSchoolAdmissionMail($mail->id, $school->id, now()->addDay()->timestamp))->handle(); }
        Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, 6);
        $this->putJson($base.'/settings', array_replace($settings, ['accepted_text' => 'Unsafe {note}']))->assertUnprocessable();
    }

    #[Test]
    public function a_reminder_rechecks_answers_and_confirmed_standing_at_send_time_and_logs_transport_failure(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->office($school);
        $url = '/api/admin/masjids/'.$school->id.'/reenrolment/'.$campaign->id; $this->postJson($url.'/reminders')->assertOk(); $row = SchoolAdmissionMail::firstOrFail();
        $this->putJson($url.'/children/'.$child->contact_id, ['answer' => 'returning'])->assertOk();
        (new \App\Jobs\SendSchoolAdmissionMail($row->id, $school->id, now()->addDay()->timestamp))->handle(); $this->assertSame('skipped', $row->fresh()->status); Mail::assertNothingSent();
        SchoolReenrolmentAnswer::query()->delete(); $this->postJson($url.'/reminders')->assertOk(); $row = SchoolAdmissionMail::orderByDesc('id')->firstOrFail();
        config(['app.env' => 'staging']); Mail::swap(app('mail.manager')); app('mail.manager')->purge();
        (new \App\Jobs\SendSchoolAdmissionMail($row->id, $school->id, now()->addDay()->timestamp))->handle(); $this->assertSame('failed', $row->fresh()->status); $this->assertNotNull($row->fresh()->failed_at);
    }

    #[Test]
    public function office_cannot_open_another_schools_application_form_response_class_or_campaign(): void
    {
        $a = $this->makeMasjid(); $ownForm = $this->form($a); $this->application($ownForm); $ownResponse = $this->response($ownForm); $ownCampaign = $this->campaign($a);
        $b = $this->makeMasjid('b'); $foreignForm = $this->form($b); $this->application($foreignForm); $foreignResponse = $this->response($foreignForm); $foreignCampaign = $this->campaign($b); $foreignClass = $this->classroom($b);
        $this->office($a); $base = '/api/admin/masjids/'.$a->id;
        $this->getJson($base.'/applications/'.$foreignResponse->id)->assertNotFound();
        $this->putJson($base.'/applications/'.$foreignResponse->id.'/status', ['status' => 'accepted'])->assertNotFound();
        $this->putJson($base.'/applications/forms/'.$foreignForm->id, ['application' => true])->assertNotFound();
        $this->putJson($base.'/applications/'.$ownResponse->id.'/status', ['status' => 'accepted', 'without_email' => true])->assertOk();
        $this->postJson($base.'/applications/'.$ownResponse->id.'/roster', ['entry_index' => 0, 'group_id' => $foreignClass->id])->assertNotFound();
        $this->getJson($base.'/reenrolment/'.$foreignCampaign->id)->assertNotFound();
        $this->postJson($base.'/reenrolment/'.$foreignCampaign->id.'/reminders')->assertNotFound();
        $this->getJson('/api/admin/masjids/'.$b->id.'/applications')->assertForbidden();
    }

    #[Test]
    public function flat_child_forms_do_not_mistake_repeatable_emergency_contacts_for_children_and_grade_aliases_are_read(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $schema = $form->schema;
        $schema['sections'][1] = ['id' => 'student', 'fields' => [['name' => 'studentFirstName', 'type' => 'text'], ['name' => 'studentLastName', 'type' => 'text'], ['name' => 'grade', 'type' => 'text']]];
        $schema['sections'][] = ['id' => 'emergency', 'repeatable' => true, 'fields' => [['name' => 'name', 'type' => 'text'], ['name' => 'phone', 'type' => 'tel']]];
        $form->update(['schema' => $schema]); $response = $this->response($form); $response->update(['data' => ['studentFirstName' => 'Sample', 'studentLastName' => 'Student', 'grade' => '4th', 'parentEmail' => 'guardian@example.invalid', 'emergency' => [['name' => 'Practice Emergency Contact', 'phone' => '+15555550111']]]]);
        $children = \App\Services\Schools\SchoolAdmissionIdentity::children($form, $response);
        $this->assertCount(1, $children); $this->assertSame('Sample', $children[0]['first_name']); $this->assertSame('4th', $children[0]['grade']);
        $form->update(['slug' => 'flat-child-form']); $other = $this->form($school); $response = $this->response($other); $data = $response->data; $data['children'][0]['schoolGrade'] = $data['children'][0]['grade']; unset($data['children'][0]['grade']); $schema = $other->schema; $schema['sections'][1]['fields'][2]['name'] = 'schoolGrade'; $other->update(['schema' => $schema]); $response->update(['data' => $data]);
        $this->assertSame('3rd', \App\Services\Schools\SchoolAdmissionIdentity::children($other, $response)[0]['grade']);
    }

    #[Test]
    public function a_same_name_child_with_a_different_guardian_already_on_another_roster_needs_identity_review(): void
    {
        $school = $this->makeMasjid(); $child = $this->enrol($this->classroom($school)); $this->guardian($child, Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'other-guardian@example.invalid']));
        $form = $this->form($school); $this->application($form); $response = $this->response($form); $target = $this->classroom($school, 'Target Class'); $this->office($school); $base = '/api/admin/masjids/'.$school->id.'/applications/'.$response->id;
        $this->putJson($base.'/status', ['status' => 'accepted', 'without_email' => true])->assertOk();
        $this->postJson($base.'/roster', ['entry_index' => 0, 'group_id' => $target->id])->assertUnprocessable(); $this->assertSame(2, Contact::count());
    }

    #[Test]
    public function a_school_year_with_reenrolment_history_is_kept_and_populated_rollback_refuses(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $actor = $this->office($school);
        \App\Support\CapabilityWriter::apply($school, ['school_reenrolment' => false], $actor->id);
        $this->assertTrue($school->fresh()->capability_overrides[\App\Support\SchoolAdmissionsMode::RECORDS]);
        $this->assertArrayNotHasKey(\App\Support\SchoolAdmissionsMode::RECORDS, $school->fresh()->attributesToArray()['capability_overrides']);
        $this->deleteJson('/api/admin/masjids/'.$school->id.'/school-calendar/years/'.$campaign->school_year_id)->assertUnprocessable();
        $migration = require database_path('migrations/2026_10_14_235000_create_school_admissions.php');
        try { $migration->down(); $this->fail('Populated rollback must refuse.'); } catch (\RuntimeException $e) { $this->assertStringContainsString('Refusing', $e->getMessage()); }
        $this->assertDatabaseHas('school_reenrolments', ['id' => $campaign->id]);
    }

    #[Test]
    public function reminder_children_json_keeps_a_members_office_record_after_the_roster_has_gone(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school);
        $child = new Contact(); $child->forceFill(['masjid_id' => $school->id, 'first_name' => 'Sample', 'last_name' => 'Student', 'email' => 'member@example.invalid', 'login_email' => 'member@example.invalid', 'signup_source' => 'app', 'verified_at' => now()])->save();
        SchoolAdmissionMail::create(['masjid_id' => $school->id, 'kind' => 'reminder', 'reenrolment_id' => $campaign->id, 'guardian_email' => 'guardian@example.invalid', 'children' => [['student_contact_id' => $child->id, 'name' => 'Sample Student']], 'subject' => 'Practice', 'body' => 'Practice', 'status' => 'sent']);
        $result = app(\App\Services\Member\MemberAccountDeletion::class)->delete($child, 'app');
        $this->assertSame('login_removed', $result['outcome']); $this->assertContains('school_admission_mails', $result['kept_because']);
        $this->assertNotNull(Contact::withTrashed()->find($child->id));
    }

    #[Test]
    public function child_intake_answers_deposits_and_reminder_links_survive_contact_merge_with_the_switch_off(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $target = Contact::factory()->create(['masjid_id' => $school->id, 'first_name' => 'Kept', 'last_name' => 'Student']);
        $form = $this->form($school); $response = $this->response($form);
        SchoolReenrolmentAnswer::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'answer' => 'returning']);
        SchoolReenrolmentDeposit::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'form_response_id' => $response->id]);
        $mail = SchoolAdmissionMail::create(['masjid_id' => $school->id, 'kind' => 'reminder', 'reenrolment_id' => $campaign->id, 'guardian_email' => 'guardian@example.invalid', 'children' => [['student_contact_id' => $child->contact_id, 'name' => 'Sample Student']], 'subject' => 'Practice', 'body' => 'Practice', 'status' => 'sent']);
        $actor = $this->office($school); \App\Support\CapabilityWriter::apply($school, ['school_reenrolment' => false], $actor->id);
        $this->postJson('/api/admin/masjids/'.$school->id.'/contacts/'.$child->contact_id.'/merge', ['target_contact_id' => $target->id])->assertOk();
        $this->assertDatabaseHas('school_reenrolment_answers', ['student_contact_id' => $target->id, 'answer' => 'returning']);
        $this->assertDatabaseHas('school_reenrolment_deposits', ['student_contact_id' => $target->id, 'form_response_id' => $response->id]);
        $this->assertSame($target->id, $mail->fresh()->children[0]['student_contact_id']);
    }

    #[Test]
    public function child_intake_overlap_refuses_contact_merge_and_keeps_both_answers(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $target = Contact::factory()->create(['masjid_id' => $school->id]);
        foreach ([$child->contact_id, $target->id] as $id) SchoolReenrolmentAnswer::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $id, 'answer' => 'returning']);
        $this->office($school); $this->postJson('/api/admin/masjids/'.$school->id.'/contacts/'.$child->contact_id.'/merge', ['target_contact_id' => $target->id])->assertUnprocessable();
        $this->assertDatabaseCount('school_reenrolment_answers', 2); $this->assertNotNull(Contact::find($child->contact_id));
    }

    #[Test]
    public function a_generic_fee_only_form_links_the_selected_child_from_signed_context_without_child_questions(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $schema = $form->schema; $schema['sections'] = [$schema['sections'][0]]; $form->update(['schema' => $schema]);
        $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian); $url = $this->url($child, $campaign);
        $this->putJson($url, ['answer' => 'returning'])->assertOk(); $page = $this->getJson($url.'/deposit')->assertOk()->assertJsonPath('data.child_name', 'Sample Student')->json('data');
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->postJson('/api/v1/forms/'.$form->id.'/responses', ['data' => $page['data'], 'reenrolment_token' => $page['token'], 'client_submission_key' => 'practice-generic-deposit-001', 'pay_with' => 'office'], ['masjid-id' => $school->id])->assertOk();
        $this->assertDatabaseHas('school_reenrolment_deposits', ['student_contact_id' => $child->contact_id, 'reenrolment_id' => $campaign->id]);
    }

    #[Test]
    public function office_actor_deletion_keeps_answers_application_history_and_mail_with_null_attribution(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $form = $this->form($school); $response = $this->response($form);
        $actor = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15555550128', 'name' => 'Practice Recorder', 'email' => 'recorder@example.invalid']);
        $answer = SchoolReenrolmentAnswer::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'answer' => 'returning', 'recorded_by_office' => true, 'answered_by_user_id' => $actor->id]);
        $history = SchoolApplicationHistory::create(['masjid_id' => $school->id, 'form_response_id' => $response->id, 'status' => 'accepted', 'changed_by_user_id' => $actor->id]);
        $mail = SchoolAdmissionMail::create(['masjid_id' => $school->id, 'kind' => 'application', 'guardian_email' => 'guardian@example.invalid', 'subject' => 'Practice', 'body' => 'Practice', 'requested_by_user_id' => $actor->id, 'status' => 'sent']);
        $actor->forceDelete(); $this->assertNull($answer->fresh()->answered_by_user_id); $this->assertTrue($answer->fresh()->recorded_by_office); $this->assertNull($history->fresh()->changed_by_user_id); $this->assertNull($mail->fresh()->requested_by_user_id);
    }

    #[Test]
    public function application_form_setting_is_a_validated_per_form_flag_and_never_needs_the_form_editing_grant(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->enable($school, 'school_applications'); $this->office($school);
        $base = '/api/admin/masjids/'.$school->id.'/applications/forms/'.$form->id;
        $this->getJson($base)->assertOk()->assertJsonPath('data.application', false);
        $this->putJson($base, ['application' => 'true'])->assertOk()->assertJsonPath('data.application', true);
        $this->putJson($base, [])->assertUnprocessable();
        $this->putJson($base, ['application' => 'false'])->assertOk()->assertJsonPath('data.application', false);
        $schema = $form->schema; $schema['sections'] = [$schema['sections'][0]]; $form->update(['schema' => $schema]);
        $this->putJson($base, ['application' => true])->assertUnprocessable();
    }

    #[Test]
    public function enabled_family_intake_readers_never_copy_health_or_office_notes_and_all_child_paths_use_the_guard(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $campaign = $this->campaign($school, $form); $child = $this->enrol($this->classroom($school)); $other = $this->enrol($child->group); $guardian = $this->guardian($child);
        \App\Models\StudentAllergy::create(['masjid_id' => $school->id, 'contact_id' => $child->contact_id, 'allergies' => 'PRIVATE_HEALTH_PRACTICE', 'source' => 'office']);
        $child->contact->forceFill(['notes' => 'PRIVATE_OFFICE_PRACTICE'])->save(); $this->family($guardian); $url = $this->url($child, $campaign); $this->putJson($url, ['answer' => 'returning'])->assertOk();
        foreach ([$url, $url.'/deposit', '/api/family/masjids/'.$school->id.'/groups/'.$child->group_id] as $path) {
            $body = $this->getJson($path)->assertOk()->getContent(); $this->assertStringNotContainsString('PRIVATE_HEALTH_PRACTICE', $body); $this->assertStringNotContainsString('PRIVATE_OFFICE_PRACTICE', $body);
        }
        $this->getJson($this->url($other, $campaign).'/deposit')->assertForbidden();
        $this->postJson($this->url($other, $campaign).'/deposit/quote', ['data' => [], 'pay_with' => 'office'])->assertForbidden();
    }

    #[Test]
    public function opening_a_campaign_freezes_proposed_grades_without_answering_for_any_child(): void
    {
        $school = $this->makeMasjid(); $this->enable($school); $year = $this->year($school); $child = $this->enrol($this->classroom($school)); $child->update(['grade_label' => '3rd']); $served = $this->enrol($child->group); $served->update(['grade_label' => '4th']); $this->office($school);
        $base = '/api/admin/masjids/'.$school->id.'/reenrolment';
        $id = $this->putJson($base, ['school_year_id' => $year->id, 'opens_on' => '2026-10-19', 'closes_on' => '2026-10-30'])->assertOk()->json('data.id');
        $child->update(['grade_label' => '4th']);
        $this->getJson($base.'/'.$id)->assertOk()->assertJsonPath('data.children.0.next_grade', '4th')->assertJsonPath('data.children.0.answer', null)->assertJsonPath('data.children.0.answered_at', null);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function every_new_tenant_model_hides_foreign_records_and_overwrites_forged_tenant_identity(): void
    {
        $a = $this->makeMasjid(); $b = $this->makeMasjid('b'); $campaign = $this->campaign($b); $form = $this->form($b); $response = $this->response($form); $child = $this->enrol($this->classroom($b));
        $records = [
            SchoolReenrolment::class => $campaign,
            SchoolAdmissionSetting::class => SchoolAdmissionSetting::create(['masjid_id' => $b->id]),
            SchoolReenrolmentAnswer::class => SchoolReenrolmentAnswer::create(['masjid_id' => $b->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id]),
            SchoolReenrolmentDeposit::class => SchoolReenrolmentDeposit::create(['masjid_id' => $b->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'form_response_id' => $response->id]),
            SchoolApplication::class => SchoolApplication::create(['masjid_id' => $b->id, 'form_response_id' => $response->id, 'status' => 'new']),
            SchoolApplicationHistory::class => SchoolApplicationHistory::create(['masjid_id' => $b->id, 'form_response_id' => $response->id, 'status' => 'accepted', 'emailed' => false]),
            SchoolAdmissionMail::class => SchoolAdmissionMail::create(['masjid_id' => $b->id, 'kind' => 'application', 'guardian_email' => 'guardian@example.invalid', 'subject' => 'Practice', 'body' => 'Practice', 'status' => 'queued']),
        ];
        app(TenantContext::class)->set($a->id);
        foreach ($records as $model => $foreign) {
            $this->assertNull($model::find($foreign->id), $model);
            $copy = $foreign->replicate(); DB::beginTransaction(); $foreign->delete(); $copy->save(); $this->assertSame($a->id, $copy->masjid_id, $model); DB::rollBack();
        }
    }
    #[Test]
    public function deposit_responses_are_excluded_from_every_application_action_and_queued_mail(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $this->application($form); $campaign = $this->campaign($school, $form);
        $child = $this->enrol($this->classroom($school)); $deposit = $this->response($form); $application = $this->response($form, 'New');
        SchoolReenrolmentDeposit::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'form_response_id' => $deposit->id]);
        $this->office($school); $base = '/api/admin/masjids/'.$school->id.'/applications';
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.children')->assertJsonPath('data.children.0.response_id', $application->id);
        $this->getJson($base.'/'.$deposit->id)->assertNotFound();
        $this->putJson($base.'/'.$deposit->id.'/status', ['status' => 'accepted'])->assertNotFound();
        SchoolApplication::create(['form_response_id' => $deposit->id, 'status' => 'accepted']);
        $this->postJson($base.'/'.$deposit->id.'/roster', ['entry_index' => 0, 'group_id' => $child->group_id])->assertNotFound();
        $history = SchoolApplicationHistory::create(['form_response_id' => $deposit->id, 'status' => 'accepted', 'emailed' => true]);
        $mail = SchoolAdmissionMail::create(['kind' => 'application', 'application_history_id' => $history->id, 'guardian_email' => 'guardian@example.invalid', 'subject' => 'Practice', 'body' => 'Practice', 'status' => 'queued']);
        (new \App\Jobs\SendSchoolAdmissionMail($mail->id, $school->id, now()->addDay()->timestamp))->handle();
        $this->assertSame('skipped', $mail->fresh()->status); Mail::assertNothingSent(); Queue::assertNothingPushed();
    }

    public static function depositStatuses(): array { return [['paid'], ['unpaid']]; }

    #[Test, \PHPUnit\Framework\Attributes\DataProvider('depositStatuses')]
    public function paid_and_unpaid_deposits_reopen_the_existing_response_and_refuse_a_second_submission(string $status): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school, true); $campaign = $this->campaign($school, $form);
        $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child); $this->family($guardian);
        $url = $this->url($child, $campaign); $this->putJson($url, ['answer' => 'returning'])->assertOk();
        $page = $this->getJson($url.'/deposit')->assertOk()->json('data');
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $payload = ['data' => $page['data'], 'reenrolment_token' => $page['token'], 'client_submission_key' => 'first-practice-'.$status, 'pay_with' => 'office'];
        $id = $this->postJson('/api/v1/forms/'.$form->id.'/responses', $payload, ['masjid-id' => $school->id])->assertOk()->json('data.uuid');
        $existing = FormResponse::where('uuid', $id)->firstOrFail(); $existing->forceFill(['payment_status' => $status, 'paid_at' => $status === 'paid' ? now() : null])->save();
        $this->family($guardian);
        $this->getJson($url.'/deposit')->assertOk()->assertJsonPath('data.response_uuids', [$id]);
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant(); $payload['client_submission_key'] = 'second-practice-'.$status;
        $before = FormResponse::count();
        $this->postJson('/api/v1/forms/'.$form->id.'/responses', $payload, ['masjid-id' => $school->id])->assertConflict()->assertJsonPath('message', 'A deposit response already exists for this child and school year. Open it from the family portal.');
        $this->assertSame($before, FormResponse::count());
        $this->assertSame(1, SchoolReenrolmentDeposit::where('reenrolment_id', $campaign->id)->where('student_contact_id', $child->contact_id)->count());
        $campaign->update(['closes_on' => '2026-10-18']); $this->family($guardian);
        $this->getJson($url.'/deposit')->assertOk()->assertJsonPath('data.response_uuids', [$id]);
    }

    #[Test]
    public function office_can_record_and_change_late_answers_but_families_cannot(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $guardian = $this->guardian($child);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-31 21:17:00', 'UTC'));
        $this->family($guardian); $this->putJson($this->url($child, $campaign), ['answer' => 'returning'])->assertUnprocessable();
        $office = $this->office($school); $url = '/api/admin/masjids/'.$school->id.'/reenrolment/'.$campaign->id.'/children/'.$child->contact_id;
        foreach (['returning', 'not_returning'] as $answer) $this->putJson($url, ['answer' => $answer])->assertOk()->assertJsonPath('data.recorded_by_office', true)->assertJsonPath('data.answer', $answer)->assertJsonPath('data.answered_at', 'Oct 31, 2026, 5:17 PM');
        $this->assertDatabaseHas('school_reenrolment_answers', ['answered_by_user_id' => $office->id, 'answered_by_contact_id' => null]);
    }

    #[Test]
    public function proposals_only_advance_to_grades_currently_served_by_this_schools_classes(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $class = $this->classroom($school); $child = $this->enrol($class); $child->update(['grade_label' => 'Grade 8']);
        $foreign = $this->makeMasjid('foreign'); $foreignChild = $this->enrol($this->classroom($foreign)); $foreignChild->update(['grade_label' => '9th']);
        app(TenantContext::class)->set($school->id); $service = app(SchoolReenrolments::class); $service->proposals($campaign);
        $this->assertSame('', $service->child($campaign, $child)['next_grade']);
        $child->update(['grade_label' => '7th']); $service->proposals($campaign);
        $this->assertSame('', $service->child($campaign, $child)['next_grade'], 'A saved blank proposal remains blank until the office chooses.');
        $served = $this->enrol($class); $served->update(['grade_label' => 'Grade 8']);
        SchoolReenrolmentAnswer::where('student_contact_id', $child->contact_id)->delete(); $service->proposals($campaign);
        $this->assertSame('8th', $service->child($campaign, $child)['next_grade']);
        $service->answer($campaign, $child, ['next_grade' => null], office: $this->office($school)->id);
        $this->assertSame('', $service->child($campaign, $child)['next_grade']);
    }

    #[Test]
    public function office_timestamps_reminder_counts_and_email_card_links_are_school_local(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form); $campaign = $this->campaign($school);
        $class = $this->classroom($school); $child = $this->enrol($class); $guardian = $this->guardian($child); $this->office($school);
        $base = '/api/admin/masjids/'.$school->id; $url = $base.'/reenrolment/'.$campaign->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.reminder_count', 1)->assertJsonPath('data.last_reminder', null);
        $this->postJson($url.'/reminders')->assertOk();
        $mail = SchoolAdmissionMail::firstOrFail();
        (new \App\Jobs\SendSchoolAdmissionMail($mail->id, $school->id, now()->addDay()->timestamp))->handle();
        $this->getJson($url)->assertOk()->assertJsonPath('data.last_reminder.count', 1)->assertJsonPath('data.last_reminder.at', 'Oct 19, 2026, 10:00 AM')->assertJsonPath('data.mails.0.sent_at', 'Oct 19, 2026, 10:00 AM');
        $this->assertStringContainsString('/family/'.$school->id.'/classes/'.$class->id.'#reenrolment', $mail->fresh()->body);
        $response = $this->response($form, when: '2026-10-14 21:17:00');
        $this->getJson($base.'/applications')->assertOk()->assertJsonPath('data.children.0.submitted_at', 'Oct 14, 2026, 5:17 PM');
        $detail = $this->putJson($base.'/applications/'.$response->id.'/status', ['status' => 'accepted'])->assertOk();
        $detail->assertJsonPath('data.history.0.changed_at', 'Oct 19, 2026, 10:00 AM');
        $appMail = SchoolAdmissionMail::where('kind', 'application')->firstOrFail();
        (new \App\Jobs\SendSchoolAdmissionMail($appMail->id, $school->id, now()->addDay()->timestamp))->handle();
        $this->getJson($base.'/applications/'.$response->id)->assertOk()->assertJsonPath('data.history.0.mails.0.sent_at', 'Oct 19, 2026, 10:00 AM');
    }

    #[Test]
    public function admissions_mail_has_an_independent_named_limiter_at_the_same_pace(): void
    {
        $job = new \App\Jobs\SendSchoolAdmissionMail(1, 1, now()->addDay()->timestamp);
        $this->assertSame('school-admissions-mail', (new \ReflectionProperty($job->middleware()[0], 'limiterName'))->getValue($job->middleware()[0]));
        $limiter = \Illuminate\Support\Facades\RateLimiter::limiter('school-admissions-mail');
        $this->assertNotNull($limiter); $limit = $limiter($job);
        $absence = (\Illuminate\Support\Facades\RateLimiter::limiter('attendance-absence-mail'))($job);
        $this->assertSame($absence->maxAttempts, $limit->maxAttempts); $this->assertNotSame($absence->key, $limit->key);
    }

    #[Test]
    public function accepted_children_report_their_current_class_after_being_added(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form); $response = $this->response($form); $class = $this->classroom($school); $this->office($school);
        $base = '/api/admin/masjids/'.$school->id.'/applications';
        $this->putJson($base.'/'.$response->id.'/status', ['status' => 'accepted', 'without_email' => true])->assertOk();
        $this->postJson($base.'/'.$response->id.'/roster', ['entry_index' => 0, 'group_id' => $class->id])->assertCreated();
        $this->getJson($base)->assertOk()->assertJsonPath('data.children.0.roster_class', $class->name);
    }

    #[Test]
    public function applications_prefer_the_applying_grade_keys_and_labels_to_the_current_grade(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form); $response = $this->response($form); $this->office($school);
        foreach (['gradeApplyingFor', 'applyingGrade', 'nextGrade', 'customGrade'] as $key) {
            $schema = $form->schema; $schema['sections'][1]['fields'][] = ['name' => $key, 'type' => 'text', 'label' => 'Grade applying for next year']; $form->update(['schema' => $schema]);
            $data = $response->data; $data['children'][0] = ['firstName' => 'Sample', 'lastName' => 'Student', 'grade' => '3rd', $key => 'Grade 4']; $response->update(['data' => $data]);
            $this->getJson('/api/admin/masjids/'.$school->id.'/applications')->assertOk()->assertJsonPath('data.children.0.grade', '4th');
        }
        $schema = $form->schema; $schema['sections'] = [['id' => 'flat', 'fields' => [['name' => 'studentFirstName', 'type' => 'text'], ['name' => 'studentLastName', 'type' => 'text'], ['name' => 'custom', 'type' => 'text', 'label' => 'Grade applying for']]]]; $form->update(['schema' => $schema]);
        $response->update(['data' => ['studentFirstName' => 'Sample', 'studentLastName' => 'Student', 'currentGrade' => '8th', 'custom' => '9th']]);
        $this->getJson('/api/admin/masjids/'.$school->id.'/applications')->assertOk()->assertJsonPath('data.children.0.grade', '9th');
    }

    #[Test]
    public function office_instants_are_formatted_on_the_school_clock_even_when_the_browser_is_elsewhere(): void
    {
        $school = $this->makeMasjid(); $form = $this->form($school); $this->application($form); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school)); $this->office($school);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 21:17:00', 'UTC'));
        $base = '/api/admin/masjids/'.$school->id;
        $this->putJson($base.'/reenrolment/'.$campaign->id.'/children/'.$child->contact_id, ['answer' => 'returning'])->assertOk()->assertJsonPath('data.answered_at', 'Oct 14, 2026, 5:17 PM');
        $response = $this->response($form, when: '2026-10-14 21:17:00');
        $this->getJson($base.'/applications')->assertOk()->assertJsonPath('data.children.0.submitted_at', 'Oct 14, 2026, 5:17 PM');
        $this->putJson($base.'/applications/'.$response->id.'/status', ['status' => 'accepted'])->assertOk()->assertJsonPath('data.history.0.changed_at', 'Oct 14, 2026, 5:17 PM');
    }

    #[Test]
    public function two_reminder_requests_in_the_same_second_keep_separate_delivery_counts(): void
    {
        $school = $this->makeMasjid(); $campaign = $this->campaign($school); $class = $this->classroom($school); $child = $this->enrol($class); $this->guardian($child); $this->office($school);
        $url = '/api/admin/masjids/'.$school->id.'/reenrolment/'.$campaign->id;
        $this->postJson($url.'/reminders')->assertOk()->assertJsonPath('data.queued', 1);
        $first = SchoolAdmissionMail::firstOrFail(); (new \App\Jobs\SendSchoolAdmissionMail($first->id, $school->id, now()->addDay()->timestamp))->handle();
        $other = $this->enrol($class); $this->guardian($other, Contact::factory()->create(['masjid_id' => $school->id, 'email' => 'another@example.invalid']));
        $this->postJson($url.'/reminders')->assertOk()->assertJsonPath('data.queued', 2);
        foreach (SchoolAdmissionMail::where('status', 'queued')->get() as $mail) (new \App\Jobs\SendSchoolAdmissionMail($mail->id, $school->id, now()->addDay()->timestamp))->handle();
        $this->getJson($url)->assertOk()->assertJsonPath('data.last_reminder.count', 2);
    }

    #[Test]
    public function deposit_guard_upgrade_refuses_existing_duplicate_links_before_changing_schema(): void
    {
        $guard = require database_path('migrations/2026_10_14_235100_guard_school_admission_deposits.php'); $guard->down();
        $school = $this->makeMasjid(); $form = $this->form($school); $campaign = $this->campaign($school); $child = $this->enrol($this->classroom($school));
        foreach (['First', 'Second'] as $name) {
            $response = $this->response($form, $name);
            SchoolReenrolmentDeposit::create(['masjid_id' => $school->id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'form_response_id' => $response->id]);
        }
        try { $guard->up(); $this->fail('A payment link must never be discarded by the migration.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Resolve duplicate', $e->getMessage()); }
        $this->assertDatabaseCount('school_reenrolment_deposits', 2); $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('school_admission_mails', 'reminder_batch'));
    }

}
