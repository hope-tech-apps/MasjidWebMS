<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Support\AlRaziWebsite\SubmissionMapper;
use App\Support\FormSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SubmissionMapper against the two form definitions it writes into.
 *
 * The load-bearing test is `every_key_the_mapper_emits_is_declared_in_the_form`:
 * FormSchema::only() drops an undeclared key WITHOUT A WORD, so a field name typed
 * differently here and in database/forms/*.json would import as nothing, forever,
 * while every run reported success. It runs both directions — nothing emitted is
 * undeclared, and nothing declared is unreachable — over a row that fills every
 * field the site's RegistrationFormData type has.
 *
 * Every value below is obviously fake. None is a real person's.
 */
class AlRaziSubmissionMapperTest extends TestCase
{
    private const FAKE_SSN = '000-00-0000';

    private function form(string $slug): Form
    {
        $definition = json_decode(
            (string) file_get_contents(base_path("database/forms/{$slug}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        // Never saved: FormSchema reads only the schema.
        return new Form(['schema' => $definition['schema'], 'settings' => $definition['settings']]);
    }

    /** @return array<string,mixed> A parent record with every optional field filled. */
    private function parent(int $n): array
    {
        return [
            'full_name' => "Test Parent{$n}",
            'relationship' => 'Guardian',
            'dob' => '1990-01-01',
            'place_of_birth' => 'Testville',
            'occupation' => 'Tester',
            'employer' => 'Test Employer',
            'work_street' => '1 Test Way',
            'work_city' => 'Testville',
            'work_state' => 'NC',
            'work_zip' => '00000',
            'work_phone' => '555-555-0110',
            'cell_phone' => '555-555-0111',
            'home_phone' => '555-555-0112',
            'email' => "parent{$n}@example.invalid",
            'highest_education' => 'Test Degree',
            'languages_spoken' => ['english' => true, 'arabic' => true, 'other' => 'Test Language'],
            'preferred_communication' => 'email',
        ];
    }

    /** @return array<string,mixed> A registration row with every field the site defines. */
    private function fullRegistration(): array
    {
        return [
            'id' => '00000000-0000-4000-8000-000000000001',
            'created_at' => '2026-09-01T10:00:00+00:00',
            'updated_at' => '2026-09-01T10:05:00+00:00',
            'student_first_name' => 'Test',
            'student_middle_name' => 'Middle',
            'student_last_name' => 'Child',
            'student_dob' => '2021-01-01',
            'grade' => 'KG',
            'program' => 'full_time',
            'parent_first_name' => 'Test',
            'parent_last_name' => 'Parent1',
            'parent_email' => 'parent1@example.invalid',
            'parent_phone' => '555-555-0111',
            'tuition_plan' => 'annual',
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'base_amount_cents' => 125000,
            'surcharge_cents' => 3750,
            'total_amount_cents' => 128750,
            'amount_paid_cents' => 128750,
            'stripe_payment_intent_id' => 'pi_test_0000',
            'stripe_session_id' => 'cs_test_0000',
            'status' => 'new',
            'documents' => array_map(fn (string $category) => [
                'category' => $category,
                'path' => "incoming/00000000-0000-4000-8000-00000000000{$category[0]}-{$category}-test.pdf",
                'original_filename' => "{$category}-test.pdf",
                'size_bytes' => 1000,
                'content_type' => 'application/pdf',
            ], ['birth_certificate', 'immunization', 'prior_records', 'ssn_card', 'custody', 'toilet_training', 'food_allergy_plan']),
            'data' => [
                'student' => [
                    'first_name' => 'Test',
                    'middle_name' => 'Middle',
                    'last_name' => 'Child',
                    'nickname' => 'Testy',
                    'gender' => 'female',
                    'dob' => '2021-01-01',
                    'age' => '5',
                    'place_of_birth_city' => 'Testville',
                    'place_of_birth_state' => 'NC',
                    'ssn' => self::FAKE_SSN,
                    'home_street' => '1 Test St',
                    'home_city' => 'Testville',
                    'home_state' => 'NC',
                    'home_zip' => '00000',
                    'previous_school' => 'Test Preschool',
                    'previous_school_address' => '2 Test St',
                    'previous_school_from' => '2024',
                    'previous_school_to' => '2026',
                    'reason_for_leaving' => 'Test reason',
                ],
                'language' => [
                    'first_language' => 'Test Language',
                    'languages_at_home' => 'Test Language',
                    'speaks_english_fluently' => true,
                    'reads_writes_english' => false,
                    'needs_english_support' => true,
                ],
                'parent1' => $this->parent(1),
                'parent2' => $this->parent(2),
                'primary_contact' => 'parent1',
                'emergency_contacts' => [
                    ['name' => 'Test Contact1', 'relationship' => 'Aunt', 'phone' => '555-555-0121', 'alt_phone' => '555-555-0122', 'address' => '3 Test St'],
                    ['name' => 'Test Contact2', 'relationship' => 'Uncle', 'phone' => '555-555-0123'],
                ],
                'authorized_pickup' => [
                    ['name' => 'Test Pickup1', 'relationship' => 'Grandparent'],
                    ['name' => 'Test Pickup2', 'relationship' => 'Neighbour'],
                    ['name' => 'Test Pickup3', 'relationship' => 'Family friend'],
                ],
                'medical' => [
                    'physician_name' => 'Dr Test',
                    'physician_phone' => '555-555-0131',
                    'clinic' => 'Test Clinic',
                    'insurance_provider' => 'Test Insurer',
                    'insurance_policy_number' => 'TEST-POLICY-0000',
                    'conditions' => [
                        'asthma' => true,
                        'heart_disease' => false,
                        'epilepsy' => false,
                        'diabetes' => false,
                        'adhd_add' => false,
                        'allergies' => 'Test allergy',
                        'other' => 'Test condition',
                    ],
                    'current_medications' => 'Test medication',
                    'dietary_restrictions' => 'Test diet',
                    'emergency_authorizations' => [
                        'administer_first_aid' => true,
                        'contact_ems' => true,
                        'transport_to_facility' => true,
                    ],
                ],
                'tuition_agreement' => [
                    'plan' => 'annual',
                    'nco_scholarship' => true,
                    'nco_scholarship_responsibility' => true,
                    'financially_responsible' => true,
                    'pays_on_time' => true,
                    'understands_late_fee' => true,
                    'attends_regularly' => true,
                    'understands_absence_policy' => true,
                    'supports_islamic_values' => true,
                    'follows_rules_islamic_etiquette' => true,
                    'understands_disciplinary_consequences' => true,
                    'cooperates_with_admin' => true,
                    'responsible_for_supplies' => true,
                    'replaces_damaged_property' => true,
                    'parent1_signature' => 'Test Parent1',
                    'parent1_signed_at' => '2026-09-01T09:59:00Z',
                    'parent2_signature' => 'Test Parent2',
                    'parent2_signed_at' => '2026-09-01T09:59:30Z',
                ],
                'consents' => [
                    'photo_video' => 'yes',
                    'field_trip' => 'no',
                    'technology_use_agreed' => true,
                    'islamic_studies_agreed' => true,
                    'health_screening' => 'yes',
                    'communication_preferences' => ['email' => true, 'text' => true, 'whatsapp' => false, 'printed' => false],
                    'consent_signature' => 'Test Parent1',
                    'consent_signed_at' => '2026-09-01T09:58:00Z',
                ],
                'policy_acks' => [
                    'attendance' => true,
                    'uniform' => true,
                    'homework' => true,
                    'discipline' => true,
                    'drop_off_pick_up' => true,
                    'health' => true,
                    'communication' => true,
                    'islamic_environment' => true,
                    'parent_involvement' => true,
                    'grievance' => true,
                    'acknowledged_at' => '2026-09-01T09:57:00Z',
                    'parent1_signature' => 'Test Parent1',
                    'parent2_signature' => 'Test Parent2',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fullCareers(): array
    {
        return [
            'id' => '00000000-0000-4000-8000-0000000000c1',
            'created_at' => '2026-09-02T09:00:00+00:00',
            'role' => 'Kindergarten Lead Teacher',
            'applicant_first_name' => 'Test',
            'applicant_last_name' => 'Applicant',
            'email' => 'applicant@example.invalid',
            'phone' => '555-555-0199',
            'resume_path' => 'kindergarten-lead-teacher/00000000-0000-4000-8000-00000000abcd-test-resume.pdf',
            'resume_url' => 'https://example.invalid/test-resume',
            'cover_letter' => 'Test cover letter.',
            'refs' => 'Test references.',
            'start_availability' => 'Immediately',
            'status' => 'new',
        ];
    }

    /**
     * The form's declared answer keys: flat field names, and the repeatable
     * section's id with its own field names. File fields are listed apart,
     * because their values are written by the import, not the mapper.
     *
     * @return array{flat: list<string>, repeatable: array<string,list<string>>, files: list<string>}
     */
    private function declared(Form $form): array
    {
        $flat = [];
        $repeatable = [];
        $files = [];

        foreach ($form->sections() as $section) {
            foreach ($section['fields'] as $field) {
                if (! empty($section['repeatable'])) {
                    $repeatable[$section['id']][] = $field['name'];
                } elseif ($field['type'] === 'file') {
                    $files[] = $field['name'];
                } else {
                    $flat[] = $field['name'];
                }
            }
        }

        return ['flat' => $flat, 'repeatable' => $repeatable, 'files' => $files];
    }

    /**
     * @param  array<string,mixed>  $mapped
     */
    private function assertEmitsExactlyTheDeclaredKeys(Form $form, array $mapped): void
    {
        $declared = $this->declared($form);

        $this->assertSame([], $mapped['unknown'], 'a complete row of the site\'s own shape has nothing unmapped');

        foreach ($mapped['data'] as $key => $value) {
            if (isset($declared['repeatable'][$key])) {
                $this->assertIsArray($value);
                foreach ($value as $row) {
                    foreach (array_keys($row) as $inner) {
                        $this->assertContains($inner, $declared['repeatable'][$key], "{$key}.*.{$inner} is emitted but not declared");
                    }
                }

                continue;
            }

            $this->assertContains($key, $declared['flat'], "\"{$key}\" is emitted but not declared, so only() would drop it silently");
        }

        // The other direction: a declared question nothing ever fills is a column
        // staff would wait on forever.
        foreach ($declared['flat'] as $name) {
            $this->assertArrayHasKey($name, $mapped['data'], "\"{$name}\" is declared but the mapper never fills it");
        }
        foreach ($declared['repeatable'] as $sectionId => $names) {
            $this->assertArrayHasKey($sectionId, $mapped['data']);
            $filled = collect($mapped['data'][$sectionId])->flatMap(fn ($row) => array_keys($row))->unique()->all();
            foreach ($names as $name) {
                $this->assertContains($name, $filled, "{$sectionId}.*.{$name} is declared but never filled");
            }
        }

        foreach ($mapped['files'] as $file) {
            $this->assertContains($file['field'], $declared['files'], "{$file['field']} is fetched but is not a file question");
        }
        $this->assertEqualsCanonicalizing($declared['files'], array_column($mapped['files'], 'field'), 'every file question has a document to fill it');

        // And only() keeps all of it: this is the silent drop the test exists for.
        $this->assertEquals($mapped['data'], FormSchema::for($form)->only($mapped['data']));
    }

    #[Test]
    public function every_key_the_mapper_emits_is_declared_in_the_registration_form(): void
    {
        $form = $this->form('alrazi-website-registration');

        // Insurance ON, so its field is reachable too.
        $this->assertEmitsExactlyTheDeclaredKeys($form, SubmissionMapper::registration($this->fullRegistration(), includeInsurance: true));
    }

    #[Test]
    public function every_key_the_mapper_emits_is_declared_in_the_careers_form(): void
    {
        $this->assertEmitsExactlyTheDeclaredKeys($this->form('alrazi-website-careers'), SubmissionMapper::careers($this->fullCareers()));
    }

    #[Test]
    public function identity_fields_are_declared_and_filled(): void
    {
        foreach ([
            'alrazi-website-registration' => SubmissionMapper::registration($this->fullRegistration()),
            'alrazi-website-careers' => SubmissionMapper::careers($this->fullCareers()),
        ] as $slug => $mapped) {
            $identity = FormSchema::for($this->form($slug))->identity($mapped['data']);

            $this->assertNotNull($identity['respondent_name'], $slug);
            $this->assertNotNull($identity['respondent_email'], $slug);
            $this->assertNotNull($identity['respondent_phone'], $slug);
        }
    }

    #[Test]
    public function the_ssn_and_the_ssn_card_never_leave_the_mapper(): void
    {
        $row = $this->fullRegistration();
        // Belt and braces: the key anywhere, in any case.
        $row['data']['parent1']['SSN'] = self::FAKE_SSN;
        $row['data']['medical']['conditions']['Ssn'] = self::FAKE_SSN;
        $row['ssn'] = self::FAKE_SSN;

        $mapped = SubmissionMapper::registration($row, includeInsurance: true);

        $this->assertStringNotContainsString(self::FAKE_SSN, json_encode($mapped, JSON_THROW_ON_ERROR));
        $this->assertNotContains('ssn_card', array_column($mapped['files'], 'field'));
        $this->assertSame([], array_filter($mapped['files'], fn ($f) => str_contains($f['path'], 'ssn_card')), 'the SSN card path is never handed on to be signed');
        // Dropped on purpose, so not reported as an unknown field either.
        $this->assertSame([], $mapped['unknown']);
    }

    #[Test]
    public function the_insurance_policy_number_is_dropped_unless_asked_for(): void
    {
        $default = SubmissionMapper::registration($this->fullRegistration());
        $this->assertArrayNotHasKey('insurancePolicyNumber', $default['data']);
        $this->assertStringNotContainsString('TEST-POLICY-0000', json_encode($default, JSON_THROW_ON_ERROR));
        $this->assertSame([], $default['unknown'], 'a deliberate drop is not an unknown field');

        $opted = SubmissionMapper::registration($this->fullRegistration(), includeInsurance: true);
        $this->assertSame('TEST-POLICY-0000', $opted['data']['insurancePolicyNumber']);
    }

    #[Test]
    public function unknown_keys_are_reported_by_path_and_never_by_value(): void
    {
        $row = $this->fullRegistration();
        $row['data']['student']['shoe_size'] = 'TEST-SECRET-1';
        $row['data']['emergency_contacts'][1]['email'] = 'TEST-SECRET-2';
        $row['data']['authorized_pickup'][] = ['name' => 'TEST-SECRET-3', 'relationship' => 'x'];
        $row['favourite_colour'] = 'TEST-SECRET-4';

        $mapped = SubmissionMapper::registration($row);

        $this->assertSame([
            'data.authorized_pickup.*',
            'data.emergency_contacts.*.email',
            'data.student.shoe_size',
            'favourite_colour',
        ], $mapped['unknown']);

        $this->assertStringNotContainsString('TEST-SECRET', json_encode($mapped['unknown'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('TEST-SECRET', json_encode($mapped['data'], JSON_THROW_ON_ERROR), 'an unmapped value is not imported either');
    }

    #[Test]
    public function values_are_kept_verbatim_and_money_becomes_dollars(): void
    {
        $mapped = SubmissionMapper::registration($this->fullRegistration());

        $this->assertSame('KG', $mapped['data']['grade']);
        $this->assertSame('full_time', $mapped['data']['program']);
        $this->assertSame('1250.00', $mapped['data']['websiteBaseAmount']);
        $this->assertSame('37.50', $mapped['data']['websiteSurcharge']);
        $this->assertSame('1287.50', $mapped['data']['websiteTotal']);
        $this->assertSame('paid', $mapped['data']['websitePaymentStatus']);
        $this->assertTrue($mapped['data']['speaksEnglishFluently']);
        $this->assertFalse($mapped['data']['readsWritesEnglish']);
        $this->assertSame('Test Pickup3', $mapped['data']['pickup3Name']);
        $this->assertCount(2, $mapped['data']['emergencyContacts']);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $mapped['external_ref']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $mapped['submitted_at']->toIso8601String());
        $this->assertArrayNotHasKey('stripe_session_id', $mapped['data']);
        $this->assertStringNotContainsString('cs_test_0000', json_encode($mapped, JSON_THROW_ON_ERROR));

        $careers = SubmissionMapper::careers($this->fullCareers());
        $this->assertSame('Kindergarten Lead Teacher', $careers['data']['role']);
        $this->assertSame('test-resume.pdf', $careers['files'][0]['original_name'], 'the site\'s random prefix is not the applicant\'s filename');
        $this->assertSame('resumes', $careers['files'][0]['bucket']);
    }

    #[Test]
    public function a_careers_row_without_a_resume_fetches_nothing(): void
    {
        $row = $this->fullCareers();
        $row['resume_path'] = null;

        $mapped = SubmissionMapper::careers($row);

        $this->assertSame([], $mapped['files']);
        $this->assertSame([], $mapped['unknown']);
    }
}
