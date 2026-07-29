<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Support\FormSchema;
use Tests\TestCase;

/**
 * FormSchema is the enforcement boundary for public form submissions: the renderer's
 * client-side validation is a convenience, this is what actually decides. These tests
 * exercise it against the real camp-registration shape (repeatable attendees, a
 * minimum age, a program-track select, a required waiver checkbox, and the
 * guardian-name-if-any-attendee-under-18 rule) because that form uses every mechanism
 * the builder supports.
 *
 * No database: FormSchema reads the model's cast attributes only.
 */
class FormSchemaTest extends TestCase
{
    private function campForm(array $overrides = []): Form
    {
        $form = new Form();

        $form->schema = [
            'sections' => [
                [
                    'id' => 'registrant',
                    'title' => 'Your Information',
                    'fields' => [
                        ['name' => 'registrantName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                        ['name' => 'registrantEmail', 'label' => 'Email address', 'type' => 'email', 'required' => true],
                        ['name' => 'registrantPhone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true],
                        ['name' => 'registrantAddress', 'label' => 'Home address', 'type' => 'text', 'required' => false],
                    ],
                ],
                [
                    'id' => 'attendees',
                    'title' => 'Who Is Attending?',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'maxEntries' => 12,
                    'fields' => [
                        ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                        ['name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true, 'min' => 7, 'max' => 99],
                        [
                            'name' => 'group',
                            'label' => 'Program track',
                            'type' => 'select',
                            'required' => true,
                            'options' => [
                                ['value' => 'brothers', 'label' => 'Brothers'],
                                ['value' => 'sisters', 'label' => 'Sisters'],
                            ],
                        ],
                        ['name' => 'allergies', 'label' => 'Allergies', 'type' => 'textarea', 'required' => false],
                    ],
                ],
                [
                    'id' => 'consents',
                    'title' => 'Agreements & Consent',
                    'fields' => [
                        ['name' => 'waiverAgreement', 'label' => 'Liability waiver', 'type' => 'checkbox', 'required' => true],
                        [
                            'name' => 'guardianName',
                            'label' => 'Parent / legal guardian name',
                            'type' => 'text',
                            'required' => false,
                            'requiredIf' => [
                                'rule' => 'anyEntryUnder',
                                'section' => 'attendees',
                                'field' => 'age',
                                'value' => 18,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $form->settings = array_merge([
            'identity' => [
                'name' => 'registrantName',
                'email' => 'registrantEmail',
                'phone' => 'registrantPhone',
            ],
            'fee' => [
                'amount' => 100,
                'currency' => 'USD',
                'perEntryOfSection' => 'attendees',
            ],
        ], $overrides);

        return $form;
    }

    /** A complete, all-adult submission passes. */
    private function validSubmission(): array
    {
        return [
            'registrantName' => 'Amal Yusuf',
            'registrantEmail' => 'amal@example.com',
            'registrantPhone' => '336-555-0123',
            'registrantAddress' => '',
            'attendees' => [
                ['fullName' => 'Amal Yusuf', 'age' => 34, 'group' => 'sisters', 'allergies' => ''],
            ],
            'waiverAgreement' => true,
        ];
    }

    public function test_a_complete_submission_passes(): void
    {
        $validator = FormSchema::for($this->campForm())->validator($this->validSubmission());

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_missing_required_top_level_field_fails(): void
    {
        $data = $this->validSubmission();
        unset($data['registrantEmail']);

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('registrantEmail', $validator->errors()->toArray());
    }

    public function test_malformed_email_fails(): void
    {
        $data = $this->validSubmission();
        $data['registrantEmail'] = 'not-an-email';

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('registrantEmail', $validator->errors()->toArray());
    }

    /** The repeatable section enforces its own minimum, not just per-row rules. */
    public function test_zero_attendees_fails_the_minimum(): void
    {
        $data = $this->validSubmission();
        $data['attendees'] = [];

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attendees', $validator->errors()->toArray());
    }

    public function test_more_attendees_than_max_entries_fails(): void
    {
        $data = $this->validSubmission();
        $data['attendees'] = array_fill(0, 13, [
            'fullName' => 'X', 'age' => 20, 'group' => 'brothers', 'allergies' => '',
        ]);

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attendees', $validator->errors()->toArray());
    }

    /** Per-row rules address the offending row, so the renderer can mark it. */
    public function test_attendee_below_minimum_age_fails_on_that_row(): void
    {
        $data = $this->validSubmission();
        $data['attendees'][] = ['fullName' => 'Too Young', 'age' => 5, 'group' => 'brothers', 'allergies' => ''];

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attendees.1.age', $validator->errors()->toArray());
    }

    /** A select value outside the declared options is rejected — this is the tamper case. */
    public function test_invented_select_option_is_rejected(): void
    {
        $data = $this->validSubmission();
        $data['attendees'][0]['group'] = 'staff-only';

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attendees.0.group', $validator->errors()->toArray());
    }

    /** A required checkbox must be TICKED — `required` alone would let false through. */
    public function test_unticked_required_checkbox_fails(): void
    {
        $data = $this->validSubmission();
        $data['waiverAgreement'] = false;

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('waiverAgreement', $validator->errors()->toArray());
    }

    public function test_guardian_name_required_when_an_attendee_is_a_minor(): void
    {
        $data = $this->validSubmission();
        $data['attendees'][] = ['fullName' => 'Yusuf Jr', 'age' => 12, 'group' => 'brothers', 'allergies' => ''];

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('guardianName', $validator->errors()->toArray());
    }

    public function test_guardian_name_not_required_when_all_attendees_are_adults(): void
    {
        $data = $this->validSubmission();

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_guardian_name_supplied_satisfies_the_conditional(): void
    {
        $data = $this->validSubmission();
        $data['attendees'][] = ['fullName' => 'Yusuf Jr', 'age' => 12, 'group' => 'brothers', 'allergies' => ''];
        $data['guardianName'] = 'Amal Yusuf';

        $validator = FormSchema::for($this->campForm())->validator($data);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_entry_count_follows_the_repeatable_section(): void
    {
        $data = $this->validSubmission();
        $data['attendees'][] = ['fullName' => 'B', 'age' => 30, 'group' => 'brothers', 'allergies' => ''];
        $data['attendees'][] = ['fullName' => 'C', 'age' => 31, 'group' => 'brothers', 'allergies' => ''];

        $this->assertSame(3, FormSchema::for($this->campForm())->entryCount($data));
    }

    public function test_amount_due_is_per_entry(): void
    {
        $data = $this->validSubmission();
        $data['attendees'][] = ['fullName' => 'B', 'age' => 30, 'group' => 'brothers', 'allergies' => ''];

        $this->assertSame(200.0, FormSchema::for($this->campForm())->amountDue($data));
    }

    public function test_a_form_without_a_fee_has_no_amount_due(): void
    {
        $form = $this->campForm();
        $settings = $form->settings;
        unset($settings['fee']);
        $form->settings = $settings;

        $this->assertNull(FormSchema::for($form)->amountDue($this->validSubmission()));
    }

    /**
     * A form that asks for first and last name separately must still produce ONE
     * searchable name, or the responses list shows half of everybody's name.
     */
    public function test_a_composite_identity_slot_joins_its_parts(): void
    {
        $form = $this->campForm();

        $schema = $form->schema;
        $schema['sections'][0]['fields'][0] = ['name' => 'firstName', 'label' => 'First name', 'type' => 'text', 'required' => true];
        array_splice($schema['sections'][0]['fields'], 1, 0, [
            ['name' => 'lastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
        ]);
        $form->schema = $schema;

        $settings = $form->settings;
        $settings['identity']['name'] = ['firstName', 'lastName'];
        $form->settings = $settings;

        $identity = FormSchema::for($form)->identity([
            'firstName' => 'Amal',
            'lastName' => 'Yusuf',
            'registrantEmail' => 'amal@example.com',
        ]);

        $this->assertSame('Amal Yusuf', $identity['respondent_name']);
    }

    /** A missing half must not leave a dangling space. */
    public function test_a_composite_identity_slot_tolerates_a_missing_part(): void
    {
        $form = $this->campForm();
        $settings = $form->settings;
        $settings['identity']['name'] = ['firstName', 'lastName'];
        $form->settings = $settings;

        $identity = FormSchema::for($form)->identity(['firstName' => 'Amal']);

        $this->assertSame('Amal', $identity['respondent_name']);
    }

    public function test_identity_is_extracted_per_the_declared_map(): void
    {
        $identity = FormSchema::for($this->campForm())->identity($this->validSubmission());

        $this->assertSame('Amal Yusuf', $identity['respondent_name']);
        $this->assertSame('amal@example.com', $identity['respondent_email']);
        $this->assertSame('336-555-0123', $identity['respondent_phone']);
    }

    /**
     * Undeclared keys must not survive into storage — otherwise a caller could inject
     * arbitrary content that later renders in the admin table and reaches the assistant.
     */
    public function test_undeclared_fields_are_stripped(): void
    {
        $data = $this->validSubmission();
        $data['isAdmin'] = true;
        $data['attendees'][0]['secretNote'] = '<script>alert(1)</script>';

        $clean = FormSchema::for($this->campForm())->only($data);

        $this->assertArrayNotHasKey('isAdmin', $clean);
        $this->assertArrayNotHasKey('secretNote', $clean['attendees'][0]);
        $this->assertSame('Amal Yusuf', $clean['registrantName']);
        $this->assertSame(34, $clean['attendees'][0]['age']);
    }
}
