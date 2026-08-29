<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToMasjid;
use App\Models\MasjidUser;
use App\Models\StripeWebhookEvent;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * The META-TEST: it enforces .claude/rules/tenant-scoping.md mechanically.
 *
 * ## Why this file exists
 *
 * That rule already said, in writing, that **every model using
 * `BelongsToMasjid` must ship a cross-tenant Feature test**. The rule was true,
 * it was written down, and it was silently unmet for six models — and on
 * 2026-08-11 two cross-tenant holes were found LIVE in production, one of them
 * months old (`SearchableTrait::filterByMasjid()` failing OPEN with no
 * `masjid-id` header, and contact-us resolving a client-supplied `device_id`
 * with no tenant filter). A rule a human has to remember is documentation, not
 * a control. This file is the control.
 *
 * MySQL has no row-level security. The bound tenant IS the boundary. Nothing in
 * the database will catch a model that quietly loses its scope, so the suite has
 * to.
 *
 * ## What it enforces, in four layers
 *
 *  1. **Discovery** — the set of tenant-scoped models is DISCOVERED by
 *     reflection over `app/Models`, never listed here. Add a model tomorrow and
 *     it is in scope tomorrow, with no edit to this file. (There is deliberately
 *     no expected count asserted anywhere below: a count is a list in disguise
 *     and would have to be bumped by the same person who forgot the test.)
 *  2. **The scope is really wired** (`the_tenant_scope_is_wired_on_every_...`) —
 *     dynamic and airtight: for every discovered model the global scope is
 *     registered, the table really has `masjid_id`, a bound tenant really adds
 *     the predicate with the right binding, an unbound one really does not, and
 *     the `creating` hook is really listening. This proves the mechanism itself
 *     with no reliance on anyone having written anything.
 *  3. **Somebody wrote the cross-tenant test** (`every_tenant_scoped_model_...`)
 *     — a STATIC proxy over `tests/`, weaker than layer 2 on purpose and
 *     honestly described in `coversModel()` below. It guards the end-to-end and
 *     HTTP-layer questions layer 2 cannot reach (404 vs 403, who may read whose
 *     roster, whether an endpoint binds at all).
 *  4. **Nothing escapes quietly** — a model whose table carries `masjid_id` but
 *     which does NOT use the trait must appear either in `DECLINED` (a genuine
 *     exemption, with its reason) or in `HAND_SCOPED_LEGACY` (the frozen
 *     pre-CRM inventory). A NEW one fails, which is the case that matters: it is
 *     exactly the shape of the two holes above.
 *
 * Plus a fifth, smaller guard: `docblocks_do_not_claim_a_test_that_does_not_exist`.
 * `app/Models/ContactLoginCode.php` once cited `FamilyPortalTenantIsolationTest`
 * as its mandatory cross-tenant test. No such file was ever written, so the rule
 * read as satisfied to every reader of that model. A false claim of coverage is
 * worse than no claim.
 *
 * ## If this test fails
 *
 * Read the failure message. It names the model and tells you what to write.
 * **Do not add an exemption to make it pass** unless the model genuinely is not
 * tenant-scoped: `DECLINED` is for models that were considered and refused the
 * trait for a stated architectural reason, never for models nobody got to.
 */
final class TenantScopingCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ---------------------------------------------------------------------
     * EXEMPTIONS — models that carry tenant identity and deliberately do NOT
     * use `BelongsToMasjid`.
     * ---------------------------------------------------------------------
     *
     * Each entry carries its one-line reason and a FALSIFIABLE fact about the
     * table, so the exemption cannot rot into a lie: if `stripe_webhook_events`
     * ever gains a `masjid_id`, the stated reason stops being true and
     * `declined_models_are_still_genuinely_declined` fails rather than shrugging.
     *
     * Verified against the built schema on 2026-08-12, not copied forward.
     */
    private const DECLINED = [
        MasjidUser::class => [
            'reason' => 'The `masjid_user` membership pivot is the table the tenant is derived FROM. Scoping it would make "which masjids may this user act on?" answerable only from inside a masjid the user is already bound to, and its creating hook would stamp memberships into the wrong organisation. Isolation here is an authorization concern (TenantResolver + the API surface). See .claude/rules/tenant-scoping.md.',
            'has_masjid_id_column' => true,
        ],
        StripeWebhookEvent::class => [
            'reason' => 'The Stripe idempotency ledger. The webhook route is unauthenticated and inbound events span every masjid, so the table carries no masjid_id at all; the UNIQUE index on stripe_event_id is what makes at-least-once delivery safe. See StripeWebhookController.',
            'has_masjid_id_column' => false,
        ],
    ];

    /**
     * ---------------------------------------------------------------------
     * THE FROZEN LEGACY INVENTORY — pre-CRM models that carry `masjid_id` and
     * are hand-scoped by their controllers instead of by a global scope.
     * ---------------------------------------------------------------------
     *
     * `.claude/rules/tenant-scoping.md` says "Do NOT retrofit blindly": adding
     * `BelongsToMasjid` to one of these silently rewrites every existing query,
     * including live public endpoints, so it needs its own task and its own
     * tests. This roster is therefore NOT a clean bill of health and must not be
     * read as one — **no entry below is asserted to be correctly isolated**. Four
     * of them (Announcement, Page, Section, Service) are precisely where the
     * fail-open `SearchableTrait::filterByMasjid()` leak lived.
     *
     * Its only job is to keep the set from GROWING in silence. A new model with
     * a `masjid_id` column and no trait is a new hand-scoped surface, and a
     * human must consciously either give it the trait plus an isolation test, or
     * write a line here saying why not.
     *
     * Shrinking this list is the goal. Retrofitting one means: add the trait,
     * write `<Model>TenantIsolationTest`, delete the line here.
     */
    private const HAND_SCOPED_LEGACY = [
        \App\Models\Announcement::class => 'Pre-CRM public content. Hand-scoped by its controllers; the public /api/v1 read goes through SearchableTrait::filterByMasjid(), which is where the 2026-08-11 fail-open leak was found and fixed.',
        \App\Models\AppVersionSetting::class => 'Per-masjid mobile app version gate, read and written only through AppConfigController on a /masjids/{id} route.',
        \App\Models\AssistantFeatureRequest::class => 'Assistant feature-request log, written only by the assistant ToolRegistry which resolves the masjid itself.',
        \App\Models\ContactReason::class => 'Admin-managed picklist behind /masjids/{id}/contact-reasons and the public mobile mirror; hand-scoped in ContactReasonsController.',
        \App\Models\DonationLink::class => 'Per-masjid external giving link, hand-scoped by its controller and surfaced through SectionContentBinder.',
        \App\Models\Event::class => 'Pre-CRM public content, hand-scoped in EventsController.',
        \App\Models\Form::class => 'Forms slice predates the CRM trait; Form/FormResponse/FormResponseAttachment hand-filter by masjid_id throughout and share a retrofit task.',
        \App\Models\FormResponse::class => 'See Form: same pre-CRM slice, hand-scoped, retrofitted together or not at all.',
        \App\Models\FormResponseAttachment::class => 'See Form: reached only through its parent FormResponse, which is itself hand-scoped.',
        \App\Models\IqamaTimeSetting::class => 'Per-masjid prayer configuration, reached only via /masjids/{id} admin routes and the public prayer endpoints.',
        \App\Models\JumaaSetting::class => 'Per-masjid Jumaa configuration, same shape as IqamaTimeSetting.',
        \App\Models\MasjidAbout::class => 'One settings row per masjid, reached only through the masjid it hangs off.',
        \App\Models\MasjidAppPublishing::class => 'One app-publishing row per masjid, written by onboarding/provisioning which resolve the masjid themselves.',
        \App\Models\MasjidMobileAppFeature::class => 'Per-masjid mobile feature toggles, reached only via /masjids/{id} admin routes.',
        \App\Models\MasjidSocialMediaLink::class => 'Per-masjid social links, one settings row per masjid.',
        \App\Models\MobileAppUser::class => 'End-user device/app registration for the public mobile API, which never runs the tenant middleware and passes masjid_id in the URL.',
        \App\Models\Notification::class => 'Pre-CRM push notification log, written by SendMasjidNotificationJob which resolves its own masjid.',
        \App\Models\Page::class => 'Pre-CRM CMS page. Public read goes through SearchableTrait::filterByMasjid() — one of the four models the 2026-08-11 fail-open leak exposed.',
        \App\Models\Prayer::class => 'Generated prayer-time rows, produced per masjid by the calculation pipeline rather than by request code.',
        \App\Models\PrayerCalculationSetting::class => 'Per-masjid calculation settings, reached only via /masjids/{id} admin routes.',
        \App\Models\ProvisioningJob::class => 'Provisioning bookkeeping, created and read by the provisioning controllers which name the masjid explicitly.',
        \App\Models\Section::class => 'Pre-CRM CMS section. Public read goes through SearchableTrait::filterByMasjid() — one of the four models the 2026-08-11 fail-open leak exposed.',
        \App\Models\Service::class => 'Pre-CRM public content. Public read goes through SearchableTrait::filterByMasjid() — one of the four models the 2026-08-11 fail-open leak exposed.',
        \App\Models\SplashAnnouncement::class => 'Per-masjid splash/in-app message, hand-scoped in SplashAnnouncementsController and the OneSignal service.',
        \App\Models\ThemeSetting::class => 'One theme row per masjid, reached only through the masjid it hangs off.',
    ];

    /**
     * Test-class names referenced in `app/` docblocks that are KNOWN not to
     * exist, and are cited as history rather than as coverage.
     *
     * Only one entry, and it earns its place: `ContactLoginCode`'s docblock
     * records that it used to name this file as its mandatory cross-tenant test
     * when no such file had ever been written. The narrative has to survive, the
     * claim must not — so the name is allowed here and asserted STILL ABSENT, so
     * the list cannot quietly outlive its reason.
     */
    private const KNOWN_ABSENT_TEST_CLASSES = [
        'FamilyPortalTenantIsolationTest' => 'Cited in app/Models/ContactLoginCode.php only as the false claim it replaced; never written.',
    ];

    /**
     * Assertions that constitute a REFUSAL — the thing a cross-tenant test has
     * to actually do. See `coversModel()` for why the bar is set here.
     */
    private const REFUSAL_ASSERTIONS = [
        '/assertStatus\(\s*40[34]\s*\)/',      // forbidden / not found over HTTP
        '/->assertForbidden\(/',
        '/->assertNotFound\(/',
        '/assertNull\(/',                      // find() misses across the boundary
        '/assertCount\(\s*0\s*,/',             // zero rows visible
        '/assertJsonCount\(\s*0\s*,/',
        '/assertSame\(\s*0\s*,/',              // zero rows updated / deleted
        '/assertEquals\(\s*0\s*,/',
        '/assertDatabaseMissing\(/',
        '/assertEmpty\(/',
    ];

    /**
     * A file establishes TWO tenants if it builds a masjid at two or more call
     * sites. The `(?<!function )` guard keeps a helper's own DECLARATION from
     * counting as one of them.
     */
    private const TENANT_FIXTURE_CALL = '/(?<!function )\b(?:Masjid::(?:create|factory)|makeMasjid\w*|createMasjid\w*|makeOrg\w*|makeTenant\w*|seedMasjid\w*)\s*\(/';

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — per tests/CLAUDE.md.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();
    }

    // =====================================================================
    // Layer 2 — the mechanism itself, proven dynamically.
    // =====================================================================

    #[Test]
    public function the_tenant_scope_is_wired_on_every_model_that_claims_it(): void
    {
        $tenant = app(TenantContext::class);
        $failures = [];

        foreach ($this->tenantScopedModels() as $class) {
            /** @var Model $model */
            $model = new $class();
            $table = $model->getTable();
            $short = class_basename($class);

            if (! array_key_exists($class::MASJID_TENANT_SCOPE, $model->getGlobalScopes())) {
                $failures[] = "{$short}: uses BelongsToMasjid but has no '"
                    . $class::MASJID_TENANT_SCOPE . "' global scope registered. "
                    . 'Something removed it (an overridden boot() that forgot parent::boot()?).';

                continue;
            }

            if (! Schema::hasColumn($table, 'masjid_id')) {
                $failures[] = "{$short}: uses BelongsToMasjid but `{$table}` has no `masjid_id` column, "
                    . 'so every query it scopes is a SQL error waiting for a bound tenant.';

                continue;
            }

            $tenant->forgetTenant();
            $unboundSql = $class::query()->toSql();

            $tenant->set(4242);
            $boundQuery = $class::query();
            $boundSql = $boundQuery->toSql();
            $boundBindings = $boundQuery->getBindings();
            $tenant->forgetTenant();

            if ($boundSql === $unboundSql) {
                $failures[] = "{$short}: binding a tenant did not change the query at all. "
                    . 'The global scope is registered but adds no predicate — this is exactly how '
                    . 'SearchableTrait::filterByMasjid() failed OPEN in production on 2026-08-11.';

                continue;
            }

            if (! str_contains($boundSql, 'masjid_id')) {
                $failures[] = "{$short}: a bound tenant produced `{$boundSql}`, which never mentions "
                    . 'masjid_id. The scope is filtering on something else.';

                continue;
            }

            if (! in_array(4242, array_map('intval', $boundBindings), true)) {
                $failures[] = "{$short}: the bound query does not bind the tenant id. "
                    . 'The predicate exists but is not the tenant.';

                continue;
            }

            if (! $model->getEventDispatcher()?->hasListeners("eloquent.creating: {$class}")) {
                $failures[] = "{$short}: no `creating` listener is registered, so masjid_id is NOT "
                    . 'server-stamped and a client-supplied masjid_id would survive into the row.';
            }
        }

        $this->assertSame(
            [],
            $failures,
            "The tenant global scope is not doing its job on these models:\n  - "
            . implode("\n  - ", $failures)
        );
    }

    // =====================================================================
    // Layer 3 — somebody wrote the cross-tenant test the rule demands.
    // =====================================================================

    #[Test]
    public function every_tenant_scoped_model_has_a_cross_tenant_isolation_test(): void
    {
        $sources = $this->testSources();
        $uncovered = [];

        foreach ($this->tenantScopedModels() as $class) {
            if ($this->coversModel($class, $sources) === null) {
                $uncovered[] = $class;
            }
        }

        $this->assertSame([], $uncovered, $this->missingCoverageMessage($uncovered));
    }

    // =====================================================================
    // Layer 4 — nothing carrying masjid_id escapes without a decision.
    // =====================================================================

    #[Test]
    public function declined_models_are_still_genuinely_declined(): void
    {
        $problems = [];

        foreach (self::DECLINED as $class => $entry) {
            $short = class_basename($class);

            if (! class_exists($class)) {
                $problems[] = "{$short}: exempted here but the class no longer exists. Delete the exemption.";

                continue;
            }

            if (in_array(BelongsToMasjid::class, class_uses_recursive($class), true)) {
                $problems[] = "{$short}: exempted here but it NOW USES BelongsToMasjid. "
                    . 'Delete the exemption and write the cross-tenant test the rule requires '
                    . "(tests/Feature/{$short}TenantIsolationTest.php).";

                continue;
            }

            $actual = Schema::hasColumn((new $class())->getTable(), 'masjid_id');

            if ($actual !== $entry['has_masjid_id_column']) {
                $problems[] = "{$short}: the exemption says its table "
                    . ($entry['has_masjid_id_column'] ? 'HAS' : 'has NO')
                    . ' masjid_id column, but the schema says the opposite. '
                    . 'The stated reason is no longer true — re-decide, do not re-word.';
            }

            $this->assertNotEmpty(
                trim($entry['reason']),
                "{$short}: every exemption must carry a reason IN THIS FILE."
            );
        }

        $this->assertSame([], $problems, "Stale exemptions:\n  - " . implode("\n  - ", $problems));
    }

    #[Test]
    public function no_model_carrying_masjid_id_escapes_the_trait_undocumented(): void
    {
        $undocumented = [];

        foreach ($this->eloquentModels() as $class) {
            if (in_array(BelongsToMasjid::class, class_uses_recursive($class), true)) {
                continue;
            }

            $table = (new $class())->getTable();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'masjid_id')) {
                continue;
            }

            if (array_key_exists($class, self::DECLINED) || array_key_exists($class, self::HAND_SCOPED_LEGACY)) {
                continue;
            }

            $undocumented[] = $class;
        }

        $this->assertSame([], $undocumented, $this->undocumentedTenantTableMessage($undocumented));
    }

    #[Test]
    public function the_legacy_roster_does_not_outlive_the_models_on_it(): void
    {
        $stale = [];

        foreach (self::HAND_SCOPED_LEGACY as $class => $reason) {
            $short = class_basename($class);

            if (! class_exists($class)) {
                $stale[] = "{$short}: listed as hand-scoped legacy but the class is gone. Delete the line.";

                continue;
            }

            if (in_array(BelongsToMasjid::class, class_uses_recursive($class), true)) {
                $stale[] = "{$short}: listed as hand-scoped legacy but it NOW USES BelongsToMasjid — "
                    . 'the retrofit happened. Delete the line here and make sure '
                    . "tests/Feature/{$short}TenantIsolationTest.php exists.";

                continue;
            }

            if (! Schema::hasColumn((new $class())->getTable(), 'masjid_id')) {
                $stale[] = "{$short}: listed as hand-scoped legacy but its table has no masjid_id. "
                    . 'It is not a tenant table; delete the line.';

                continue;
            }

            $this->assertNotEmpty(trim($reason), "{$short}: every legacy entry must carry a one-line reason.");
        }

        $this->assertSame([], $stale, "The legacy roster is stale:\n  - " . implode("\n  - ", $stale));
    }

    // =====================================================================
    // Layer 5 — a docblock may not claim a test that does not exist.
    // =====================================================================

    #[Test]
    public function docblocks_do_not_claim_a_test_that_does_not_exist(): void
    {
        $existing = $this->testClassNames();
        $liars = [];

        foreach ($this->appSources() as $path => $source) {
            $rel = 'app/' . ltrim(str_replace(app_path(), '', $path), DIRECTORY_SEPARATOR);

            // (a) explicit path claims: "tests/Feature/FooTest.php"
            preg_match_all('#\btests/[A-Za-z0-9_/]+\.php\b#', $source, $paths);

            foreach (array_unique($paths[0]) as $claim) {
                if (! file_exists(base_path($claim))) {
                    $liars[] = "{$rel} cites `{$claim}`, which does not exist.";
                }
            }

            // (b) bare class-name claims: "FooTest"
            preg_match_all('/\b([A-Z][A-Za-z0-9_]*Test)\b/', $source, $names);

            foreach (array_unique($names[1]) as $claim) {
                if (isset($existing[$claim])) {
                    continue;
                }

                if (array_key_exists($claim, self::KNOWN_ABSENT_TEST_CLASSES)) {
                    continue;
                }

                $liars[] = "{$rel} cites `{$claim}`, and no such test class exists under tests/.";
            }
        }

        $this->assertSame(
            [],
            $liars,
            "A docblock claims coverage that does not exist. A false claim of coverage is worse than\n"
            . "none — it makes the mandatory cross-tenant test read as done. Either write the test or\n"
            . "correct the docblock (and if the name is deliberate history, add it to\n"
            . "KNOWN_ABSENT_TEST_CLASSES with its reason).\n\n  - "
            . implode("\n  - ", $liars)
        );
    }

    #[Test]
    public function the_known_absent_test_list_does_not_outlive_its_reason(): void
    {
        $existing = $this->testClassNames();

        foreach (self::KNOWN_ABSENT_TEST_CLASSES as $name => $reason) {
            $this->assertArrayNotHasKey(
                $name,
                $existing,
                "`{$name}` is listed in KNOWN_ABSENT_TEST_CLASSES ({$reason}) but the file now exists. "
                . 'Remove the entry so the list keeps meaning what it says.'
            );
        }
    }

    // =====================================================================
    // Discovery — reflection over app/Models. Never a hardcoded list.
    // =====================================================================

    /**
     * Every concrete Eloquent model under `app/Models`, by reflection.
     *
     * Filename -> FQCN via the PSR-4 root, then filtered to real, concrete
     * subclasses of Model. Nothing here knows any model's name, so a model added
     * tomorrow is in scope tomorrow.
     *
     * @return list<class-string<Model>>
     */
    private function eloquentModels(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $root = app_path('Models');
        $classes = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([$root . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Models\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $cache = $classes;
    }

    /**
     * The models the rule is ABOUT: those actually using `BelongsToMasjid`.
     *
     * `class_uses_recursive()` rather than a source-text grep on purpose — a
     * model that merely NAMES the trait in a docblock explaining why it declined
     * it (MasjidUser, StripeWebhookEvent both do) must not be swept in, and a
     * model that picks the trait up through an intermediate trait or a parent
     * class must not be missed.
     *
     * @return list<class-string<Model>>
     */
    private function tenantScopedModels(): array
    {
        return array_values(array_filter(
            $this->eloquentModels(),
            fn (string $class): bool => in_array(BelongsToMasjid::class, class_uses_recursive($class), true)
        ));
    }

    // =====================================================================
    // The coverage signal, and what it is worth.
    // =====================================================================

    /**
     * Does any test genuinely exercise cross-tenant refusal for `$class`?
     *
     * ## The signal
     *
     * A model is covered when SOME file under `tests/` satisfies both:
     *
     *   (1) **it builds two tenants** — a masjid is constructed at two or more
     *       call sites in the file. One masjid cannot demonstrate a boundary,
     *       and "0 rows" against a single tenant is indistinguishable from an
     *       empty table.
     *   (2) **one single test METHOD both names the model and asserts a
     *       refusal** — 403/404, a null `find()`, a zero-row count, a zero
     *       affected-row count, `assertDatabaseMissing`. Method-level, not
     *       file-level: a model merely mentioned in a `use` statement at the top
     *       of a suite that refuses something ELSE is not coverage, and
     *       file-level matching passes every one of those.
     *
     * ## Why THIS signal
     *
     * Filename conventions (`<Model>TenantIsolationTest`) were the obvious
     * alternative and are worthless here: the six models that went uncovered
     * would have been caught by nothing, and the convention is unevenly followed
     * anyway — GroupThreadRead is covered inside GroupMessagingTenantIsolationTest,
     * RentPayment inside PropertyTenantIsolationTest. Requiring a file per model
     * would force fake files. Requiring a REFUSAL is the smallest thing that
     * cannot be satisfied by a test which only proves the happy path, which is
     * the actual failure mode: a suite that seeds one masjid, reads its own rows
     * back, and never asks the boundary question.
     *
     * ## Its weakness — stated plainly, because pretending otherwise is the bug
     *
     *   - **It is lexical.** A test method that names `Donation` and, for an
     *     unrelated reason, asserts `assertNull($donation->receipt)` counts. The
     *     signal proves a refusal was asserted NEAR the model, not that the
     *     refusal was ABOUT the tenant boundary.
     *   - **Two call sites is a proxy for two tenants**, not proof of it. A file
     *     with a masjid helper called once plus one inline `Masjid::create()`
     *     clears the bar with a single tenant.
     *   - **It cannot see semantics.** A test asserting the wrong masjid's row is
     *     invisible for the wrong reason (a typo'd id, a stale fixture) passes.
     *   - **It cannot fail a test that is skipped, or one that asserts nothing
     *       because its fixture silently produced no rows.**
     *
     * ## One deliberate bias: `assertFalse` and `assertSame(<literal array>)`
     * are NOT refusals here
     *
     * Both can express a refusal, and both are excluded, because both are
     * ordinary everywhere: `assertFalse` is among the most common assertions in
     * this suite, and admitting it would let almost any test count as isolation
     * coverage. The bias is toward FALSE POSITIVES — a real test can read as
     * uncovered — and that bias paid immediately. `SmsSuppression` proved its
     * isolation only as `assertSame(['+1613…'], …->pluck(…))` and an
     * `assertFalse`, so this check reported it uncovered; the investigation
     * found the suite had never asserted that masjid A cannot UPDATE or DELETE
     * masjid B's opt-out, which for that table is the verb that matters. The
     * fix was the missing assertion, not a looser pattern here. If this check
     * ever flags a model you believe is covered, look for the same thing before
     * touching REFUSAL_ASSERTIONS.
     *
     * So this layer is a floor, not a ceiling: it makes "nobody wrote one" a
     * BUILD FAILURE, which is the exact failure that let two holes ship. The
     * airtight half of the job is
     * `the_tenant_scope_is_wired_on_every_model_that_claims_it()` above, which
     * proves the mechanism dynamically and is not fooled by any of the above.
     *
     * @param  array<string,string>  $sources
     * @return array{0:string,1:string}|null  [relative path, method name]
     */
    private function coversModel(string $class, array $sources): ?array
    {
        $short = class_basename($class);
        $named = '/\b' . preg_quote($short, '/') . '\b/';

        foreach ($sources as $path => $source) {
            if (preg_match_all(self::TENANT_FIXTURE_CALL, $source) < 2) {
                continue;
            }

            foreach ($this->testMethodBodies($source) as $method => $body) {
                if (! preg_match($named, $body)) {
                    continue;
                }

                foreach (self::REFUSAL_ASSERTIONS as $refusal) {
                    if (preg_match($refusal, $body)) {
                        return [$path, $method];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Split a test file into `method name => body`, by brace matching.
     *
     * Regex cannot balance braces, and a naive "up to the next `}`" split would
     * end every method at its first closure — which is where the interesting
     * assertions live.
     *
     * @return array<string,string>
     */
    private function testMethodBodies(string $source): array
    {
        $bodies = [];

        preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{;]*\{/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => [$signature, $offset]) {
            $open = $offset + strlen($signature) - 1;
            $depth = 0;
            $end = null;

            for ($j = $open, $len = strlen($source); $j < $len; $j++) {
                if ($source[$j] === '{') {
                    $depth++;
                } elseif ($source[$j] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $j;

                        break;
                    }
                }
            }

            $bodies[$matches[1][$i][0]] = substr($source, $open, ($end ?? strlen($source)) - $open + 1);
        }

        return $bodies;
    }

    // =====================================================================
    // Source readers
    // =====================================================================

    /**
     * Every PHP file under `tests/`, EXCEPT this one.
     *
     * Excluding self is load-bearing: this file names every model in its own
     * rosters and asserts plenty of zero-valued things, so without the exclusion
     * the meta-test would cheerfully accept ITSELF as the cross-tenant coverage
     * for all 34 models and always pass.
     *
     * @return array<string,string>  relative path => source
     */
    private function testSources(): array
    {
        $sources = [];
        $self = realpath(__FILE__);

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'))) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            if (realpath($file->getPathname()) === $self) {
                continue;
            }

            $relative = 'tests/' . ltrim(
                str_replace(base_path('tests'), '', $file->getPathname()),
                DIRECTORY_SEPARATOR
            );

            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }

    /** @return array<string,string> absolute path => source */
    private function appSources(): array
    {
        $sources = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }

    /** @return array<string,string> test class basename => relative path */
    private function testClassNames(): array
    {
        $names = [];

        foreach (array_keys($this->testSources()) as $relative) {
            $names[basename($relative, '.php')] = $relative;
        }

        // testSources() excludes this file; put it back so a docblock citing the
        // meta-test itself is not reported as a lie.
        $names[basename(__FILE__, '.php')] = 'tests/Feature/' . basename(__FILE__);

        return $names;
    }

    // =====================================================================
    // Failure messages — say what to WRITE, not just what is wrong.
    // =====================================================================

    /** @param  list<class-string>  $uncovered */
    private function missingCoverageMessage(array $uncovered): string
    {
        if ($uncovered === []) {
            return '';
        }

        $lines = [
            'These models use BelongsToMasjid but NOTHING under tests/ proves a cross-tenant refusal',
            'for them. MySQL has no row-level security: the bound tenant is the only boundary, so an',
            'untested scope is an unproven one. Two such holes were found live in production on',
            '2026-08-11 (see .claude/rules/tenant-scoping.md).',
            '',
            'Write ONE test method per model below. It must (a) seed two masjids and (b) assert a',
            'refusal — a 403/404 over HTTP, a null find(), a zero row count, or zero affected rows.',
            'Mirror tests/Feature/TenantIsolationTest.php (model layer) and, if the model has an HTTP',
            'surface, tests/Feature/GroupCrudTest.php (404 for another tenant id under your own route,',
            '403 for another tenant in the route).',
            '',
        ];

        foreach ($uncovered as $class) {
            $short = class_basename($class);

            $lines[] = "  {$short}  ->  tests/Feature/{$short}TenantIsolationTest.php";
            $lines[] = "      #[Test] public function a_bound_tenant_cannot_read_another_organizations_"
                . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '(): void';
            $lines[] = "      // seed masjid A and masjid B, create a {$short} in B,";
            $lines[] = '      // bind A, then: assertNull(' . $short . '::find($bId));';
            $lines[] = '      //              assertSame(0, ' . $short . "::where('id', \$bId)->update([...]));";
            $lines[] = '      //              assertSame(0, ' . $short . "::where('id', \$bId)->delete());";
            $lines[] = '      // and assert create() stamps the BOUND tenant over a client-supplied masjid_id.';
            $lines[] = '';
        }

        $lines[] = 'Adding the model to DECLINED is NOT the fix unless it genuinely should not be';
        $lines[] = 'tenant-scoped. Exemptions are for models that were considered and refused the trait,';
        $lines[] = 'never for models nobody got to.';

        return implode("\n", $lines);
    }

    /** @param  list<class-string>  $undocumented */
    private function undocumentedTenantTableMessage(array $undocumented): string
    {
        if ($undocumented === []) {
            return '';
        }

        $lines = [
            'These models carry a `masjid_id` column but do NOT use BelongsToMasjid, and no decision',
            'about them is recorded anywhere. That is the shape of both holes found live on 2026-08-11:',
            'a tenant table whose isolation depends on every caller remembering to filter it.',
            '',
            'Pick one, deliberately:',
            '',
            '  (a) PREFERRED — add `use BelongsToMasjid;` to the model and write',
            '      tests/Feature/<Model>TenantIsolationTest.php. Read the "Do NOT retrofit blindly"',
            '      section of .claude/rules/tenant-scoping.md first: a global scope rewrites every',
            '      existing query on that model, including live public endpoints.',
            '',
            '  (b) It is genuinely not tenant-scoped (like MasjidUser, the table the tenant is derived',
            '      FROM) -> add it to self::DECLINED with its architectural reason and the truth about',
            '      its masjid_id column.',
            '',
            '  (c) It is pre-CRM and hand-scoped by its controllers today -> add it to',
            '      self::HAND_SCOPED_LEGACY with a one-line reason. This records the debt; it does not',
            '      discharge it.',
            '',
            'Undocumented:',
            '',
        ];

        foreach ($undocumented as $class) {
            $lines[] = '  ' . $class;
        }

        return implode("\n", $lines);
    }
}
