<?php

namespace App\Support;

use App\Enums\SectionType;
use App\Http\Controllers\AdminDashboard\OnboardingController;
use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Registration;
use App\Models\Section;
use App\Models\User;
use App\Services\Registrations\RegistrationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds (and removes) the Al-Razi Islamic School demo tenant.
 *
 * Driven ONLY by `php artisan demo:seed-school`. It is deliberately not a
 * `Database\Seeders\*` class: a class in that namespace is one `db:seed --class`
 * away from a production console, and one careless `$this->call(...)` away from
 * running inside `DatabaseSeeder`. Nothing here runs unless a human types the
 * command.
 *
 * ## Three properties this class is built around
 *
 * **1. The tenant is provisioned through the REAL path.** `seed()` builds a
 * genuine `ProvisionMasjidRequest`, validates it, and hands it to
 * `OnboardingController@provision` — the same call the Super-Admin wizard makes.
 * The demo tenant therefore gets its school feature bundle, its terminology
 * pack and its three seeded school forms from the code that gives every real
 * tenant those things, and cannot drift from them. Hand-inserting a `masjids`
 * row would have produced a tenant that looked right until the day provisioning
 * changed.
 *
 * **2. It is IDEMPOTENT, and it never overwrites.** Every object has a stable
 * natural key — an address for a person, a slug for a group/form/offering/page,
 * a label for a behaviour skill — and is created only when that key is free.
 * Nothing already in the database is ever UPDATED, so a demoer who renamed a
 * classroom, revoked a consent or deleted a post between runs keeps their
 * change. Rows with no natural key (feed posts, threads, awards, recitations)
 * are seeded PER GROUP and only when that group holds none of that kind, which
 * is the same guarantee at a coarser grain.
 *
 * **3. Removal is scoped by the tenant, not by a guess.** `rollback()` finds the
 * tenant by its marker address and deletes only rows underneath it — a set the
 * database itself defines through `masjid_id`. Everything that carries BYTES is
 * deleted THROUGH THE MODEL first (a DB cascade fires no model events and would
 * orphan every classroom photo on the private disk forever —
 * .claude/rules/private-uploads.md); the tenant row goes last, so the cascade
 * only has provisioning configuration left to sweep.
 */
class DemoSchoolSeeder
{
    /** @var callable(string):void|null */
    private $report;

    /** Temp files created for image uploads, removed once the run finishes. */
    private array $tempFiles = [];

    /**
     * @param  callable(string):void|null  $report  progress sink (the command's line writer)
     */
    public function __construct(?callable $report = null)
    {
        $this->report = $report;
    }

    /**
     * The demo tenant, if it exists — soft-deleted ones included, because a
     * soft-deleted row still owns the unique marker address and a re-seed has
     * to find it rather than collide with it.
     */
    public function tenant(): ?Masjid
    {
        return Masjid::withTrashed()
            ->where('email', DemoSchool::TENANT_EMAIL)
            ->first();
    }

    // ==================================================================
    // Seed
    // ==================================================================

    /**
     * Create everything that is missing and report what now exists.
     *
     * @param  array{city_id?:int|null,admin_password?:string|null}  $options
     * @return array<string,int|string>
     */
    public function seed(array $options = []): array
    {
        $created = [];

        try {
            $principal = $this->staffAccounts($options['admin_password'] ?? null, $created);

            $masjid = $this->tenant();

            if ($masjid && $masjid->trashed()) {
                // Somebody soft-deleted the demo tenant. Restoring is the only
                // correct move: the row still owns the unique marker address,
                // so a fresh provision would collide with it.
                $masjid->restore();
                $this->line('restored the soft-deleted demo tenant');
            }

            if (! $masjid) {
                $masjid = $this->provision($principal, $options['city_id'] ?? null);
                $created['tenant'] = 1;
            }

            // Bind the tenant for everything that follows. From here on the
            // BelongsToMasjid global scope filters every read and its creating
            // hook stamps every write, so no query in this class can reach — or
            // create — a row in somebody else's organization even by mistake.
            // See .claude/rules/tenant-scoping.md.
            $context = app(TenantContext::class);
            $context->set($masjid->id);

            try {
                $this->people($masjid, $created);
                $this->rosters($masjid, $created);
                $this->behaviorVocabulary($masjid, $created);
                $this->classStory($masjid, $created);
                $this->money($masjid, $created);
                $this->publicSite($masjid, $created);
            } finally {
                $context->forgetTenant();
            }

            return $this->inventory($masjid) + ['created' => $created];
        } finally {
            $this->cleanUpTempFiles();
        }
    }

    // ------------------------------------------------------------------
    // The tenant
    // ------------------------------------------------------------------

    /**
     * Provision through `OnboardingController@provision` — the wizard's own
     * endpoint, reached in-process rather than over HTTP.
     *
     * The request is a real `ProvisionMasjidRequest` put through
     * `validateResolved()`, so the demo payload is held to exactly the rules a
     * SuperAdmin's payload is. If it ever stops validating, this fails loudly
     * here instead of quietly producing a half-configured tenant.
     */
    private function provision(User $principal, ?int $cityId): Masjid
    {
        [$countryId, $resolvedCityId] = $this->resolveGeography($cityId);

        $payload = DemoSchool::provisionPayload() + [
            'country_id' => $countryId,
            'city_id' => $resolvedCityId,
            'user_id' => $principal->id,
        ];

        $request = ProvisionMasjidRequest::create(
            '/api/admin/onboarding/provision',
            'POST',
            $payload
        );
        $request->headers->set('Accept', 'application/json');
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        // The controller stamps `created_by` from the authenticated user. Set
        // it to the demo's own principal so the audit trail points at a demo
        // row rather than at nobody, and put the guard back afterwards — a
        // console command has no business leaving a user authenticated.
        Auth::setUser($principal);

        try {
            $response = app(OnboardingController::class)->provision($request);
        } finally {
            Auth::forgetUser();
        }

        $body = json_decode((string) $response->getContent(), true);

        if (($body['status'] ?? null) !== 'success' || empty($body['data']['masjid_id'])) {
            throw new RuntimeException(
                'Provisioning the demo tenant failed: ' . json_encode($body['data'] ?? $body)
            );
        }

        $masjid = Masjid::findOrFail((int) $body['data']['masjid_id']);

        // The CRM gate is a SuperAdmin toggle that defaults OFF, and every
        // group, behaviour and hifz screen sits behind it. Set once, at
        // creation — a later run never touches it, so a demoer who switched it
        // off to show the gate keeps that state.
        $masjid->update(['crm_enabled' => true]);

        $this->line("provisioned tenant #{$masjid->id} ({$masjid->orgType()})");

        return $masjid;
    }

    /**
     * Geography is REFERENCE DATA the demo borrows and never creates.
     *
     * `countries`/`cities` are shared by every tenant, so a demo fixture that
     * invented rows there would be leaving litter its own rollback could not
     * safely remove. Instead the seeder attaches to what is already there, and
     * says exactly what to run if the tables are empty.
     *
     * @return array{0:int,1:int} [country_id, city_id]
     */
    private function resolveGeography(?int $cityId): array
    {
        $city = $cityId
            ? DB::table('cities')->where('id', $cityId)->first()
            : DB::table('cities')->orderBy('id')->first();

        if (! $city) {
            throw new RuntimeException(
                $cityId
                    ? "City {$cityId} does not exist."
                    : 'No cities exist yet, and this seeder never creates shared reference data. '
                        . 'Run `php artisan db:seed --class=CountriesCitiesSeeder` first, or pass --city=<id>.'
            );
        }

        return [(int) $city->country_id, (int) $city->id];
    }

    // ------------------------------------------------------------------
    // People
    // ------------------------------------------------------------------

    /**
     * The staff `users` rows, created BEFORE the tenant because provisioning
     * wants an owner (`masjids.user_id`, unique) and validates it exists.
     *
     * Passwords are random and thrown away unless the operator supplies one for
     * the principal with `--admin-password=`. A demo fixture that shipped a
     * known password would be a credential in version control that works on
     * whatever database somebody points it at; choosing one is the operator's
     * deliberate act, not this file's default.
     */
    private function staffAccounts(?string $adminPassword, array &$created): User
    {
        $principal = null;
        $index = 1;

        foreach (DemoSchool::staff() as $key => $person) {
            $email = DemoSchool::email($person['first'], $person['last']);
            $isPrincipal = ! empty($person['principal']);

            $user = User::withTrashed()->where('email', $email)->first();

            if ($user && $user->trashed()) {
                $user->restore();
            }

            if (! $user) {
                $user = User::create([
                    'name' => $person['first'] . ' ' . $person['last'],
                    'email' => $email,
                    'phone' => DemoSchool::phone($index),
                    'type' => 'MasjidAdmin',
                    'password' => Hash::make(
                        $isPrincipal && $adminPassword ? $adminPassword : Str::random(48)
                    ),
                ]);

                $created['staff_users'] = ($created['staff_users'] ?? 0) + 1;
            }

            if ($isPrincipal) {
                $principal = $user;
            }

            $index++;
        }

        if (! $principal) {
            throw new RuntimeException('The demo blueprint has no principal staff member.');
        }

        return $principal;
    }

    /**
     * Contacts for staff, guardians and students.
     *
     * A staff Contact carries the SAME address as their User on purpose: that
     * match is how `App\Support\GroupAudience` decides which person a logged-in
     * caller is, so a teacher whose two rows disagreed could publish to their
     * classroom and not read it back.
     */
    private function people(Masjid $masjid, array &$created): void
    {
        $index = 1;

        foreach (DemoSchool::staff() as $person) {
            $this->contact(
                DemoSchool::email($person['first'], $person['last']),
                $person['first'],
                $person['last'],
                $index++,
                $person['title'] . ' — demo staff record.',
                $created
            );
        }

        foreach (DemoSchool::guardians() as $person) {
            $this->contact(
                DemoSchool::email($person['first'], $person['last']),
                $person['first'],
                $person['last'],
                $index++,
                'Parent / guardian — demo record.',
                $created
            );
        }

        foreach (DemoSchool::students() as $person) {
            $this->contact(
                DemoSchool::email($person['first'], $person['last'], true),
                $person['first'],
                $person['last'],
                $index++,
                'Student — demo record.',
                $created
            );
        }
    }

    /** Create a contact if its address is free; never update an existing one. */
    private function contact(
        string $email,
        string $first,
        string $last,
        int $phoneIndex,
        string $notes,
        array &$created
    ): Contact {
        $existing = Contact::withTrashed()->where('email', $email)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $created['contacts'] = ($created['contacts'] ?? 0) + 1;

        // No masjid_id: the tenant is bound, so BelongsToMasjid's creating hook
        // stamps it from the context and would override anything passed here
        // anyway. See .claude/rules/tenant-scoping.md.
        return Contact::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => DemoSchool::phone($phoneIndex),
            'notes' => $notes,
        ]);
    }

    // ------------------------------------------------------------------
    // Rosters
    // ------------------------------------------------------------------

    private function rosters(Masjid $masjid, array &$created): void
    {
        foreach (DemoSchool::classrooms() as $classroom) {
            $group = Group::withTrashed()->where('slug', $classroom['slug'])->first();

            if ($group && $group->trashed()) {
                $group->restore();
            }

            if (! $group) {
                $group = Group::create([
                    'masjid_id' => $masjid->id,
                    'name' => $classroom['name'],
                    'slug' => $classroom['slug'],
                    'kind' => $classroom['kind'],
                    'description' => $classroom['description'],
                    'is_active' => true,
                    'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
                ]);

                $created['groups'] = ($created['groups'] ?? 0) + 1;
            }

            // The teacher, as a leader membership over their staff Contact.
            $leader = DemoSchool::staff()[$classroom['leader']];
            $this->membership(
                $group,
                $this->contactByEmail(DemoSchool::email($leader['first'], $leader['last'])),
                GroupMembership::ROLE_LEADER,
                null,
                null,
                $created
            );

            foreach ($classroom['roster'] as $studentKey => $guardianConsents) {
                $student = DemoSchool::students()[$studentKey];
                $studentContact = $this->contactByEmail(
                    DemoSchool::email($student['first'], $student['last'], true)
                );

                // Participant FIRST: a guardian edge may only name a ward who
                // already holds a participant membership in this same group,
                // or it would grant access to a child nobody put there.
                $this->membership($group, $studentContact, GroupMembership::ROLE_MEMBER, null, null, $created);

                foreach ($guardianConsents as $guardianKey => $consentScope) {
                    $guardian = DemoSchool::guardians()[$guardianKey];

                    $this->membership(
                        $group,
                        $this->contactByEmail(DemoSchool::email($guardian['first'], $guardian['last'])),
                        GroupMembership::ROLE_GUARDIAN,
                        $studentContact->id,
                        $consentScope,
                        $created
                    );
                }
            }
        }
    }

    /**
     * One membership edge, created only when that exact edge is absent.
     *
     * Consent is written ONLY on creation. Re-running must never restore a
     * consent a demoer withdrew to show the gate working — "absence of a record
     * means no consent" is only true if the absent state is reachable and stays
     * reachable.
     */
    private function membership(
        Group $group,
        Contact $contact,
        string $role,
        ?int $wardContactId,
        ?string $consentScope,
        array &$created
    ): GroupMembership {
        $existing = GroupMembership::query()
            ->where('group_id', $group->id)
            ->where('contact_id', $contact->id)
            ->where('role', $role)
            ->where('guardian_of_contact_id', $wardContactId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $created['memberships'] = ($created['memberships'] ?? 0) + 1;

        return GroupMembership::create([
            'masjid_id' => $group->masjid_id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $wardContactId,
            'joined_at' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'consent_granted_at' => $consentScope ? now()->subWeeks(6) : null,
            'consent_scope' => $consentScope,
        ]);
    }

    // ------------------------------------------------------------------
    // Behaviour vocabulary
    // ------------------------------------------------------------------

    private function behaviorVocabulary(Masjid $masjid, array &$created): void
    {
        foreach (DemoSchool::behaviorSkills() as $skill) {
            $exists = BehaviorSkill::query()->where('label', $skill['label'])->exists();

            if ($exists) {
                continue;
            }

            BehaviorSkill::create([
                'masjid_id' => $masjid->id,
                'label' => $skill['label'],
                'polarity' => $skill['polarity'],
                'default_points' => $skill['points'],
                'is_active' => true,
            ]);

            $created['behavior_skills'] = ($created['behavior_skills'] ?? 0) + 1;
        }
    }

    // ------------------------------------------------------------------
    // The class story: posts, threads, awards, recitations
    // ------------------------------------------------------------------

    private function classStory(Masjid $masjid, array &$created): void
    {
        $users = $this->staffUsersByKey();
        $skills = BehaviorSkill::query()->get()->keyBy('label');
        $posts = DemoSchool::posts();
        $threads = DemoSchool::threads();
        $awards = DemoSchool::awards();
        $hifz = DemoSchool::hifzEntries();

        foreach (DemoSchool::classrooms() as $classroom) {
            $group = Group::query()->where('slug', $classroom['slug'])->first();

            if (! $group) {
                continue;
            }

            $leaderUser = $users[$classroom['leader']];

            $this->seedPosts($group, $posts[$classroom['slug']] ?? [], $leaderUser, $created);
            $this->seedThreads($group, $threads[$classroom['slug']] ?? [], $users, $created);
            $this->seedAwards($group, $awards[$classroom['slug']] ?? [], $skills, $leaderUser, $created);

            if ($classroom['kind'] === Group::KIND_HALAQA) {
                $this->seedHifz($group, $classroom['roster'], $hifz, $leaderUser, $created);
            }
        }
    }

    /**
     * Feed posts, one of which carries a photograph.
     *
     * The image goes through `App\Support\GroupPostAttachments` — the SAME code
     * the upload endpoint uses — so the bytes land on the private disk under a
     * randomised name in the tenant's own subtree, and the row that describes
     * them is what the authenticated, consent-gated download endpoint reads
     * back. Writing bytes into `storage/` by hand would produce a file the app
     * could not serve and the purge could not find.
     */
    private function seedPosts(Group $group, array $posts, User $author, array &$created): void
    {
        if ($posts === [] || GroupPost::withTrashed()->where('group_id', $group->id)->exists()) {
            return;
        }

        foreach ($posts as $blueprint) {
            $at = now()->subDays($blueprint['days_ago']);

            $post = GroupPost::create([
                'masjid_id' => $group->masjid_id,
                'group_id' => $group->id,
                'author_user_id' => $author->id,
                'title' => $blueprint['title'],
                'body' => $blueprint['body'],
            ]);

            $post->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

            $created['posts'] = ($created['posts'] ?? 0) + 1;

            if (! empty($blueprint['image'])) {
                GroupPostAttachments::store($post, [
                    $this->sampleUpload(Str::slug($blueprint['title']) . '.png'),
                ]);

                $created['post_images'] = ($created['post_images'] ?? 0) + 1;
            }
        }
    }

    private function seedThreads(Group $group, array $threads, array $users, array &$created): void
    {
        if ($threads === [] || GroupThread::withTrashed()->where('group_id', $group->id)->exists()) {
            return;
        }

        foreach ($threads as $blueprint) {
            $aboutMembershipId = null;

            if (! empty($blueprint['about'])) {
                $student = DemoSchool::students()[$blueprint['about']];
                $contact = $this->contactByEmail(
                    DemoSchool::email($student['first'], $student['last'], true)
                );

                $aboutMembershipId = GroupMembership::query()
                    ->where('group_id', $group->id)
                    ->where('contact_id', $contact->id)
                    ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
                    ->value('id');
            }

            $thread = GroupThread::create([
                'masjid_id' => $group->masjid_id,
                'group_id' => $group->id,
                'created_by_user_id' => $users[$blueprint['opened_by']]->id,
                'subject' => $blueprint['subject'],
                'scope' => $blueprint['scope'],
                'about_membership_id' => $aboutMembershipId,
            ]);

            $created['threads'] = ($created['threads'] ?? 0) + 1;

            $oldest = null;

            foreach ($blueprint['messages'] as $message) {
                $at = now()->subDays($message['days_ago']);
                $oldest = $oldest === null ? $at : $oldest;

                $row = GroupMessage::create([
                    'masjid_id' => $group->masjid_id,
                    'group_thread_id' => $thread->id,
                    'author_user_id' => $users[$message['author']]->id,
                    'body' => $message['body'],
                ]);

                $row->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

                $created['messages'] = ($created['messages'] ?? 0) + 1;
            }

            // GroupMessage touches its thread, which would date every demo
            // conversation to "just now". Put the thread back where its
            // messages say it belongs.
            $last = now()->subDays(min(array_column($blueprint['messages'], 'days_ago')));
            $thread->forceFill(['created_at' => $oldest, 'updated_at' => $last])->saveQuietly();
        }
    }

    /**
     * Behaviour awards.
     *
     * The label, polarity and point value are SNAPSHOTTED onto each award, as
     * the real endpoint does: re-weighting a skill next term must not restate
     * what a child was told in October.
     */
    private function seedAwards(Group $group, array $awards, $skills, User $awardedBy, array &$created): void
    {
        if ($awards === [] || BehaviorAward::withTrashed()->where('group_id', $group->id)->exists()) {
            return;
        }

        $vocabulary = DemoSchool::behaviorSkills();

        foreach ($awards as [$studentKey, $skillKey, $daysAgo, $note]) {
            $membership = $this->participantMembership($group, $studentKey);

            if (! $membership) {
                continue;
            }

            $definition = $vocabulary[$skillKey];
            $skill = $skills->get($definition['label']);

            BehaviorAward::create([
                'masjid_id' => $group->masjid_id,
                'group_id' => $group->id,
                'group_membership_id' => $membership->id,
                'behavior_skill_id' => $skill?->id,
                'awarded_by_user_id' => $awardedBy->id,
                'skill_label' => $definition['label'],
                'skill_polarity' => $definition['polarity'],
                'points' => $definition['points'],
                'note' => $note,
                'awarded_at' => now()->subDays($daysAgo)->setTime(14, 0),
            ]);

            $created['awards'] = ($created['awards'] ?? 0) + 1;
        }
    }

    private function seedHifz(Group $group, array $roster, array $hifz, User $heardBy, array &$created): void
    {
        if (HifzEntry::withTrashed()->where('group_id', $group->id)->exists()) {
            return;
        }

        foreach (array_keys($roster) as $studentKey) {
            $entries = $hifz[$studentKey] ?? [];
            $membership = $this->participantMembership($group, $studentKey);

            if (! $membership || $entries === []) {
                continue;
            }

            foreach ($entries as [$kind, $fromSurah, $fromAyah, $toSurah, $toAyah, $daysAgo, $quality]) {
                HifzEntry::create([
                    'masjid_id' => $group->masjid_id,
                    'group_id' => $group->id,
                    'group_membership_id' => $membership->id,
                    'heard_by_user_id' => $heardBy->id,
                    'kind' => $kind,
                    'from_surah' => $fromSurah,
                    'from_ayah' => $fromAyah,
                    'to_surah' => $toSurah,
                    'to_ayah' => $toAyah,
                    'quality' => $quality,
                    'major_mistakes' => $quality === HifzEntry::QUALITY_REPEAT ? 2 : 0,
                    'minor_mistakes' => in_array($quality, [HifzEntry::QUALITY_FAIR, HifzEntry::QUALITY_REPEAT], true) ? 3 : 0,
                    'recited_at' => now()->subDays($daysAgo)->setTime(9, 30),
                ]);

                $created['hifz_entries'] = ($created['hifz_entries'] ?? 0) + 1;
            }
        }
    }

    // ------------------------------------------------------------------
    // Money — no Stripe object is created anywhere in this method
    // ------------------------------------------------------------------

    /**
     * One FREE offering and one PAID one, registered through
     * `RegistrationService` — the same service the public registration endpoint
     * calls, so the seats, the snapshots and the guarded `registration_count`
     * are all produced by the real code.
     *
     * NOTHING HERE TALKS TO STRIPE. The free plan confirms synchronously
     * through the service's declared free-path carve-out. The paid plan's rows
     * are ordinary intake records holding a seat at `payment_status = awaiting`
     * with a locally minted idempotency key; one is then confirmed through
     * `confirm()`, which is the same seam a webhook would use. No Checkout
     * Session, Customer, Price or Subscription exists for any of it, and no
     * `stripe_*` column is written.
     */
    private function money(Masjid $masjid, array &$created): void
    {
        $service = app(RegistrationService::class);
        $definition = DemoSchool::offeringFormDefinition();

        foreach (DemoSchool::offerings() as $blueprint) {
            $form = $this->formBySlug($blueprint['form']['slug']);

            if (! $form) {
                $form = Form::create([
                    'masjid_id' => $masjid->id,
                    'slug' => $blueprint['form']['slug'],
                    'name' => $blueprint['form']['name'],
                    'description' => $blueprint['form']['description'],
                    'schema' => $definition['schema'],
                    'settings' => $definition['settings'],
                    'is_active' => true,
                ]);

                $created['forms'] = ($created['forms'] ?? 0) + 1;
            }

            $offering = Offering::withTrashed()->where('slug', $blueprint['slug'])->first();

            if ($offering && $offering->trashed()) {
                $offering->restore();
            }

            if (! $offering) {
                $offering = Offering::create([
                    'masjid_id' => $masjid->id,
                    'kind' => $blueprint['kind'],
                    'name' => $blueprint['name'],
                    'slug' => $blueprint['slug'],
                    'intake_form_id' => $form->id,
                    // No roster group: these are a parent evening and an
                    // after-school club, not a classroom. Pointing them at one
                    // would write memberships into a roster the demo already
                    // curates by hand.
                    'group_id' => null,
                    'capacity' => $blueprint['capacity'],
                    'opens_at' => now()->subDays(21),
                    'closes_at' => now()->addDays(45),
                    'is_active' => true,
                ]);

                $created['offerings'] = ($created['offerings'] ?? 0) + 1;
            }

            $plan = FeePlan::query()
                ->where('offering_id', $offering->id)
                ->where('label', $blueprint['plan']['label'])
                ->first();

            if (! $plan) {
                $plan = FeePlan::create([
                    'masjid_id' => $masjid->id,
                    'offering_id' => $offering->id,
                    'kind' => $blueprint['plan']['kind'],
                    // Integer minor units, always.
                    'amount_minor' => $blueprint['plan']['amount_minor'],
                    'currency' => 'cad',
                    'label' => $blueprint['plan']['label'],
                    'is_active' => true,
                ]);

                $created['fee_plans'] = ($created['fee_plans'] ?? 0) + 1;
            }

            if (Registration::query()->where('offering_id', $offering->id)->exists()) {
                continue;
            }

            foreach ($blueprint['registrations'] as $position => $payerKey) {
                $guardian = DemoSchool::guardians()[$payerKey];
                $payer = $this->contactByEmail(DemoSchool::email($guardian['first'], $guardian['last']));

                $wards = $this->wardsOf($payer);

                $registration = $service->register(
                    $offering,
                    $plan,
                    $payer,
                    [
                        'parentName' => $payer->first_name . ' ' . $payer->last_name,
                        'parentEmail' => $payer->email,
                        'parentPhone' => $payer->phone,
                        'students' => $wards === []
                            ? $payer->first_name . ' ' . $payer->last_name
                            : implode(', ', array_map(fn (Contact $c) => $c->first_name . ' ' . $c->last_name, $wards)),
                        'notes' => '',
                    ],
                    $wards
                );

                // The first paid seat is confirmed through the SAME seam a
                // webhook would use, so the demo shows a settled registration
                // and an outstanding one side by side. Neither has a Stripe
                // object behind it.
                if ($position === 0 && $registration->isPending()) {
                    $service->confirm($registration);
                }

                $created['registrations'] = ($created['registrations'] ?? 0) + 1;
            }
        }
    }

    /** The children this guardian holds an edge over, anywhere in the tenant. */
    private function wardsOf(Contact $guardian): array
    {
        $wardIds = GroupMembership::query()
            ->where('contact_id', $guardian->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->pluck('guardian_of_contact_id')
            ->filter()
            ->unique()
            ->all();

        return Contact::query()->whereIn('id', $wardIds)->get()->all();
    }

    // ------------------------------------------------------------------
    // Public site
    // ------------------------------------------------------------------

    private function publicSite(Masjid $masjid, array &$created): void
    {
        foreach (DemoSchool::pages() as $blueprint) {
            $existing = Page::withTrashed()
                ->where('masjid_id', $masjid->id)
                ->where('slug', $blueprint['slug'])
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                // The page is here, so its sections are the demoer's business
                // now. Re-adding them would duplicate a layout somebody may
                // have rearranged.
                continue;
            }

            $page = Page::create([
                'masjid_id' => $masjid->id,
                'slug' => $blueprint['slug'],
                'title' => $blueprint['title'],
                'page_title' => $blueprint['title'],
                'is_active' => true,
                'order' => $blueprint['order'],
                'show_in_menu' => true,
            ]);

            $created['pages'] = ($created['pages'] ?? 0) + 1;

            foreach ($blueprint['sections'] as $order => $sectionBlueprint) {
                $section = Section::create([
                    'masjid_id' => $masjid->id,
                    'section_type' => SectionType::from($sectionBlueprint['type']),
                    'title' => $sectionBlueprint['title'],
                    'content' => $sectionBlueprint['content'],
                    'is_active' => true,
                ]);

                $page->sections()->attach($section->id, ['order' => $order + 1]);

                $created['sections'] = ($created['sections'] ?? 0) + 1;
            }
        }
    }

    // ==================================================================
    // Rollback
    // ==================================================================

    /**
     * Remove the demo tenant and everything under it — and nothing else.
     *
     * The scope is the tenant row the MARKER address names. Every table this
     * seeder writes to except `users` carries `masjid_id`, so "what the demo
     * created" is a set the database defines rather than a list this method
     * has to keep in sync; the staff accounts are caught by the marker domain
     * instead, since `users` has no tenant column in this schema.
     *
     * ORDER IS LOAD-BEARING. Anything with bytes on disk goes THROUGH THE MODEL
     * first, because a DB cascade fires no model events and would leave every
     * classroom photograph on the private disk forever. The tenant row is
     * force-deleted last, at which point the cascade has only provisioning
     * configuration left to remove.
     *
     * @return array<string,int>
     */
    public function rollback(): array
    {
        $removed = [];
        $masjid = $this->tenant();

        if ($masjid) {
            // Belt and braces. The lookup already keyed on the marker, but this
            // method force-deletes a tenant root, so the invariant is asserted
            // rather than assumed.
            if ($masjid->email !== DemoSchool::TENANT_EMAIL) {
                throw new RuntimeException(
                    'Refusing to roll back: tenant #' . $masjid->id . ' does not carry the demo marker address.'
                );
            }

            $context = app(TenantContext::class);
            $context->set($masjid->id);

            try {
                $removed = $this->removeTenantContent();
            } finally {
                $context->forgetTenant();
            }

            $masjid->forceDelete();
            $removed['tenant'] = 1;
        }

        // Staff accounts last: `masjids.user_id` nulls on user delete, so the
        // tenant row has to be gone before the owner is.
        $users = User::withTrashed()
            ->where('email', 'like', '%' . DemoSchool::DOMAIN)
            ->get()
            ->filter(fn (User $user) => DemoSchool::isDemoEmail($user->email));

        foreach ($users as $user) {
            $user->forceDelete();
            $removed['staff_users'] = ($removed['staff_users'] ?? 0) + 1;
        }

        return $removed;
    }

    /**
     * Delete the tenant's rows, byte-bearing tables first.
     *
     * Runs with the tenant BOUND, so every query below is confined to it by the
     * global scope — a missing `where` here cannot reach another organization's
     * rows.
     */
    private function removeTenantContent(): array
    {
        $removed = [];

        // 1. Feed posts — purge() force-deletes THROUGH the model so each
        //    attachment's own deleting hook removes its bytes from the private
        //    disk. Soft-deleted posts included: a post somebody hid last week
        //    is exactly the one that must not linger.
        $removed['post_images'] = GroupPostAttachment::query()->count();

        GroupPost::withTrashed()->get()->each(function (GroupPost $post) use (&$removed) {
            $post->purge();
            $removed['posts'] = ($removed['posts'] ?? 0) + 1;
        });

        // 2. Threads (messages and read markers go with them by cascade — rows
        //    only, nothing on disk to orphan).
        $removed['messages'] = GroupMessage::query()->count();

        GroupThread::withTrashed()->get()->each(function (GroupThread $thread) use (&$removed) {
            $thread->purge();
            $removed['threads'] = ($removed['threads'] ?? 0) + 1;
        });

        // 3. Behaviour and hifz records about children.
        $removed['awards'] = BehaviorAward::withTrashed()->count();
        BehaviorAward::withTrashed()->get()->each->purge();

        $removed['hifz_entries'] = HifzEntry::withTrashed()->count();
        HifzEntry::withTrashed()->get()->each->forceDelete();

        $removed['behavior_skills'] = BehaviorSkill::query()->count();
        BehaviorSkill::query()->get()->each->delete();

        // 4. Money. Registrations before fee plans: `registrations.fee_plan_id`
        //    is a RESTRICT foreign key on purpose.
        $removed['registrations'] = Registration::query()->count();
        Registration::query()->get()->each->delete();

        FeePlan::query()->get()->each->delete();
        Offering::withTrashed()->get()->each->forceDelete();

        // Form responses through the model: their attachments live on the
        // private disk too. `form_responses` and `forms` carry a masjid_id but
        // NOT the BelongsToMasjid trait, so these two are filtered by hand —
        // the only queries in this method that are.
        $masjidId = $this->boundMasjidId();

        FormResponse::query()->where('masjid_id', $masjidId)->get()->each->delete();

        $removed['forms'] = Form::withTrashed()->where('masjid_id', $masjidId)->count();
        Form::withTrashed()->where('masjid_id', $masjidId)->get()->each->forceDelete();

        // 5. Rosters, then the people they referenced.
        $removed['memberships'] = GroupMembership::query()->count();
        GroupMembership::query()->get()->each->delete();

        $removed['groups'] = Group::withTrashed()->count();
        Group::withTrashed()->get()->each->forceDelete();

        $removed['contacts'] = Contact::withTrashed()->count();
        Contact::withTrashed()->get()->each->forceDelete();

        // 6. The public site. `pages` and `sections` carry a masjid_id without
        // the trait as well, so they are filtered by hand too.
        $removed['pages'] = Page::withTrashed()->where('masjid_id', $masjidId)->count();
        Page::withTrashed()->where('masjid_id', $masjidId)->get()->each(function (Page $page) {
            $page->sections()->detach();
            $page->forceDelete();
        });

        $removed['sections'] = Section::query()->where('masjid_id', $masjidId)->count();
        Section::query()->where('masjid_id', $masjidId)->get()->each->delete();

        return $removed;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /** What exists right now, for the command to print. */
    public function inventory(Masjid $masjid): array
    {
        return app(TenantContext::class)->runWithout(fn () => [
            'masjid_id' => $masjid->id,
            'org_type' => $masjid->orgType(),
            'contacts' => Contact::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'groups' => Group::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'memberships' => GroupMembership::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'guardian_edges' => GroupMembership::withoutMasjidScope()->where('masjid_id', $masjid->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)->count(),
            'consented_edges' => GroupMembership::withoutMasjidScope()->where('masjid_id', $masjid->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)->whereNotNull('consent_granted_at')->count(),
            'posts' => GroupPost::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'post_images' => GroupPostAttachment::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'threads' => GroupThread::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'messages' => GroupMessage::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'behavior_skills' => BehaviorSkill::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'awards' => BehaviorAward::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'hifz_entries' => HifzEntry::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'forms' => Form::where('masjid_id', $masjid->id)->count(),
            'offerings' => Offering::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'fee_plans' => FeePlan::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'registrations' => Registration::withoutMasjidScope()->where('masjid_id', $masjid->id)->count(),
            'pages' => Page::where('masjid_id', $masjid->id)->count(),
            'sections' => Section::where('masjid_id', $masjid->id)->count(),
            'staff_users' => User::where('email', 'like', '%' . DemoSchool::DOMAIN)->count(),
        ]);
    }

    private function boundMasjidId(): int
    {
        return (int) app(TenantContext::class)->get();
    }

    /** @return array<string,User> keyed by staff blueprint key */
    private function staffUsersByKey(): array
    {
        $users = [];

        foreach (DemoSchool::staff() as $key => $person) {
            $users[$key] = User::where('email', DemoSchool::email($person['first'], $person['last']))->firstOrFail();
        }

        return $users;
    }

    private function contactByEmail(string $email): Contact
    {
        return Contact::query()->where('email', $email)->firstOrFail();
    }

    private function formBySlug(string $slug): ?Form
    {
        $form = Form::withTrashed()
            ->where('masjid_id', $this->boundMasjidId())
            ->where('slug', $slug)
            ->first();

        if ($form && $form->trashed()) {
            $form->restore();
        }

        return $form;
    }

    private function participantMembership(Group $group, string $studentKey): ?GroupMembership
    {
        $student = DemoSchool::students()[$studentKey];

        $contact = Contact::query()
            ->where('email', DemoSchool::email($student['first'], $student['last'], true))
            ->first();

        if (! $contact) {
            return null;
        }

        return GroupMembership::query()
            ->where('group_id', $group->id)
            ->where('contact_id', $contact->id)
            ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
            ->first();
    }

    /**
     * The stand-in classroom photo as a real UploadedFile, so it travels the
     * same path as a browser upload: sniffed MIME type, randomised stored name,
     * tenant-scoped directory.
     */
    private function sampleUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'demo-school-');
        file_put_contents($path, DemoSchool::sampleImageBytes());

        $this->tempFiles[] = $path;

        // The final `true` marks it a test-mode file, which is what lets an
        // UploadedFile be constructed around a path PHP did not receive through
        // a multipart request. Nothing else about the handling differs.
        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function cleanUpTempFiles(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    private function line(string $message): void
    {
        if ($this->report) {
            ($this->report)($message);
        }
    }
}
