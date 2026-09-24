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
 *  - the drafts list says Live, linked to the organisation;
 *  - the store posts only through saveThenPost (flush, check, post), and the
 *    step blanks the credentials by clearsSecrets;
 *  - nothing can be edited, no step opened, and leaving asks first, while a
 *    provision runs; the answer takes focus;
 *  - a provisioned draft's deleted logo is not fetched, and pages are
 *    "switched on", never "live", before the domain panel says so.
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
        $this->assertMatchesRegularExpression('/iqamaBlockers\(store\.answers, asksPrayer\(store\.answers\)\)/', $step);
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
        $this->assertMatchesRegularExpression('/ApiService\.post\(`\/api\/admin\/studio\/drafts\/\$\{current\.id\}\/provision`, provisionBody\(answers, secrets, lockVersion\)\)/', $store);
        $this->assertDoesNotMatchRegularExpression('/(ref|reactive|shallowRef)(<[^>]*>)?\([^)]*emptySecrets/', $store, 'the draft store must hold no credentials');

        // The two places the store may name them, removed exactly; any other
        // mention, even on one of those lines, is a leak.
        $allowed = ['provisionBody(answers, secrets, lockVersion)', 'async function provision(secrets: ProvisionSecrets)'];
        foreach ($allowed as $expression) {
            $this->assertSame(1, substr_count($store, $expression), "'{$expression}' should appear once");
        }
        $rest = str_replace($allowed, '', $store);
        $this->assertDoesNotMatchRegularExpression('/\bsecrets\b/', $rest, 'the store names the credentials outside provision()\'s signature and provisionBody');

        // Blanked by the rule that says an organisation now exists, right after the answer.
        $this->assertMatchesRegularExpression('/const result = await store\.provision\(secrets\);\s*if \(clearsSecrets\(result\)\) clearSecrets\(secrets\);/', $step);

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

        $this->assertMatchesRegularExpression('/v-if="outcome\?\.kind === \'created\'"[^>]*>\s*<ProvisionResults :result="outcome\.result" :invitee="outcome\.invitee" \/>/', $step);
        $this->assertSame(1, substr_count($step, '<ProvisionResults'), 'the results are drawn in one place');
        $this->assertMatchesRegularExpression('/v-else-if="outcome\?\.kind === \'unconfirmed\'" id="studio-generate-outcome" tabindex="-1"\s+class="alert alert-danger/', $step);
    }

    #[Test]
    public function the_invitation_is_ticked_only_through_invite_outcome(): void
    {
        $results = $this->spaCode(self::RESULTS);

        // Who the draft named when Provision was pressed, carried in the outcome; never the live answers.
        $this->assertStringContainsString('const invite = computed(() => inviteOutcome(props.result.after_commit, props.invitee));', $results);
        $this->assertStringNotContainsString('store.answers', $results);
        $this->assertMatchesRegularExpression('/let invitee: Invitee = inviteeOf\(answers\);/', $this->spaCode(self::STORE));
        $this->assertMatchesRegularExpression('/readProvisionOutcome\(sent\.answer\.status, sent\.answer\.body, invitee\)/', $this->spaCode(self::STORE));
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
        $this->assertMatchesRegularExpression("/if \(outcome\.kind === 'conflict' \|\| outcome\.kind === 'changed'\) \{\s*await load\(current\.id\);/", $store);

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

    /**
     * DECISIONS (S8 stage B): the autosave is flushed before the POST, and if
     * it failed or conflicted nothing is sent. The order is saveThenPost's
     * (unit-tested in studio-provision.test.ts); this pins that the store's
     * only provision POST is the one it runs last.
     */
    #[Test]
    public function the_store_posts_only_through_save_then_post(): void
    {
        $store = $this->spaCode(self::STORE);
        $provision = $this->between($store, 'async function provision(secrets: ProvisionSecrets)', 'async function fetchOptions');

        $this->assertMatchesRegularExpression('/const sent = await saveThenPost\(\{\s*flush,\s*stillOpen: \(\) => gen === generation,/', $provision);
        $this->assertMatchesRegularExpression('/unsaved: changedSections\(answers, saved\.value\)\.length,/', $provision);
        $this->assertMatchesRegularExpression('/post: async \(\)[^{]*\{.*ApiService\.post\(`\/api\/admin\/studio\/drafts\/\$\{current\.id\}\/provision`/s', $provision);
        $this->assertSame(1, substr_count($provision, 'ApiService.post('), 'one POST, inside the post step');
        $this->assertLessThan(strpos($provision, 'ApiService.post('), strpos($provision, 'saveThenPost('), 'the POST is saveThenPost\'s last step, not before it');
        $this->assertStringNotContainsString('flush()', $provision, 'the flush is saveThenPost\'s, not called around it');

        // A refusal is reported, and nothing after it reads an answer.
        $this->assertMatchesRegularExpression("/if \(sent\.kind === 'closed' \|\| gen !== generation\) return null;\s*if \(sent\.kind === 'unsaved'\) \{\s*provisionOutcome\.value = \{ kind: 'failed', message: sent\.message \};\s*return provisionOutcome\.value;\s*\}/", $provision);
    }

    /**
     * While the POST runs the server builds from the answers it holds; an edit
     * then would be autosaved beside it, or dropped by the created branch.
     */
    #[Test]
    public function nothing_is_editable_and_no_step_opens_while_a_provision_runs(): void
    {
        $store = $this->spaCode(self::STORE);
        $this->assertStringContainsString('const editable = computed(() => !readOnly.value && !provisioning.value);', $store);
        $this->assertMatchesRegularExpression('/async function uploadLogo\(file: File\): Promise<boolean> \{\s*const current = draft\.value;\s*if \(!current \|\| !editable\.value\) return false;/', $store);
        $this->assertMatchesRegularExpression('/async function removeLogo\(\): Promise<boolean> \{\s*const current = draft\.value;\s*if \(!current \|\| !editable\.value\) return false;/', $store);

        foreach ([
            'components/super/studio/steps/StepFoundation.vue',
            'components/super/studio/steps/StudioFeatureStep.vue',
            'components/super/studio/steps/StudioLayoutStep.vue',
            'components/super/studio/foundation/PlatformsPanel.vue',
            'components/super/studio/foundation/DomainPanel.vue',
        ] as $file) {
            $code = $this->spaCode($file);
            $this->assertStringContainsString('!store.editable', $code, "{$file} locks on editable");
            $this->assertStringNotContainsString('store.readOnly', $code, "{$file} would stay editable while a provision runs");
        }
        $this->assertMatchesRegularExpression('/<fieldset class="step-foundation" :disabled="!store\.editable">/', $this->spaCode('components/super/studio/steps/StepFoundation.vue'));
        $this->assertDoesNotMatchRegularExpression('/:disabled="[^"]*store\.readOnly/', $this->spaCode('components/super/studio/foundation/BrandPanel.vue'));

        $view = $this->spaCode('views/dashboard/super/studio/StudioView.vue');
        $this->assertMatchesRegularExpression('/function canOpen\(step: StudioStepKey\): boolean \{\s*return !store\.provisioning && stepBlockedReason\(step\) === \'\';/', $view);
        $this->assertMatchesRegularExpression('/:disabled="currentIndex === 0 \|\| store\.provisioning"/', $view);
        $this->assertMatchesRegularExpression('/:disabled="!canOpen\(step\.key\)"/', $view);
        $this->assertMatchesRegularExpression('/:disabled="!canOpen\(nextStep\.key\)"/', $view);
    }

    /**
     * The results are reported only in the provision's answer. Leaving while
     * it runs (a link, a reload) would lose them: the browser prompts, and a
     * route change asks.
     */
    #[Test]
    public function leaving_while_a_provision_runs_asks_first(): void
    {
        $this->assertMatchesRegularExpression('/function hasUnsavedWork\(\): boolean \{\s*return provisioning\.value \|\|/', $this->spaCode(self::STORE));

        $view = $this->spaCode('views/dashboard/super/studio/StudioView.vue');
        $this->assertMatchesRegularExpression('/function onBeforeUnload\(event: BeforeUnloadEvent\) \{\s*if \(!store\.hasUnsavedWork\(\)\) return;/', $view);
        $this->assertMatchesRegularExpression('/onBeforeRouteLeave\(async \(\) => \{\s*if \(store\.provisioning\) \{\s*const answer = await QSwal\.fire\(/', $view);
        $guard = $this->between($view, 'if (store.provisioning) {', 'if (store.hasUnsavedWork()) await store.flush();');
        $this->assertMatchesRegularExpression('/if \(answer\.isConfirmed\) store\.reset\(\);\s*return answer\.isConfirmed;/', $guard, 'Stay keeps the page and the provision\'s answer');
    }

    #[Test]
    public function the_answer_takes_focus(): void
    {
        $step = $this->spaCode(self::STEP);

        $this->assertMatchesRegularExpression('/await nextTick\(\);\s*const target = outcomeFocusId\(result\);\s*if \(target\) document\.getElementById\(target\)\?\.focus\(\);/', $step);
        // Every alert the answer can draw is the one element outcomeFocusId names, focusable from script.
        $this->assertSame(5, preg_match_all('/id="studio-generate-outcome" tabindex="-1"/', $step));
        foreach (['unconfirmed', 'invalid', 'failed', 'changed', 'unknown'] as $kind) {
            $this->assertMatchesRegularExpression("/v-(else-)?if=\"outcome\\?\\.kind === '{$kind}'\" id=\"studio-generate-outcome\"/", $step, $kind);
        }
        // The panels whose headings it names (StudioPanel ids come from their titles).
        $this->assertStringContainsString('title="Created"', $step);
        $this->assertStringContainsString('title="Already provisioned"', $step);
    }

    /**
     * Provisioning deletes the draft's private logo bytes; fetching them for a
     * provisioned draft answered 404 and showed "This draft has no logo." as
     * an error beside the logo's name.
     */
    #[Test]
    public function a_provisioned_drafts_logo_is_not_fetched_and_is_said_to_be_on_the_organisation(): void
    {
        $this->assertMatchesRegularExpression(
            "/async function fetchLogo\(\): Promise<void> \{\s*const current = draft\.value;\s*revokeLogoUrl\(\);\s*if \(!current\?\.logo \|\| current\.status === 'provisioned'\) return;/",
            $this->spaCode(self::STORE)
        );
        $this->assertMatchesRegularExpression('/<span v-else-if="store\.readOnly && store\.draft\?\.logo"[^>]*>On the organisation<\/span>/', $this->spaCode('components/super/studio/foundation/BrandPanel.vue'));
    }

    /** Until S11 serves a Studio site, "live" is the domain panel's word alone (R24). */
    #[Test]
    public function website_sections_are_switched_on_not_live(): void
    {
        $results = $this->spaCode(self::RESULTS);

        $this->assertMatchesRegularExpression("/\{\{ site\.sections_active === 1 \? 'section' : 'sections' \}\} switched on/", $results);
        $this->assertFalse($this->saysWords($results, 'live'), 'ProvisionResults must not call anything live');
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
