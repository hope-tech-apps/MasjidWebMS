<?php

namespace App\Support;

use App\Models\AppointmentRequest;
use App\Models\ContactCredential;
use App\Models\FormResponse;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationPayment;
// Carbon\CarbonImmutable, not Illuminate\Support\CarbonImmutable — Laravel only
// ships a mutable Illuminate\Support\Carbon; there is no immutable counterpart.
// Same import DonationMetrics makes, for the same reason.
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * ImpactMetrics — the ADMIN-SIDE read model behind an impact / grant report.
 *
 * ## What this is for
 *
 * Muslim nonprofits and free clinics are asked for impact figures every time
 * they apply for a grant or file a funder report ("visits served", "value of
 * care", "volunteers"). Today they hand-assemble them from spreadsheets while
 * Manara already holds the underlying rows. This class computes the REAL
 * numbers, for a stated period, from the tables that already exist, so an
 * administrator can look one up and decide what to publish.
 *
 * ## What this is NOT: the impact_stats page section (T-020) stays authoritative
 *
 * `SectionType::IMPACT_STATS` is a PAGE-BUILDER section whose `stats[].value`
 * is DISPLAY TEXT an admin types ("6,000+", "$6.3M", "1 in 4"). Those are
 * audited, published, editorially-rounded claims for a stated reporting window.
 * NOTHING here writes to a page section, subscribes to one, or reads one:
 *
 *   - there is no write path from this class to `page_sections` (grep it);
 *   - `SectionType::IMPACT_STATS` is untouched — still author-supplied
 *     (`usesDynamicContent() === false`), still the same `content` shape;
 *   - the only bridge is a human one: every metric carries a `formatted`
 *     string an admin may CHOOSE to copy into the section by hand.
 *
 * That asymmetry is the point. A published funder figure must not silently
 * change because a row was added to a CRM table this morning.
 *
 * ## Tenant isolation
 *
 * MySQL has no row-level security, so this is entirely app-layer
 * (.claude/rules/tenant-scoping.md). Two mechanisms, both belt AND braces:
 *
 *   1. Every aggregate runs inside `withTenant()`, which BINDS TenantContext to
 *      the masjid being reported on for the duration of the computation and
 *      restores the previous binding afterwards. DonationMetrics relies on the
 *      ambient binding alone, which is correct on an admin route (the `tenant`
 *      middleware has already bound it, and 403'd a MasjidAdmin aiming
 *      elsewhere) — but a report is the one payload where an UNBOUND caller
 *      (a console command, the Assistant) silently summing every tenant's rows
 *      under one organization's letterhead would be a fabricated funder
 *      document, not a glitch. Binding explicitly makes that impossible for
 *      every caller. A DIFFERENT tenant already bound is a programmer error and
 *      throws rather than being quietly overridden.
 *   2. `form_responses` has NO BelongsToMasjid trait (it predates the CRM), so
 *      it is the one table filtered by hand, exactly like the pre-CRM models.
 *      Every other source is a trait model and inherits the global scope.
 *
 * Joins never re-scope the joined table: a credential's contact, a membership's
 * group and a registrant's registration all belong to the same masjid as the
 * scoped base row, so scoping the base transitively scopes the join — the same
 * argument DonationMetrics makes for its donations->funds join. What the joins
 * DO add is `deleted_at IS NULL`, because a raw join bypasses the soft-delete
 * scope and a deleted person must not appear in a funder count.
 *
 * ## Money
 *
 * Integer minor units end to end. `value` for a money metric IS the minor-unit
 * integer; `formatted` is the only place it is divided, matching the existing
 * `number_format($cents / 100, 2)` idiom. Money metrics reuse DonationMetrics
 * rather than re-deriving anything, so the impact report and the giving
 * dashboard can never disagree about what "donations this year" means.
 *
 * ## No PHI, ever
 *
 * The appointment aggregates read `created_at` and `status` only. They never
 * touch `reason` or `date_of_birth`, which are encrypted at rest and must never
 * be queried, filtered on, or logged (.claude/rules/appointments.md).
 */
class ImpactMetrics
{
    // ------------------------------------------------------------ metric keys
    // Machine keys are the stable contract. Renaming one BREAKS a report an
    // organization has already filed against it, so treat these as append-only.

    public const APPOINTMENT_REQUESTS_RECEIVED = 'appointment_requests_received';
    public const APPOINTMENT_REQUESTS_SCHEDULED = 'appointment_requests_scheduled';
    public const APPOINTMENT_REQUESTS_CLOSED = 'appointment_requests_closed';
    public const CREDENTIALED_VOLUNTEERS = 'credentialed_volunteers';
    public const CREDENTIALS_VALID = 'credentials_valid';
    public const CREDENTIALS_EXPIRED = 'credentials_expired';
    public const DONATIONS_TOTAL = 'donations_total';
    public const DONORS_IDENTIFIED = 'donors_identified';
    public const DONATIONS_COUNT = 'donations_count';
    public const FORM_SUBMISSIONS = 'form_submissions';
    public const FORM_SUBMISSION_PEOPLE = 'form_submission_people';
    public const ACTIVE_GROUPS = 'active_groups';
    public const GROUP_PARTICIPANTS = 'group_participants';
    public const ACTIVE_OFFERINGS = 'active_offerings';
    public const REGISTRATIONS_CONFIRMED = 'registrations_confirmed';
    public const REGISTRATION_PARTICIPANTS = 'registration_participants';
    public const PROGRAM_FEES_COLLECTED = 'program_fees_collected';

    /**
     * How a metric relates to time. A grant reviewer's first question about any
     * number is "over what window?", and these three answers are genuinely
     * different — conflating them is how an impact report becomes wrong.
     *
     *  - BASIS_PERIOD — a FLOW counted inside [from, to]: things that happened.
     *  - BASIS_AS_OF  — a STOCK evaluated against a single date using dates the
     *    row actually stores (a credential's expires_at), so it is genuinely
     *    recomputable for a past date.
     *  - BASIS_CURRENT — a stock read off a CURRENT flag the platform keeps no
     *    history for (`groups.is_active`). It describes today and cannot be
     *    recomputed for a past period; saying so is the whole point of the
     *    basis field.
     */
    public const BASIS_PERIOD = 'period';
    public const BASIS_AS_OF = 'as_of';
    public const BASIS_CURRENT = 'current';

    /** Value units. `money_minor` is an integer in the currency's minor units. */
    public const UNIT_COUNT = 'count';
    public const UNIT_MONEY_MINOR = 'money_minor';

    /**
     * Why a computed metric is not in the response. Reported rather than
     * silently dropped: a reader must be able to tell "we did not ask" from
     * "the answer was zero".
     */
    public const OMITTED_NO_DATA = 'not_in_default_set_and_no_data';
    public const OMITTED_PERMISSION = 'requires_view_donations_permission';

    public function __construct(
        private readonly Masjid $masjid,
        private readonly string $timezone,
    ) {
    }

    /**
     * Masjids created before the timezone column existed default to the app
     * timezone, so the period boundaries stay defined rather than throwing —
     * same fallback as DonationMetrics::forMasjid().
     */
    public static function forMasjid(Masjid $masjid): self
    {
        return new self($masjid, $masjid->timezone ?: (string) config('app.timezone', 'UTC'));
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * The report: the selected metrics, in a stable order.
     *
     * @param  array{from?:?string,to?:?string}  $filters  raw yyyy-mm-dd strings
     * @param  bool  $includeMoney  caller holds `view donations` (money-bearing
     *                              metrics are omitted, and SAID to be omitted,
     *                              when they do not)
     * @return array{metrics:array<int,array<string,mixed>>,omitted:array<int,array{key:string,reason:string}>}
     */
    public function report(array $filters = [], bool $includeMoney = true): array
    {
        $window = $this->window($filters);
        $values = $this->values($window, $includeMoney);

        $metrics = [];
        $omitted = [];

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];

            if ($definition['money'] && ! $includeMoney) {
                $omitted[] = ['key' => $key, 'reason' => self::OMITTED_PERMISSION];

                continue;
            }

            $value = (int) ($values[$key] ?? 0);

            // Vertical-aware selection, and the "or" is deliberate: org_type
            // picks the DEFAULT set (a community org sees its intake and
            // credential figures at zero, because a funder asks about them
            // either way), and any metric with real data behind it is included
            // regardless of vertical. A masjid running a food pantry has
            // appointment requests, and hiding them because the tenant is not
            // flagged "community" would be exactly the hardcoding
            // .claude/rules/verticals.md forbids.
            if (! $this->inDefaultSet($definition) && $value === 0) {
                $omitted[] = ['key' => $key, 'reason' => self::OMITTED_NO_DATA];

                continue;
            }

            $metrics[] = $this->present($definition, $value, $window);
        }

        return ['metrics' => $metrics, 'omitted' => $omitted];
    }

    /**
     * What the report was computed under, so the page can label it instead of
     * the reader having to assume. Mirrors DonationMetrics::meta().
     *
     * @param  array{from?:?string,to?:?string}  $filters
     * @return array<string,mixed>
     */
    public function meta(array $filters = []): array
    {
        $window = $this->window($filters);

        return [
            'org_type' => $this->masjid->orgType(),
            'timezone' => $this->timezone,
            'currency' => $this->currency(),
            'period' => [
                'from' => $window['from']?->toDateString(),
                'to' => $window['to']?->toDateString(),
                'as_of' => $window['as_of']->toDateString(),
            ],
            'generated_at' => CarbonImmutable::now($this->timezone)->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------- the catalogue

    /**
     * THE metric catalogue: one entry per figure, carrying its human label, its
     * time basis, its default verticals, and — the part a funder question turns
     * on — the exact table and DEFINITION that produced it.
     *
     * Every definition string is written to be pasted into a grant application
     * footnote. Where the underlying data cannot answer the sharper question a
     * reviewer might ask, the definition says so rather than implying it can.
     *
     * Labels use $masjid->term() for tenant vocabulary — "Halaqat" / "Classrooms"
     * / "Teams" — because .claude/rules/verticals.md forbids hardcoding a
     * vertical's nouns in an admin payload.
     *
     * @return array<int,array<string,mixed>>
     */
    private function definitions(): array
    {
        $groups = $this->masjid->term('groups');
        $programs = $this->masjid->term('programs');

        $community = [Masjid::ORG_TYPE_COMMUNITY];
        $schoolAndCommunity = [Masjid::ORG_TYPE_SCHOOL, Masjid::ORG_TYPE_COMMUNITY];
        $everyVertical = Masjid::ORG_TYPES;

        return [
            // ------------------------------------------------- intake (T-021)
            [
                'key' => self::APPOINTMENT_REQUESTS_RECEIVED,
                'label' => 'Appointment requests received',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $community,
                'source' => 'appointment_requests',
                'definition' => 'Appointment requests submitted during the period, counted by created_at, '
                    . 'in every triage status (new, contacted, scheduled, closed). One row is one REQUEST, '
                    . 'not one person: the same person asking twice counts twice, and the platform holds no '
                    . 'identity matching that could collapse them.',
            ],
            [
                'key' => self::APPOINTMENT_REQUESTS_SCHEDULED,
                'label' => 'Appointment requests scheduled',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $community,
                'source' => 'appointment_requests',
                'definition' => 'Requests submitted during the period whose status is `scheduled` AS OF NOW. '
                    . 'There is no status-history table, so this is the current state of each request, not a '
                    . 'count of scheduling events — a request that was scheduled and has since been closed '
                    . 'appears under closed, not here. It also records that an appointment was BOOKED; the '
                    . 'platform stores no attendance, so it must not be reported as visits delivered.',
            ],
            [
                'key' => self::APPOINTMENT_REQUESTS_CLOSED,
                'label' => 'Appointment requests closed',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $community,
                'source' => 'appointment_requests',
                'definition' => 'Requests submitted during the period whose status is `closed` AS OF NOW — the '
                    . 'same current-state caveat as scheduled. `closed` means triage finished; it does not by '
                    . 'itself assert that a visit happened or that the person was helped.',
            ],

            // -------------------------------------------- volunteers (T-023)
            [
                'key' => self::CREDENTIALED_VOLUNTEERS,
                'label' => 'Credentialed volunteers',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_AS_OF,
                'money' => false,
                'verticals' => $schoolAndCommunity,
                'source' => 'contact_credentials, contacts',
                'definition' => 'Distinct people holding at least one credential that had not expired on the '
                    . 'as-of date (expires_at NULL means non-expiring, otherwise expires_at >= as-of). One '
                    . 'person with three credentials counts once; deleted contacts are excluded. This counts '
                    . 'people who are CREDENTIALED, not people who served — the platform records no shifts '
                    . 'and no volunteer hours, so it cannot support an hours figure.',
            ],
            [
                'key' => self::CREDENTIALS_VALID,
                'label' => 'Valid credentials on file',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_AS_OF,
                'money' => false,
                'verticals' => $schoolAndCommunity,
                'source' => 'contact_credentials',
                'definition' => 'Credential RECORDS (licenses, background checks, certifications) not expired '
                    . 'on the as-of date. Counts records, not people — read alongside credentialed_volunteers, '
                    . 'which counts people.',
            ],
            [
                'key' => self::CREDENTIALS_EXPIRED,
                'label' => 'Expired credentials on file',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_AS_OF,
                'money' => false,
                'verticals' => $schoolAndCommunity,
                'source' => 'contact_credentials',
                'definition' => 'Credential records whose expires_at fell before the as-of date. The renewal '
                    . 'gap: reported next to valid credentials so coverage is legible rather than implied.',
            ],

            // ------------------------------------------------------- giving
            [
                'key' => self::DONATIONS_TOTAL,
                'label' => 'Donations received',
                'unit' => self::UNIT_MONEY_MINOR,
                'basis' => self::BASIS_PERIOD,
                'money' => true,
                'verticals' => $everyVertical,
                'source' => 'donations (via App\Support\DonationMetrics)',
                'definition' => 'Gross amount of donations with status `succeeded` in the period, in integer '
                    . 'minor units. Computed by DonationMetrics — the same code behind the giving dashboard, '
                    . 'so this figure cannot drift from it. Gift date is donated_at when present (offline '
                    . 'gifts, a wall-calendar day) and created_at otherwise, each compared in its own time '
                    . 'frame. GROSS: processor fees are not deducted.',
            ],
            [
                'key' => self::DONORS_IDENTIFIED,
                'label' => 'Identified donors',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => true,
                'verticals' => $everyVertical,
                'source' => 'donations (via App\Support\DonationMetrics)',
                'definition' => 'Distinct donors behind those gifts (COUNT DISTINCT contact_id). Gifts with no '
                    . 'contact — walk-in cash, an unmatched import row — carry no identity and are excluded '
                    . 'here while still counting in the total, so this is a FLOOR on the number of people who '
                    . 'gave, never the whole number.',
            ],
            [
                'key' => self::DONATIONS_COUNT,
                'label' => 'Gifts received',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => true,
                'verticals' => $everyVertical,
                'source' => 'donations (via App\Support\DonationMetrics)',
                'definition' => 'Number of succeeded gifts in the period, anonymous gifts included. One '
                    . 'recurring commitment charged monthly is twelve gifts, not one.',
            ],

            // -------------------------------------------------------- forms
            [
                'key' => self::FORM_SUBMISSIONS,
                'label' => 'Form submissions',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'form_responses',
                'definition' => 'Submissions to this organization\'s forms during the period, counted by '
                    . 'submitted_at. Submissions to forms that have since been deleted are included (the '
                    . 'submission is a historical fact), and so are registration intake submissions, which '
                    . 'also appear under the registration metrics — those answer a different question, so '
                    . 'reporting both is not double counting one.',
            ],
            [
                'key' => self::FORM_SUBMISSION_PEOPLE,
                'label' => 'People represented by form submissions',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'form_responses',
                'definition' => 'SUM(entry_count) over the same submissions: how many people those forms '
                    . 'represent. A family registering four children on one form is 1 submission and 4 here. '
                    . 'Forms without a repeatable section store entry_count = 1, so for them the two figures '
                    . 'match. People are counted per submission, so someone submitting twice counts twice.',
            ],

            // ------------------------------------------- groups / programs
            [
                'key' => self::ACTIVE_GROUPS,
                'label' => 'Active ' . $groups,
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_CURRENT,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'groups',
                'definition' => 'Groups flagged is_active right now, excluding deleted ones. CURRENT state: '
                    . 'the platform keeps no history of the active flag, so this cannot be recomputed for a '
                    . 'past period and does not vary with the period filter. starts_on/ends_on are scheduling '
                    . 'metadata and are deliberately not applied, so a group between terms still counts.',
            ],
            [
                'key' => self::GROUP_PARTICIPANTS,
                'label' => 'People in active ' . $groups,
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_CURRENT,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'group_memberships, groups, contacts',
                'definition' => 'Distinct people holding a leader or member place in a currently-active group. '
                    . 'Guardian edges are EXCLUDED: a guardian row records a relationship to someone else\'s '
                    . 'place, not participation of its own. A person in three groups counts once; deleted '
                    . 'contacts and deleted groups are excluded. CURRENT state, like active groups above.',
            ],
            [
                'key' => self::ACTIVE_OFFERINGS,
                'label' => 'Active ' . $programs,
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_CURRENT,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'offerings',
                'definition' => 'Registerable offerings flagged is_active right now, excluding deleted ones. '
                    . 'CURRENT state, no history. opens_at/closes_at bound the REGISTRATION window rather '
                    . 'than the program\'s run dates, so they are deliberately not applied — an offering '
                    . 'whose signups have closed but whose program is running still counts.',
            ],

            // --------------------------------------------- registrations
            [
                'key' => self::REGISTRATIONS_CONFIRMED,
                'label' => 'Confirmed registrations',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'registrations',
                'definition' => 'Registrations created during the period (created_at) whose SEAT status is '
                    . '`confirmed` as of now. Seat and money are separate state machines: a family behind on '
                    . 'an installment (payment_status past_due) is still enrolled and still counts. One row '
                    . 'is one household signing up for one offering, however many children it covers. '
                    . 'Current status, not a transition log.',
            ],
            [
                'key' => self::REGISTRATION_PARTICIPANTS,
                'label' => 'People enrolled through registration',
                'unit' => self::UNIT_COUNT,
                'basis' => self::BASIS_PERIOD,
                'money' => false,
                'verticals' => $everyVertical,
                'source' => 'registrants, registrations, contacts',
                'definition' => 'Distinct people named on those confirmed registrations (COUNT DISTINCT '
                    . 'registrants.contact_id). A guardian enrolling three children is 1 registration and 3 '
                    . 'people; the same child enrolled in two programs in the period counts once here. '
                    . 'Deleted contacts are excluded.',
            ],
            [
                'key' => self::PROGRAM_FEES_COLLECTED,
                'label' => 'Program fees collected',
                'unit' => self::UNIT_MONEY_MINOR,
                'basis' => self::BASIS_PERIOD,
                'money' => true,
                'verticals' => $everyVertical,
                'source' => 'registration_payments',
                'definition' => 'Sum of amount_minor over registration charges with status `succeeded` and a '
                    . 'paid_at inside the period, in integer minor units. Tuition and program fees actually '
                    . 'collected — NOT donations, and never overlapping them (a separate table and a '
                    . 'separate ledger). Refunded and failed charges are excluded, as are rows with no '
                    . 'paid_at, so an all-time figure and a bounded one are computed over the same '
                    . 'population. GROSS: processor fees are not deducted.',
            ],
        ];
    }

    /**
     * Is this metric in the tenant vertical's DEFAULT set?
     *
     * Read through Masjid::orgType(), never the raw column, so an unrecognized
     * org_type degrades to masjid rather than silently taking another
     * vertical's set (.claude/rules/verticals.md).
     */
    private function inDefaultSet(array $definition): bool
    {
        return in_array($this->masjid->orgType(), $definition['verticals'], true);
    }

    /**
     * One metric as it appears in the response. The shape is the contract: a
     * machine key, a human label, the value, its unit, the period it covers,
     * and the provenance a funder question is answered from.
     *
     * @param  array{from:?CarbonImmutable,to:?CarbonImmutable,as_of:CarbonImmutable}  $window
     * @return array<string,mixed>
     */
    private function present(array $definition, int $value, array $window): array
    {
        $isMoney = $definition['unit'] === self::UNIT_MONEY_MINOR;

        return [
            'key' => $definition['key'],
            'label' => $definition['label'],
            'value' => $value,
            'unit' => $definition['unit'],
            // Non-null only where it means something. A count has no currency,
            // and an empty string would invite someone to print it.
            'currency' => $isMoney ? $this->currency() : null,
            // The ONLY place a minor-unit integer is divided. Everything above
            // stays integral, per .claude/rules/stripe-payments.md.
            'formatted' => $isMoney
                ? $this->currency() . ' ' . number_format($value / 100, 2)
                : number_format($value),
            'basis' => $definition['basis'],
            'period' => $this->periodFor($definition['basis'], $window),
            'provenance' => [
                'source' => $definition['source'],
                'definition' => $definition['definition'],
            ],
        ];
    }

    /**
     * The window THIS metric actually covers — not the window that was asked
     * for. A stock metric bounded by from/to would be a lie: it describes one
     * instant, and saying which instant is the difference between a defensible
     * figure and an unanswerable one.
     *
     * @param  array{from:?CarbonImmutable,to:?CarbonImmutable,as_of:CarbonImmutable}  $window
     * @return array{from:?string,to:?string,as_of:?string,timezone:string}
     */
    private function periodFor(string $basis, array $window): array
    {
        return match ($basis) {
            self::BASIS_PERIOD => [
                'from' => $window['from']?->toDateString(),
                'to' => $window['to']?->toDateString(),
                'as_of' => null,
                'timezone' => $this->timezone,
            ],
            self::BASIS_AS_OF => [
                'from' => null,
                'to' => null,
                'as_of' => $window['as_of']->toDateString(),
                'timezone' => $this->timezone,
            ],
            // A current-state figure describes the moment the report ran, even
            // when the caller asked about 2019.
            self::BASIS_CURRENT => [
                'from' => null,
                'to' => null,
                'as_of' => $this->today()->toDateString(),
                'timezone' => $this->timezone,
            ],
        };
    }

    // -------------------------------------------------------------- the maths

    /**
     * Every metric's raw value, keyed by machine key.
     *
     * Deliberately a handful of grouped queries rather than one per metric:
     * the appointment triage figures, and the credential figures, each come out
     * of a single scan as conditional aggregates, which also guarantees the
     * numbers in a group describe ONE consistent snapshot. The same reasoning
     * as DonationMetrics::summary().
     *
     * @param  array{from:?CarbonImmutable,to:?CarbonImmutable,as_of:CarbonImmutable}  $window
     * @return array<string,int>
     */
    private function values(array $window, bool $includeMoney): array
    {
        return $this->withTenant(function () use ($window, $includeMoney): array {
            $values = array_merge(
                $this->appointmentValues($window),
                $this->credentialValues($window),
                $this->formValues($window),
                $this->groupValues(),
                $this->registrationValues($window),
            );

            // Skipped wholesale when the caller lacks `view donations`: there is
            // no reason to touch the ledger for a payload that will not carry it.
            if ($includeMoney) {
                $values = array_merge($values, $this->moneyValues($window));
            }

            return $values;
        });
    }

    /** @return array<string,int> */
    private function appointmentValues(array $window): array
    {
        $query = AppointmentRequest::query();
        $this->applyWindow($query, 'appointment_requests.created_at', $window);

        // status only — never `reason` or `date_of_birth` (encrypted at rest;
        // .claude/rules/appointments.md forbids querying them at all).
        $row = $query->selectRaw(
            'COUNT(*) AS received,
             SUM(CASE WHEN appointment_requests.status = ? THEN 1 ELSE 0 END) AS scheduled,
             SUM(CASE WHEN appointment_requests.status = ? THEN 1 ELSE 0 END) AS closed',
            [AppointmentRequest::STATUS_SCHEDULED, AppointmentRequest::STATUS_CLOSED]
        )->first();

        return [
            self::APPOINTMENT_REQUESTS_RECEIVED => (int) $row->received,
            self::APPOINTMENT_REQUESTS_SCHEDULED => (int) $row->scheduled,
            self::APPOINTMENT_REQUESTS_CLOSED => (int) $row->closed,
        ];
    }

    /** @return array<string,int> */
    private function credentialValues(array $window): array
    {
        $asOf = $window['as_of']->toDateString();

        // expires_at is a DATE column compared against a yyyy-mm-dd literal, so
        // the comparison is correct on MySQL (native DATE) and on the SQLite
        // test driver (lexicographic on the same format) alike — the same
        // arrangement ContactCredential's scopes use.
        $notExpired = '(contact_credentials.expires_at IS NULL OR contact_credentials.expires_at >= ?)';
        $expired = '(contact_credentials.expires_at IS NOT NULL AND contact_credentials.expires_at < ?)';

        $row = ContactCredential::query()
            // The contact join exists only to drop credentials belonging to a
            // soft-deleted person: a raw join bypasses the SoftDeletes scope,
            // and a removed volunteer must not appear in a funder count. It is
            // not a scoping device — the base row's masjid_id already scopes
            // this, and a credential's contact is always the same tenant.
            ->join('contacts', 'contacts.id', '=', 'contact_credentials.contact_id')
            ->whereNull('contacts.deleted_at')
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN {$notExpired} THEN contact_credentials.contact_id END) AS volunteers,
                 SUM(CASE WHEN {$notExpired} THEN 1 ELSE 0 END) AS valid_records,
                 SUM(CASE WHEN {$expired} THEN 1 ELSE 0 END) AS expired_records",
                [$asOf, $asOf, $asOf]
            )
            ->first();

        return [
            self::CREDENTIALED_VOLUNTEERS => (int) $row->volunteers,
            self::CREDENTIALS_VALID => (int) $row->valid_records,
            self::CREDENTIALS_EXPIRED => (int) $row->expired_records,
        ];
    }

    /** @return array<string,int> */
    private function formValues(array $window): array
    {
        // form_responses predates the CRM and carries NO BelongsToMasjid trait,
        // so this is the one aggregate that must filter by hand — exactly like
        // the pre-CRM controllers do (.claude/rules/tenant-scoping.md). The id
        // is server-derived: it comes from the Masjid the tenant middleware
        // already resolved, never from request input.
        $query = FormResponse::query()->where('form_responses.masjid_id', $this->masjid->id);
        $this->applyWindow($query, 'form_responses.submitted_at', $window);

        $row = $query->selectRaw(
            'COUNT(*) AS submissions, COALESCE(SUM(form_responses.entry_count), 0) AS people'
        )->first();

        return [
            self::FORM_SUBMISSIONS => (int) $row->submissions,
            self::FORM_SUBMISSION_PEOPLE => (int) $row->people,
        ];
    }

    /** @return array<string,int> */
    private function groupValues(): array
    {
        return [
            // SoftDeletes + the tenant scope are both live on these models, so
            // deleted rows and other tenants are already out.
            self::ACTIVE_GROUPS => Group::query()->where('is_active', true)->count(),
            self::ACTIVE_OFFERINGS => Offering::query()->where('is_active', true)->count(),
            self::GROUP_PARTICIPANTS => GroupMembership::query()
                ->join('groups', 'groups.id', '=', 'group_memberships.group_id')
                ->join('contacts', 'contacts.id', '=', 'group_memberships.contact_id')
                // Raw joins see no global scopes — hence both soft-delete
                // filters by hand. Tenancy comes from the scoped base row.
                ->whereNull('groups.deleted_at')
                ->whereNull('contacts.deleted_at')
                ->where('groups.is_active', true)
                ->whereIn('group_memberships.role', GroupMembership::PARTICIPANT_ROLES)
                ->distinct()
                ->count('group_memberships.contact_id'),
        ];
    }

    /** @return array<string,int> */
    private function registrationValues(array $window): array
    {
        $confirmed = Registration::query()->where('registrations.status', Registration::STATUS_CONFIRMED);
        $this->applyWindow($confirmed, 'registrations.created_at', $window);

        $people = Registrant::query()
            ->join('registrations', 'registrations.id', '=', 'registrants.registration_id')
            ->join('contacts', 'contacts.id', '=', 'registrants.contact_id')
            ->whereNull('contacts.deleted_at')
            ->where('registrations.status', Registration::STATUS_CONFIRMED);
        // The window is on the REGISTRATION, not the registrant row: a person is
        // counted for the period their household signed up in, which is the
        // period the seat was taken.
        $this->applyWindow($people, 'registrations.created_at', $window);

        return [
            self::REGISTRATIONS_CONFIRMED => $confirmed->count(),
            self::REGISTRATION_PARTICIPANTS => $people->distinct()->count('registrants.contact_id'),
        ];
    }

    /** @return array<string,int> */
    private function moneyValues(array $window): array
    {
        // Donations go through DonationMetrics rather than a second SUM: the
        // money rules (which statuses count, how a gift's date is decided, how
        // the masjid's timezone applies to two differently-framed columns) are
        // subtle enough that a parallel implementation WOULD drift, and a
        // funder report disagreeing with the giving dashboard is worse than
        // either being wrong alone. `all_time` is the bucket bounded only by
        // the caller's from/to, i.e. exactly the requested period.
        $donations = DonationMetrics::forMasjid($this->masjid)->summary([
            'from' => $window['from']?->toDateString(),
            'to' => $window['to']?->toDateString(),
        ])['all_time'];

        $fees = RegistrationPayment::query()
            ->where('registration_payments.status', RegistrationPayment::STATUS_SUCCEEDED)
            // No paid_at means the charge never reported a settlement time.
            // Excluded always, so the all-time figure and a bounded one are
            // computed over the same population rather than differing by rows
            // that a window silently drops.
            ->whereNotNull('registration_payments.paid_at');
        $this->applyWindow($fees, 'registration_payments.paid_at', $window);

        return [
            self::DONATIONS_TOTAL => (int) $donations['gross_cents'],
            self::DONORS_IDENTIFIED => (int) $donations['donor_count'],
            self::DONATIONS_COUNT => (int) $donations['gift_count'],
            self::PROGRAM_FEES_COLLECTED => (int) $fees->sum('registration_payments.amount_minor'),
        ];
    }

    // ------------------------------------------------------------- plumbing

    /**
     * Run $callback with TenantContext bound to the masjid being reported on,
     * restoring whatever was bound before.
     *
     * A different tenant already bound is a PROGRAMMER ERROR — it means a
     * controller resolved one masjid from the route and the middleware bound
     * another — and it throws rather than being overridden, because silently
     * winning that argument is how one organization's figures end up on
     * another's grant application. On every admin route the two agree
     * (ResolveMasjidTenant 403s a MasjidAdmin aiming elsewhere and binds a
     * SuperAdmin to the masjid the URL names), so this never fires in practice;
     * it exists so that it cannot start firing silently.
     */
    private function withTenant(callable $callback): mixed
    {
        $tenant = app(TenantContext::class);
        $previous = $tenant->get();

        if ($previous !== null && $previous !== (int) $this->masjid->id) {
            throw new RuntimeException(
                'ImpactMetrics refused to report on masjid ' . $this->masjid->id
                . ' while tenant ' . $previous . ' is bound.'
            );
        }

        $tenant->set((int) $this->masjid->id);

        try {
            return $callback();
        } finally {
            $previous === null ? $tenant->forgetTenant() : $tenant->set($previous);
        }
    }

    /**
     * The caller's from/to as masjid-local calendar days, plus the as-of date
     * the stock metrics are evaluated against.
     *
     * `to` is INCLUSIVE to the admin who typed it, so the window is closed at
     * the start of the following day — same convention as DonationMetrics.
     *
     * as_of is clamped to today: a report run in August for a period ending in
     * December must not evaluate credentials against a date that has not
     * happened, which would count a license expiring in October as already
     * lapsed. Clamping mirrors DonationMetrics stopping its live buckets at the
     * end of today rather than the end of the period.
     *
     * @param  array{from?:?string,to?:?string}  $filters
     * @return array{from:?CarbonImmutable,to:?CarbonImmutable,to_exclusive:?CarbonImmutable,as_of:CarbonImmutable}
     */
    private function window(array $filters): array
    {
        $from = $this->localDay($filters['from'] ?? null);
        $to = $this->localDay($filters['to'] ?? null);
        $today = $this->today();

        // A null bound is unbounded on that side — the forms/offerings
        // convention, and what DonationMetrics does with an absent filter.
        $asOf = ($to !== null && $to->lt($today)) ? $to : $today;

        return [
            'from' => $from,
            'to' => $to,
            'to_exclusive' => $to?->addDay(),
            'as_of' => $asOf,
        ];
    }

    /**
     * Constrain a query to the period on a TIMESTAMP column.
     *
     * The boundaries the admin typed are masjid-local calendar days; these
     * columns are instants stored in the app timezone (config/app.php pins it
     * to UTC). So each boundary is converted before comparison — the same
     * conversion DonationMetrics applies to `created_at`, and the reason a bare
     * date literal would be wrong by the offset (dragging in, or dropping, the
     * evening hours either side of local midnight).
     *
     * Formatted explicitly rather than passed as a Carbon: the query grammar
     * formats a DateTimeInterface binding in ITS OWN timezone and performs no
     * conversion, so handing it a local instant would silently undo this.
     *
     * @param  array{from:?CarbonImmutable,to_exclusive:?CarbonImmutable}  $window
     */
    private function applyWindow(mixed $query, string $column, array $window): void
    {
        $appTimezone = (string) config('app.timezone', 'UTC');

        if ($window['from'] !== null) {
            $query->where($column, '>=', $window['from']->setTimezone($appTimezone)->format('Y-m-d H:i:s'));
        }

        if ($window['to_exclusive'] !== null) {
            $query->where($column, '<', $window['to_exclusive']->setTimezone($appTimezone)->format('Y-m-d H:i:s'));
        }
    }

    /** A yyyy-mm-dd (or ISO datetime) filter value as local midnight at the org. */
    private function localDay(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, $this->timezone)
            ->setTimezone($this->timezone)
            ->startOfDay();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }

    /**
     * The platform books money in a single configured currency (see
     * DonationsController::store), so one code labels the whole report.
     */
    private function currency(): string
    {
        return strtoupper((string) config('services.stripe.currency', 'usd'));
    }
}
