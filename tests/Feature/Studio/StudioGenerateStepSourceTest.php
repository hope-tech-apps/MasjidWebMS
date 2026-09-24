<?php

namespace Tests\Feature\Studio;

use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * Step 3, Generate, read from its source (docs/manara-studio-w1.md S8).
 *
 * A Feature test cannot render Vue, so, like StudioSpaSourceTest, this pins
 * what the files say. The rules themselves (the gate, the body, how an answer
 * is read, when an invitation is "sent") are unit-tested under node in
 * resources/vue-app/tests/studio-provision.test.ts; these check that the
 * screen is wired to them and to nothing else:
 *
 *  - Generate opens in the stepper and draws StepGenerate;
 *  - the Provision button is disabled by the gate and while the call runs;
 *  - the store credentials are the step's own memory and leave only in the
 *    provision body, never through the draft store's state (R7);
 *  - results are shown only for a confirmed 201; a 201 without
 *    `capabilities_applied` is an error (catalogue risk [2]);
 *  - the invitation is ticked only through inviteOutcome;
 *  - "Open live site" is S7's panel's alone (R24);
 *  - a 409 reloads the draft and the read-only step offers no Provision;
 *  - a created organisation disarms the autosave for good;
 *  - the drafts list says Live, linked to the organisation.
 *
 * Comments are stripped before a file is checked.
 */
class StudioGenerateStepSourceTest extends TestCase
{
    use ReadsStudioSource;

    private const STEP = 'components/super/studio/steps/StepGenerate.vue';

    private const RESULTS = 'components/super/studio/generate/ProvisionResults.vue';

    private const FIELDS = 'components/super/studio/generate/ByoCredentialsFields.vue';

    private const STORE = 'stores/super/studioDraftStore.ts';

    #[Test]
    public function generate_opens_in_the_stepper_and_draws_its_step(): void
    {
        $steps = $this->spaCode('core/studio/steps.ts');
        $this->assertStringNotContainsString('GENERATE_AVAILABLE', $steps, 'Generate is no longer held shut');
        $this->assertMatchesRegularExpression("/key: 'generate', title: 'Generate', headingId: 'studio-generate-title'/", $steps);

        $view = $this->spaCode('views/dashboard/super/studio/StudioView.vue');
        $this->assertStringNotContainsString('GENERATE_AVAILABLE', $view);
        $this->assertStringContainsString('<StepGenerate v-else-if="store.currentStep === \'generate\'" />', $view);

        $this->assertMatchesRegularExpression('/<h5 id="studio-generate-title"[^>]*tabindex="-1"/', $this->spaCode(self::STEP));
    }

    #[Test]
    public function the_provision_button_is_disabled_by_the_gate_and_while_the_call_runs(): void
    {
        $step = $this->spaCode(self::STEP);

        $this->assertMatchesRegularExpression('/<button[^>]*:disabled="!canProvision"[^>]*@click="provision"/s', $step);
        $this->assertMatchesRegularExpression('/const canProvision = computed\(\(\) => !store\.readOnly && !store\.provisioning && blockers\.value\.length === 0\);/', $step);
        $this->assertMatchesRegularExpression('/generateBlockers\(store\.answers, !!store\.draft\?\.logo, secrets\)/', $step);
        $this->assertMatchesRegularExpression('/async function provision\(\) \{\s*if \(!canProvision\.value\) return;/', $step);

        // The store refuses a second call while one runs, whatever the button does.
        $store = $this->spaCode(self::STORE);
        $this->assertMatchesRegularExpression('/if \(!current \|\| readOnly\.value \|\| provisioning\.value\) return null;\s*const gen = generation;\s*provisioning\.value = true;/', $store);
    }

    #[Test]
    public function the_store_credentials_are_the_steps_own_memory_and_leave_only_in_the_provision_body(): void
    {
        $step = $this->spaCode(self::STEP);
        $this->assertStringContainsString('const secrets = reactive(emptySecrets());', $step);
        $this->assertStringContainsString('await store.provision(secrets)', $step);

        $store = $this->spaCode(self::STORE);
        $this->assertSame(1, substr_count($store, '/provision`'), 'the store should post to the provision route in exactly one place');
        $this->assertMatchesRegularExpression('/ApiService\.post\(`\/api\/admin\/studio\/drafts\/\$\{current\.id\}\/provision`, provisionBody\(answers, secrets\)\)/', $store);
        $this->assertDoesNotMatchRegularExpression('/(ref|reactive|shallowRef)(<[^>]*>)?\([^)]*emptySecrets/', $store, 'the draft store must hold no credentials');
        foreach (explode("\n", $store) as $line) {
            if (preg_match('/\bsecrets\b/', $line) && ! str_contains($line, 'provisionBody(answers, secrets)')
                && ! str_contains($line, 'async function provision(secrets: ProvisionSecrets)')) {
                $this->fail("the store names the credentials outside provision()'s signature and provisionBody: {$line}");
            }
        }

        // The fields own no copy and talk to nothing but their parent.
        $fields = $this->spaCode(self::FIELDS);
        foreach (['useStudioDraftStore', 'ApiService', 'localStorage', 'sessionStorage', 'v-model'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $fields, "ByoCredentialsFields must not use {$forbidden}");
        }
    }

    #[Test]
    public function results_are_shown_only_for_a_confirmed_201(): void
    {
        $step = $this->spaCode(self::STEP);

        $this->assertMatchesRegularExpression('/v-if="outcome\?\.kind === \'created\'"[^>]*>\s*<ProvisionResults :result="outcome\.result" \/>/', $step);
        $this->assertSame(1, substr_count($step, '<ProvisionResults'), 'the results are drawn in one place');
        $this->assertMatchesRegularExpression('/v-else-if="outcome\?\.kind === \'unconfirmed\'" class="alert alert-danger/', $step);
    }

    #[Test]
    public function the_invitation_is_ticked_only_through_invite_outcome(): void
    {
        $results = $this->spaCode(self::RESULTS);

        $this->assertStringContainsString('const invite = computed(() => inviteOutcome(props.result.after_commit, store.answers));', $results);
        $this->assertMatchesRegularExpression('/<i v-if="invite\.sent" class="bi bi-check-circle-fill/', $results);
        $this->assertStringNotContainsString('invites_sent', $results, 'the count is read by inviteOutcome, not here');
    }

    #[Test]
    public function open_live_site_is_the_domain_panels_alone(): void
    {
        foreach ([self::STEP, self::RESULTS] as $file) {
            $code = $this->spaCode($file);
            $this->assertStringNotContainsString('live_url', $code, "{$file} must leave the live link to StudioDomainAttachPanel (R24)");
            $this->assertStringNotContainsString('<a ', $code, "{$file} links only through router-link and the panel");
        }

        $this->assertStringContainsString('<StudioDomainAttachPanel v-if="hasWebAddress" :masjid-id="result.masjid_id" />', $this->spaCode(self::RESULTS));
        $this->assertMatchesRegularExpression('/<a v-if="domain\.live_url"/', $this->spaCode('components/super/studio/StudioDomainAttachPanel.vue'));
    }

    #[Test]
    public function a_conflict_reloads_the_draft_and_the_read_only_step_offers_no_provision(): void
    {
        $store = $this->spaCode(self::STORE);
        $this->assertMatchesRegularExpression("/if \(outcome\.kind === 'conflict'\) \{\s*await load\(current\.id\);/", $store);

        $step = $this->spaCode(self::STEP);
        $readOnly = $this->between($step, 'v-else-if="store.readOnly"', '<template v-else>');
        $this->assertStringNotContainsString('<button', $readOnly, 'the read-only step must offer no way to provision again');
        $this->assertStringNotContainsString('@click', $readOnly, 'the read-only step must offer no way to provision again');
        $this->assertSame(1, substr_count($step, '@click="provision"'));
        $this->assertGreaterThan(strpos($step, '<template v-else>'), strpos($step, '@click="provision"'), 'Provision is only in the editable branch');
    }

    #[Test]
    public function a_created_organisation_disarms_the_autosave_for_good(): void
    {
        $store = $this->spaCode(self::STORE);

        $this->assertMatchesRegularExpression(
            "/if \(outcome\.kind === 'created' \|\| outcome\.kind === 'unconfirmed'\) \{\s*armed\.value = false;\s*autosave\.reset\(\);\s*clearPreviewTimer\(\);/",
            $store
        );
        $this->assertMatchesRegularExpression("/status: 'provisioned', provisioned_masjid_id: masjidId/", $store);
    }

    #[Test]
    public function the_drafts_list_says_live_linked_to_the_organisation(): void
    {
        $list = $this->spaCode('views/dashboard/super/studio/StudioDraftsView.vue');

        $this->assertMatchesRegularExpression(
            '/<router-link v-if="row\.status === \'provisioned\' && row\.provisioned_masjid_id"\s*:to="`\/dashboard\/super\/masjids\/\$\{row\.provisioned_masjid_id\}`" class="status-pill live">\s*Live\s*<\/router-link>/',
            $list
        );
    }

    #[Test]
    public function the_provision_route_is_typed_and_served(): void
    {
        $this->assertStringContainsString('| `/api/admin/studio/drafts/${number}/provision`', $this->spaCode('core/types/config/BackendApiRoutes.ts'));

        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/admin/studio/drafts/7/provision', 'POST'));
        $this->assertSame('api/admin/studio/drafts/{draft_id}/provision', $route->uri());
    }

    /** The code from the first `$from` to the next `$to`. */
    private function between(string $code, string $from, string $to): string
    {
        $start = strpos($code, $from);
        $this->assertNotFalse($start, "'{$from}' not found");
        $end = strpos($code, $to, $start);
        $this->assertNotFalse($end, "'{$to}' not found after '{$from}'");

        return substr($code, $start, $end - $start);
    }
}
