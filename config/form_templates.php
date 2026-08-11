<?php

/*
|--------------------------------------------------------------------------
| Vertical form templates (PLAN T-011)
|--------------------------------------------------------------------------
|
| Starter sign-up forms seeded onto a tenant AT PROVISIONING TIME, keyed by
| `org_type` — the forms counterpart of the feature bundles in
| `config/verticals.php`. Templates are DATA, not code: adding one for a
| future vertical means adding an array here, nothing else.
|
| A template produces an ORDINARY form — same `forms` table, same
| `ValidFormSchema` vocabulary, same public submit endpoint — that an admin
| then edits like any form they built by hand. Nothing downstream knows or
| cares that a form began as a template.
|
| Rules of this file:
|
|  - Every org_type in `Masjid::ORG_TYPES` has a key. Masjid and community
|    list NONE today — provisioning them must stay byte-identical to before
|    this file existed (`FormTemplateTest` pins it).
|  - Each template carries `slug`, `name`, `description`, `schema`,
|    `settings`, `is_active`. Schemas must pass `App\Rules\ValidFormSchema`
|    and use only `FormSchema::FIELD_TYPES`; identity maps must point at real
|    questions. `FormTemplateTest::every_template_passes_the_schema_rule`
|    enforces both, so a typo here fails the suite rather than a submitter.
|  - `is_active` mirrors how forms work today: the DB default is active, and
|    a form is only public once an admin places it on a page (a `form`
|    section carries `content.form_id`), so seeding active is safe.
|  - A `file` question's allowed types and size ceiling do NOT live in the
|    schema — they are enforced at the request boundary from
|    `config/forms.php` (see the careers résumé field).
|
| Seeding happens in `OnboardingController@provision` via
| `App\Support\FormTemplates::applyTo()`, which is also how an EXISTING
| tenant gets them later: `php artisan form:apply-templates {masjid}`.
| Re-applying is idempotent and never overwrites — an existing (masjid, slug)
| form, edited or even soft-deleted by an admin, is skipped and reported.
|
*/

return [

    'masjid' => [],

    'community' => [],

    'school' => [

        /*
         * Admissions / registration interest — the alrazischool.org
         * "interested in enrolling" flow. Collects the student, the grade
         * they are applying for, and the parent to follow up with.
         */
        [
            'slug' => 'admissions-interest',
            'name' => 'Admissions Interest',
            'description' => 'Tell us about your child and we will follow up with next steps for enrollment.',
            'is_active' => true,
            'schema' => [
                'sections' => [
                    [
                        'id' => 'student',
                        'title' => 'Student',
                        'fields' => [
                            ['name' => 'studentFirstName', 'label' => 'Student first name', 'type' => 'text', 'required' => true],
                            ['name' => 'studentLastName', 'label' => 'Student last name', 'type' => 'text', 'required' => true],
                            ['name' => 'studentDateOfBirth', 'label' => 'Student date of birth', 'type' => 'date', 'required' => true],
                            [
                                'name' => 'gradeApplyingFor',
                                'label' => 'Grade applying for',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['value' => 'pre-k', 'label' => 'Pre-K'],
                                    ['value' => 'kindergarten', 'label' => 'Kindergarten'],
                                    ['value' => 'grade-1', 'label' => 'Grade 1'],
                                    ['value' => 'grade-2', 'label' => 'Grade 2'],
                                    ['value' => 'grade-3', 'label' => 'Grade 3'],
                                    ['value' => 'grade-4', 'label' => 'Grade 4'],
                                    ['value' => 'grade-5', 'label' => 'Grade 5'],
                                    ['value' => 'grade-6', 'label' => 'Grade 6'],
                                    ['value' => 'grade-7', 'label' => 'Grade 7'],
                                    ['value' => 'grade-8', 'label' => 'Grade 8'],
                                    ['value' => 'other', 'label' => 'Other / not sure yet'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'parent',
                        'title' => 'Parent / Guardian',
                        'fields' => [
                            ['name' => 'parentName', 'label' => 'Parent or guardian name', 'type' => 'text', 'required' => true],
                            ['name' => 'parentEmail', 'label' => 'Email address', 'type' => 'email', 'required' => true],
                            ['name' => 'parentPhone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true],
                            [
                                'name' => 'howHeard',
                                'label' => 'How did you hear about us?',
                                'type' => 'select',
                                'options' => [
                                    ['value' => 'friend-family', 'label' => 'A friend or family member'],
                                    ['value' => 'current-family', 'label' => 'A current school family'],
                                    ['value' => 'masjid', 'label' => 'Our masjid or community'],
                                    ['value' => 'social-media', 'label' => 'Social media'],
                                    ['value' => 'search', 'label' => 'Online search'],
                                    ['value' => 'event', 'label' => 'A school or community event'],
                                    ['value' => 'other', 'label' => 'Other'],
                                ],
                            ],
                            ['name' => 'questions', 'label' => 'Anything you would like us to know?', 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => [
                    'name' => 'parentName',
                    'email' => 'parentEmail',
                    'phone' => 'parentPhone',
                ],
            ],
        ],

        /*
         * Careers application — the alrazischool.org employment flow. The
         * résumé is a required `file` question; the MIME/size rules come from
         * config/forms.php at the request boundary, never from this schema.
         */
        [
            'slug' => 'careers-application',
            'name' => 'Careers Application',
            'description' => 'Apply to join our faculty and staff.',
            'is_active' => true,
            'schema' => [
                'sections' => [
                    [
                        'id' => 'applicant',
                        'title' => 'About you',
                        'fields' => [
                            ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                            ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true],
                            ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'required' => true],
                        ],
                    ],
                    [
                        'id' => 'application',
                        'title' => 'Your application',
                        'fields' => [
                            [
                                'name' => 'roleAppliedFor',
                                'label' => 'Role you are applying for',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['value' => 'teacher', 'label' => 'Teacher'],
                                    ['value' => 'assistant-teacher', 'label' => 'Assistant teacher'],
                                    ['value' => 'substitute-teacher', 'label' => 'Substitute teacher'],
                                    ['value' => 'administration', 'label' => 'Administration'],
                                    ['value' => 'support-staff', 'label' => 'Support staff'],
                                    ['value' => 'other', 'label' => 'Other'],
                                ],
                            ],
                            ['name' => 'resume', 'label' => 'Résumé', 'type' => 'file', 'required' => true],
                            ['name' => 'coverLetter', 'label' => 'Cover letter', 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => [
                    'name' => 'fullName',
                    'email' => 'email',
                    'phone' => 'phone',
                ],
            ],
        ],

        /*
         * Withdrawal (exit) request — a family formally withdrawing a
         * student. The identity name is the STUDENT, so the responses list
         * reads as a roster of who is leaving.
         */
        [
            'slug' => 'withdrawal-request',
            'name' => 'Withdrawal Request',
            'description' => 'Request the withdrawal of a student and tell us about your experience.',
            'is_active' => true,
            'schema' => [
                'sections' => [
                    [
                        'id' => 'student',
                        'title' => 'Student being withdrawn',
                        'fields' => [
                            ['name' => 'studentName', 'label' => 'Student name', 'type' => 'text', 'required' => true],
                            ['name' => 'lastDay', 'label' => 'Last day of attendance', 'type' => 'date', 'required' => true],
                            [
                                'name' => 'reason',
                                'label' => 'Reason for withdrawal',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['value' => 'relocation', 'label' => 'Relocating / moving away'],
                                    ['value' => 'tuition', 'label' => 'Tuition or financial reasons'],
                                    ['value' => 'transportation', 'label' => 'Transportation'],
                                    ['value' => 'another-school', 'label' => 'Transferring to another school'],
                                    ['value' => 'homeschooling', 'label' => 'Homeschooling'],
                                    ['value' => 'other', 'label' => 'Other'],
                                ],
                            ],
                            ['name' => 'exitFeedback', 'label' => 'Is there anything we could have done better?', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'id' => 'contact',
                        'title' => 'Parent / Guardian',
                        'fields' => [
                            ['name' => 'parentName', 'label' => 'Parent or guardian name', 'type' => 'text', 'required' => true],
                            ['name' => 'parentEmail', 'label' => 'Email address', 'type' => 'email', 'required' => true],
                            ['name' => 'parentPhone', 'label' => 'Phone number', 'type' => 'tel'],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => [
                    'name' => 'studentName',
                    'email' => 'parentEmail',
                    'phone' => 'parentPhone',
                ],
            ],
        ],

    ],

];
