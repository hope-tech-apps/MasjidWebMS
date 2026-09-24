<?php

namespace App\Support\AlRaziWebsite;

use Carbon\CarbonImmutable;

/**
 * Turns one row of the Al-Razi school website's export into the `data` of a
 * Manara form response.
 *
 * Pure: no database, no config, no clock. The command decides what to do with the
 * result; this class only decides what a website row MEANS here. The target field
 * names are the ones declared in database/forms/alrazi-website-registration.json
 * and alrazi-website-careers.json, and AlRaziSubmissionMapperTest asserts that
 * every key emitted below is declared there — FormSchema::only() drops an
 * undeclared key without a word, so a typo here would lose a column silently.
 *
 * The source shape is the site's own type, `RegistrationFormData` in
 * src/lib/registration-types.ts, and the two tables' columns.
 *
 * ## What is never imported, whatever the export sends
 *
 *  - Any key named like an SSN, anywhere in the row (`student.ssn` is the one the
 *    site has). The export already strips it; this is the second belt.
 *  - Any document that is not in one of the six categories below, compared
 *    trimmed and lowercased ("SSN_CARD", " ssn_card " and "social_security_card"
 *    are all simply not on the list), and any document whose file name contains
 *    "ssn" in any case, whatever category it claims. The category is typed by the
 *    family's browser and the site never checks it; the site's own upload path
 *    names the card `incoming/<uuid>-ssn_card-<file>`. Neither is returned as a
 *    file to fetch, so its path is never even sent to be signed.
 *  - `medical.insurance_policy_number`, unless the caller opts in
 *    (ALRAZI_EXPORT_INCLUDE_INSURANCE).
 *  - `stripe_session_id`: no use to staff.
 *
 * ## Keys this class does not know
 *
 * are reported back by PATH ONLY ("data.student.shoe_size", with list positions
 * written as `*`), never with their value, so the command can log that the site
 * has grown a field without putting a child's details in the log.
 *
 * @phpstan-type Mapped array{
 *     external_ref: ?string,
 *     submitted_at: ?CarbonImmutable,
 *     data: array<string,mixed>,
 *     files: list<array{field: string, bucket: string, path: string, original_name: string, content_type: ?string, size_bytes: ?int}>,
 *     unknown: list<string>,
 * }
 */
class SubmissionMapper
{
    public const REGISTRATION_BUCKET = 'application-documents';

    public const RESUME_BUCKET = 'resumes';

    /** A key with one of these names is dropped wherever it appears, compared case-insensitively. */
    private const NEVER_KEYS = ['ssn', 'social_security_number', 'social_security'];

    /**
     * Document category => the form's file field. An ALLOW-list: a category that
     * is not here is never fetched. `ssn_card` is deliberately absent.
     */
    public const DOCUMENT_FIELDS = [
        'birth_certificate' => 'docBirthCertificate',
        'immunization' => 'docImmunization',
        'prior_records' => 'docPriorRecords',
        'custody' => 'docCustody',
        'toilet_training' => 'docToiletTraining',
        'food_allergy_plan' => 'docFoodAllergyPlan',
    ];

    /** The category that is never fetched, never signed and never stored. */
    public const SSN_CARD_CATEGORY = 'ssn_card';

    public const INSURANCE_POLICY_PATH = 'medical.insurance_policy_number';

    /**
     * Written by the sync, never by this class: "Removed from the website on
     * YYYY-MM-DD" on a row the export stopped returning. Declared in both forms.
     */
    public const REMOVED_FIELD = 'websiteRemoved';

    /**
     * The text field that holds a SHA-256 of the website's storage path for the
     * document in $fileField. The path itself is the family's words (a child's
     * name is a common filename), so only its hash is kept; a new hash means the
     * family replaced the document on the website.
     */
    public static function refField(string $fileField): string
    {
        return $fileField . 'Ref';
    }

    /**
     * The text field where the sync says, for staff, why a document that is on
     * the website is not here ("On the website, not importable here (HEIC image)").
     * Written by the sync, never by this class.
     */
    public static function statusField(string $fileField): string
    {
        return $fileField . 'Status';
    }

    /** The reference stored for one storage path. */
    public static function pathRef(string $path): string
    {
        return hash('sha256', $path);
    }

    /**
     * registration_applications columns => form field. Applied AFTER the `data`
     * paths below, so where both carry the same value (the student's name, the
     * parent's email) a non-empty column wins.
     */
    private const REGISTRATION_COLUMNS = [
        'grade' => 'grade',
        'program' => 'program',
        'student_first_name' => 'studentFirstName',
        'student_middle_name' => 'studentMiddleName',
        'student_last_name' => 'studentLastName',
        'student_dob' => 'studentDob',
        'parent_first_name' => 'parentFirstName',
        'parent_last_name' => 'parentLastName',
        'parent_email' => 'parentEmail',
        'parent_phone' => 'parentPhone',
        'tuition_plan' => 'websiteTuitionPlan',
        'payment_method' => 'websitePaymentMethod',
        'payment_status' => 'websitePaymentStatus',
        'stripe_payment_intent_id' => 'websiteStripePaymentId',
    ];

    /** Integer-cent columns, stored as dollar strings ("1250.00"). */
    private const REGISTRATION_CENTS = [
        'base_amount_cents' => 'websiteBaseAmount',
        'surcharge_cents' => 'websiteSurcharge',
        'total_amount_cents' => 'websiteTotal',
        'amount_paid_cents' => 'websiteAmountPaid',
    ];

    /**
     * Columns that are understood but not copied into `data`: the id and date
     * become external_ref and submitted_at, `data` and `documents` are read
     * below, `status` belongs to staff once the row is here (it is 'new' on
     * create), and stripe_session_id is no use to anyone.
     */
    private const REGISTRATION_HANDLED = ['id', 'created_at', 'updated_at', 'data', 'documents', 'status', 'stripe_session_id'];

    /** Leaf paths inside registration_applications.data => form field. */
    private const REGISTRATION_DATA = [
        'student.first_name' => 'studentFirstName',
        'student.middle_name' => 'studentMiddleName',
        'student.last_name' => 'studentLastName',
        'student.nickname' => 'studentNickname',
        'student.gender' => 'studentGender',
        'student.dob' => 'studentDob',
        'student.age' => 'studentAge',
        'student.place_of_birth_city' => 'studentBirthCity',
        'student.place_of_birth_state' => 'studentBirthState',
        'student.home_street' => 'homeStreet',
        'student.home_city' => 'homeCity',
        'student.home_state' => 'homeState',
        'student.home_zip' => 'homeZip',
        'student.previous_school' => 'previousSchool',
        'student.previous_school_address' => 'previousSchoolAddress',
        'student.previous_school_from' => 'previousSchoolFrom',
        'student.previous_school_to' => 'previousSchoolTo',
        'student.reason_for_leaving' => 'reasonForLeaving',

        'language.first_language' => 'firstLanguage',
        'language.languages_at_home' => 'languagesAtHome',
        'language.speaks_english_fluently' => 'speaksEnglishFluently',
        'language.reads_writes_english' => 'readsWritesEnglish',
        'language.needs_english_support' => 'needsEnglishSupport',

        // parent1's email and cell phone are the parent_email / parent_phone
        // columns, which the site copies from exactly these two.
        'parent1.email' => 'parentEmail',
        'parent1.cell_phone' => 'parentPhone',
        'primary_contact' => 'primaryContact',

        'medical.physician_name' => 'physicianName',
        'medical.physician_phone' => 'physicianPhone',
        'medical.clinic' => 'clinic',
        'medical.insurance_provider' => 'insuranceProvider',
        self::INSURANCE_POLICY_PATH => 'insurancePolicyNumber',
        'medical.conditions.asthma' => 'conditionAsthma',
        'medical.conditions.heart_disease' => 'conditionHeartDisease',
        'medical.conditions.epilepsy' => 'conditionEpilepsy',
        'medical.conditions.diabetes' => 'conditionDiabetes',
        'medical.conditions.adhd_add' => 'conditionAdhd',
        'medical.conditions.allergies' => 'allergies',
        'medical.conditions.other' => 'otherConditions',
        'medical.current_medications' => 'currentMedications',
        'medical.dietary_restrictions' => 'dietaryRestrictions',
        'medical.emergency_authorizations.administer_first_aid' => 'authFirstAid',
        'medical.emergency_authorizations.contact_ems' => 'authContactEms',
        'medical.emergency_authorizations.transport_to_facility' => 'authTransport',

        'tuition_agreement.plan' => 'tuitionAgreementPlan',
        'tuition_agreement.nco_scholarship' => 'ncoScholarship',
        'tuition_agreement.nco_scholarship_responsibility' => 'ncoScholarshipResponsibility',
        'tuition_agreement.financially_responsible' => 'financiallyResponsible',
        'tuition_agreement.pays_on_time' => 'paysOnTime',
        'tuition_agreement.understands_late_fee' => 'understandsLateFee',
        'tuition_agreement.attends_regularly' => 'attendsRegularly',
        'tuition_agreement.understands_absence_policy' => 'understandsAbsencePolicy',
        'tuition_agreement.supports_islamic_values' => 'supportsIslamicValues',
        'tuition_agreement.follows_rules_islamic_etiquette' => 'followsRulesIslamicEtiquette',
        'tuition_agreement.understands_disciplinary_consequences' => 'understandsDisciplinaryConsequences',
        'tuition_agreement.cooperates_with_admin' => 'cooperatesWithAdmin',
        'tuition_agreement.responsible_for_supplies' => 'responsibleForSupplies',
        'tuition_agreement.replaces_damaged_property' => 'replacesDamagedProperty',
        'tuition_agreement.parent1_signature' => 'tuitionParent1Signature',
        'tuition_agreement.parent1_signed_at' => 'tuitionParent1SignedAt',
        'tuition_agreement.parent2_signature' => 'tuitionParent2Signature',
        'tuition_agreement.parent2_signed_at' => 'tuitionParent2SignedAt',

        'consents.photo_video' => 'photoVideoConsent',
        'consents.field_trip' => 'fieldTripConsent',
        'consents.technology_use_agreed' => 'technologyUseAgreed',
        'consents.islamic_studies_agreed' => 'islamicStudiesAgreed',
        'consents.health_screening' => 'healthScreeningConsent',
        'consents.communication_preferences.email' => 'commEmail',
        'consents.communication_preferences.text' => 'commText',
        'consents.communication_preferences.whatsapp' => 'commWhatsapp',
        'consents.communication_preferences.printed' => 'commPrinted',
        'consents.consent_signature' => 'consentSignature',
        'consents.consent_signed_at' => 'consentSignedAt',

        'policy_acks.attendance' => 'ackAttendance',
        'policy_acks.uniform' => 'ackUniform',
        'policy_acks.homework' => 'ackHomework',
        'policy_acks.discipline' => 'ackDiscipline',
        'policy_acks.drop_off_pick_up' => 'ackDropOffPickUp',
        'policy_acks.health' => 'ackHealth',
        'policy_acks.communication' => 'ackCommunication',
        'policy_acks.islamic_environment' => 'ackIslamicEnvironment',
        'policy_acks.parent_involvement' => 'ackParentInvolvement',
        'policy_acks.grievance' => 'ackGrievance',
        'policy_acks.acknowledged_at' => 'policyAcknowledgedAt',
        'policy_acks.parent1_signature' => 'policyParent1Signature',
        'policy_acks.parent2_signature' => 'policyParent2Signature',
    ];

    /** ParentRecord leaf path => field suffix, prefixed with parent1 / parent2. */
    private const PARENT_FIELDS = [
        'full_name' => 'FullName',
        'relationship' => 'Relationship',
        'dob' => 'Dob',
        'place_of_birth' => 'PlaceOfBirth',
        'occupation' => 'Occupation',
        'employer' => 'Employer',
        'work_street' => 'WorkStreet',
        'work_city' => 'WorkCity',
        'work_state' => 'WorkState',
        'work_zip' => 'WorkZip',
        'work_phone' => 'WorkPhone',
        'cell_phone' => 'CellPhone',
        'home_phone' => 'HomePhone',
        'email' => 'Email',
        'highest_education' => 'Education',
        'languages_spoken.english' => 'SpeaksEnglish',
        'languages_spoken.arabic' => 'SpeaksArabic',
        'languages_spoken.other' => 'OtherLanguages',
        'preferred_communication' => 'PreferredCommunication',
    ];

    /** The one repeatable section: data.emergency_contacts[] rows. */
    public const EMERGENCY_SECTION = 'emergencyContacts';

    private const EMERGENCY_FIELDS = [
        'name' => 'name',
        'relationship' => 'relationship',
        'phone' => 'phone',
        'alt_phone' => 'altPhone',
        'address' => 'address',
    ];

    /**
     * data.authorized_pickup[] is "up to 3" on the site. A form may have only one
     * repeatable section (ValidFormSchema), and emergency contacts have it, so the
     * pickup people are three fixed slots; a fourth is reported, not dropped quietly.
     */
    public const PICKUP_SLOTS = 3;

    private const PICKUP_FIELDS = [
        'name' => 'Name',
        'relationship' => 'Relationship',
    ];

    private const CAREERS_COLUMNS = [
        'role' => 'role',
        'applicant_first_name' => 'applicantFirstName',
        'applicant_last_name' => 'applicantLastName',
        'email' => 'email',
        'phone' => 'phone',
        'start_availability' => 'startAvailability',
        'resume_url' => 'resumeLink',
        'cover_letter' => 'coverLetter',
        'refs' => 'refs',
    ];

    private const CAREERS_HANDLED = ['id', 'created_at', 'updated_at', 'status', 'resume_path'];

    public const RESUME_FIELD = 'resume';

    /**
     * One registration_applications row.
     *
     * @param  array<string,mixed>  $row
     * @return Mapped
     */
    public static function registration(array $row, bool $includeInsurance = false): array
    {
        $unknown = [];
        $data = [];

        $row = self::withoutNeverKeys($row);

        foreach ($row as $column => $value) {
            if (in_array($column, self::REGISTRATION_HANDLED, true)
                || array_key_exists($column, self::REGISTRATION_COLUMNS)
                || array_key_exists($column, self::REGISTRATION_CENTS)) {
                continue;
            }
            $unknown[] = (string) $column;
        }

        $inner = $row['data'] ?? [];

        if (! is_array($inner)) {
            $unknown[] = 'data';
            $inner = [];
        }

        // The two lists first, so the leaf walk below never sees them.
        $emergency = $inner['emergency_contacts'] ?? null;
        $pickup = $inner['authorized_pickup'] ?? null;
        unset($inner['emergency_contacts'], $inner['authorized_pickup']);

        $dataMap = self::REGISTRATION_DATA;
        foreach ([1, 2] as $n) {
            foreach (self::PARENT_FIELDS as $leaf => $suffix) {
                // parent1's email and cell phone already map to the columns' fields.
                $dataMap["parent{$n}.{$leaf}"] ??= "parent{$n}{$suffix}";
            }
        }

        foreach (self::leaves($inner) as $path => $value) {
            if ($path === self::INSURANCE_POLICY_PATH && ! $includeInsurance) {
                continue;
            }

            if (! array_key_exists($path, $dataMap)) {
                $unknown[] = 'data.' . $path;

                continue;
            }

            self::put($data, $dataMap[$path], $value);
        }

        $data[self::EMERGENCY_SECTION] = self::emergencyRows($emergency, $unknown);
        if ($data[self::EMERGENCY_SECTION] === []) {
            unset($data[self::EMERGENCY_SECTION]);
        }

        self::pickupSlots($pickup, $data, $unknown);

        foreach (self::REGISTRATION_COLUMNS as $column => $field) {
            if (array_key_exists($column, $row) && ! self::put($data, $field, $row[$column])) {
                $unknown[] = $column;
            }
        }

        foreach (self::REGISTRATION_CENTS as $column => $field) {
            $cents = $row[$column] ?? null;

            if ($cents === null || $cents === '') {
                continue;
            }

            if (! is_int($cents) && ! (is_string($cents) && preg_match('/^-?\d+$/', $cents))) {
                $unknown[] = $column;

                continue;
            }

            $data[$field] = self::dollars((int) $cents);
        }

        $files = self::documentFiles($row['documents'] ?? null, $unknown);

        foreach ($files as $file) {
            $data[self::refField($file['field'])] = self::pathRef($file['path']);
        }

        return [
            'external_ref' => self::externalRef($row),
            'submitted_at' => self::submittedAt($row),
            'data' => $data,
            'files' => $files,
            'unknown' => self::normalisePaths($unknown),
        ];
    }

    /**
     * One careers_applications row.
     *
     * @param  array<string,mixed>  $row
     * @return Mapped
     */
    public static function careers(array $row): array
    {
        $unknown = [];
        $data = [];

        $row = self::withoutNeverKeys($row);

        foreach ($row as $column => $value) {
            if (in_array($column, self::CAREERS_HANDLED, true)) {
                continue;
            }

            if (! array_key_exists($column, self::CAREERS_COLUMNS)) {
                $unknown[] = (string) $column;

                continue;
            }

            if (! self::put($data, self::CAREERS_COLUMNS[$column], $value)) {
                $unknown[] = (string) $column;
            }
        }

        $files = [];
        $path = $row['resume_path'] ?? null;

        if (is_string($path) && trim($path) !== '') {
            $data[self::refField(self::RESUME_FIELD)] = self::pathRef($path);
            $files[] = [
                'field' => self::RESUME_FIELD,
                'bucket' => self::RESUME_BUCKET,
                'path' => $path,
                'original_name' => self::nameFromPath($path),
                'content_type' => null,
                'size_bytes' => null,
            ];
        } elseif ($path !== null && $path !== '') {
            $unknown[] = 'resume_path';
        }

        return [
            'external_ref' => self::externalRef($row),
            'submitted_at' => self::submittedAt($row),
            'data' => $data,
            'files' => $files,
            'unknown' => self::normalisePaths($unknown),
        ];
    }

    // ------------------------------------------------------------------ pieces

    /**
     * Set one answer. Blank is no answer, so an empty column never erases what the
     * `data` walk found. Returns false for a value that is not a scalar at all —
     * the caller reports its path, because the site has changed shape there.
     *
     * @param  array<string,mixed>  $data
     */
    private static function put(array &$data, string $field, mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $data[$field] = $value;

        return true;
    }

    /**
     * Every scalar leaf of a nested array, keyed by its dot path. An empty array
     * is no leaf at all; a list's positions are numeric segments, which
     * normalisePaths() turns into `*` for reporting.
     *
     * @param  array<mixed>  $tree
     * @return array<string,mixed>
     */
    private static function leaves(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += self::leaves($value, $path);

                continue;
            }

            $out[$path] = $value;
        }

        return $out;
    }

    /**
     * @param  list<string>  $unknown
     * @return list<array<string,mixed>>
     */
    private static function emergencyRows(mixed $rows, array &$unknown): array
    {
        if ($rows === null) {
            return [];
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            $unknown[] = 'data.emergency_contacts';

            return [];
        }

        $out = [];

        foreach ($rows as $i => $contact) {
            if (! is_array($contact)) {
                $unknown[] = "data.emergency_contacts.{$i}";

                continue;
            }

            $mapped = [];

            foreach (self::leaves($contact) as $path => $value) {
                if (! array_key_exists($path, self::EMERGENCY_FIELDS)) {
                    $unknown[] = "data.emergency_contacts.{$i}.{$path}";

                    continue;
                }

                self::put($mapped, self::EMERGENCY_FIELDS[$path], $value);
            }

            if ($mapped !== []) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<string>  $unknown
     */
    private static function pickupSlots(mixed $people, array &$data, array &$unknown): void
    {
        if ($people === null) {
            return;
        }

        if (! is_array($people) || ! array_is_list($people)) {
            $unknown[] = 'data.authorized_pickup';

            return;
        }

        foreach ($people as $i => $person) {
            if ($i >= self::PICKUP_SLOTS) {
                // Reported as a path, like any field with no home here.
                $unknown[] = "data.authorized_pickup.{$i}";

                continue;
            }

            if (! is_array($person)) {
                $unknown[] = "data.authorized_pickup.{$i}";

                continue;
            }

            $slot = $i + 1;

            foreach (self::leaves($person) as $path => $value) {
                if (! array_key_exists($path, self::PICKUP_FIELDS)) {
                    $unknown[] = "data.authorized_pickup.{$i}.{$path}";

                    continue;
                }

                self::put($data, "pickup{$slot}" . self::PICKUP_FIELDS[$path], $value);
            }
        }
    }

    /**
     * The documents to fetch: one per allowed category, and nothing else.
     *
     * @param  list<string>  $unknown
     * @return list<array{field: string, bucket: string, path: string, original_name: string, content_type: ?string, size_bytes: ?int}>
     */
    private static function documentFiles(mixed $documents, array &$unknown): array
    {
        if ($documents === null) {
            return [];
        }

        if (! is_array($documents) || ! array_is_list($documents)) {
            $unknown[] = 'documents';

            return [];
        }

        $files = [];
        $seen = [];

        foreach ($documents as $i => $document) {
            $category = is_array($document) ? ($document['category'] ?? null) : null;
            $category = is_string($category) ? strtolower(trim($category)) : null;

            // The allow-list before ANY other test, so nothing below can so much as
            // read the path of a document outside it. The card is not reported: it
            // is dropped on purpose.
            if ($category === null || ! isset(self::DOCUMENT_FIELDS[$category])) {
                if ($category !== self::SSN_CARD_CATEGORY) {
                    $unknown[] = "documents.{$i}";
                }

                continue;
            }

            $path = $document['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                $unknown[] = "documents.{$i}";

                continue;
            }

            // Filed under an allowed category, but named as the card by the site's
            // own upload path. The category is the browser's word; the name is not.
            if (self::namesAnSsnCard($path)) {
                continue;
            }

            $field = self::DOCUMENT_FIELDS[$category];

            // One file per question per response (the attachments table's unique
            // index). A second document in the same category has nowhere to go.
            if (isset($seen[$field])) {
                $unknown[] = "documents.{$i}";

                continue;
            }
            $seen[$field] = true;

            $original = $document['original_filename'] ?? null;
            $size = $document['size_bytes'] ?? null;
            $type = $document['content_type'] ?? null;

            $files[] = [
                'field' => $field,
                'bucket' => self::REGISTRATION_BUCKET,
                'path' => $path,
                'original_name' => is_string($original) && trim($original) !== '' ? $original : self::nameFromPath($path),
                'content_type' => is_string($type) && $type !== '' ? $type : null,
                'size_bytes' => is_int($size) ? $size : null,
            ];
        }

        return $files;
    }

    /**
     * Whether a storage path's final segment names a Social Security number or card.
     *
     * "ssn" must stand as its own token — bounded by a non-letter or an end — so
     * `ssn.jpg`, `ssn_card`, `-ssn_card-` and `SSN-front.png` are caught, while a
     * child called Hassna or Hassnain, or a teacher called Jessner, is not. A raw
     * substring match silently dropped those families' birth certificates. Any
     * spelling of "social security" is caught as well. The rule MUST stay
     * identical to `pathNamesSsn()` in the site's export function.
     */
    private static function namesAnSsnCard(string $path): bool
    {
        $name = basename(str_replace('\\', '/', $path));

        return preg_match('/(?:^|[^a-z])ssn(?:[^a-z]|$)|social[^a-z]*security/i', $name) === 1;
    }

    /**
     * Drop every SSN-shaped key at any depth, before anything else looks at the row.
     *
     * @param  array<mixed>  $tree
     * @return array<mixed>
     */
    private static function withoutNeverKeys(array $tree): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::NEVER_KEYS, true)) {
                continue;
            }

            $out[$key] = is_array($value) ? self::withoutNeverKeys($value) : $value;
        }

        return $out;
    }

    /**
     * The storage object's own name, less the random prefix the site gives it
     * ("<uuid>-resume.pdf" -> "resume.pdf"). Only a fallback: registrations carry
     * the family's own filename.
     */
    private static function nameFromPath(string $path): string
    {
        $name = basename(str_replace('\\', '/', $path));
        $name = preg_replace('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-/i', '', $name) ?? $name;

        return $name !== '' ? $name : 'document';
    }

    /** @param  array<string,mixed>  $row */
    private static function externalRef(array $row): ?string
    {
        $id = $row['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $id = trim((string) $id);

        // The column is 64 wide; a longer id is not one this import understands.
        return $id !== '' && strlen($id) <= 64 ? $id : null;
    }

    /** @param  array<string,mixed>  $row */
    private static function submittedAt(array $row): ?CarbonImmutable
    {
        $at = $row['created_at'] ?? null;

        if (! is_string($at) || trim($at) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($at);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Integer cents as a plain dollar string, with no float in between. */
    private static function dollars(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Paths for the log: list positions become `*`, duplicates collapse, sorted.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function normalisePaths(array $paths): array
    {
        $out = array_map(
            fn (string $path) => preg_replace('/(?<=^|\.)\d+(?=\.|$)/', '*', $path) ?? $path,
            $paths
        );

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }
}
