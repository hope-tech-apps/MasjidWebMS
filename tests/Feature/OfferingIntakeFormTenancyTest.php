<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\Offering;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE WRITER MUST FILTER `masjid_id` EXACTLY AS EVERY READER DOES.
 *
 * `RegistrationService::register()` loaded the offering's intake form with a
 * bare `Form::query()->whereKey($offering->intake_form_id)->first()` — no tenant
 * filter — while every other reader of that same column has one:
 *
 *     OfferingPublicPayload::intakeForm            where masjid_id
 *     OfferingRegistrationState::intakeFormExists  where masjid_id
 *     AdminDashboard\OfferingsController           where masjid_id
 *
 * The public register path runs UNBOUND (no tenant middleware), so the
 * `BelongsToMasjid` global scope adds no constraint at all and a bare
 * `whereKey()` resolves ANY organisation's form
 * (.claude/rules/tenant-scoping.md).
 *
 * Measured with `offerings.intake_form_id` pointing at another organisation's
 * form:
 *
 *     GET  /api/v1/offerings/{slug}    200, registration_state "closed",
 *                                      registration_state_reason no_intake_form,
 *                                      intake_form null      <- the readers filtered
 *     POST /api/v1/offerings/{slug}/register
 *                                      200                   <- the writer did not
 *                                      -> a form_responses row with
 *                                         masjid_id = org A and form_id = org B's form
 *
 * — answers validated against another organisation's schema, stored against its
 * form, and counted in its response totals, while every surface reported the
 * program closed.
 *
 * ## Reachability, stated plainly
 *
 * `OfferingFormRequest::ownedRule` blocks a cross-tenant `intake_form_id` on the
 * admin surface, so a row in this shape needs a seeder, an import or a manual DB
 * edit to arise. That is why this is the lowest-severity of the five and not the
 * highest. It is fixed anyway because it is the exact shape of the two holes
 * that shipped this month — a hand-filter present in every reader and absent in
 * the one place that WRITES — and because the failure is silent in both
 * directions at once: the page says closed, and the write succeeds.
 */
class OfferingIntakeFormTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The public register path runs exactly like this.
        app(TenantContext::class)->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    #[Test]
    public function a_registration_can_never_be_validated_against_another_organisations_form(): void
    {
        [$offering, $plan] = $this->crossTenantOffering();

        $headers = ['masjid-id' => (string) $this->masjidA->id];

        // The READERS already reported this correctly — which is precisely what
        // made the write path's silence dangerous.
        $page = $this->getJson("/api/v1/offerings/{$offering->slug}", $headers)
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('closed', $page['registration_state']);
        $this->assertSame('no_intake_form', $page['registration_state_reason']);
        $this->assertNull($page['intake_form']);

        // The write now agrees with them.
        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Karim', 'email' => 'aisha@test.local'],
            // org B's field, which is the only reason this body would validate.
            'data' => ['b_only_question' => 'yes'],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This offering is not currently accepting registrations.');

        // Nothing written anywhere, for either organisation.
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertSame(0, (int) Offering::withoutMasjidScope()->find($offering->id)->registration_count);
    }

    #[Test]
    public function no_form_response_is_ever_written_with_one_organisations_id_and_anothers_form(): void
    {
        // The row the defect produced, named as its own assertion: a
        // form_responses row is keyed by BOTH columns and they used to be able
        // to disagree. Nothing in the schema stops it — only this filter does.
        [$offering, $plan] = $this->crossTenantOffering();

        try {
            app(RegistrationService::class)->register(
                $offering->fresh(),
                $plan,
                Contact::factory()->create(['masjid_id' => $this->masjidA->id]),
                ['b_only_question' => 'yes']
            );

            $this->fail('register() accepted another organisation\'s intake form');
        } catch (RegistrationException $e) {
            $this->assertSame('This offering is not currently accepting registrations.', $e->getMessage());
        }

        $mismatched = FormResponse::query()
            ->join('forms', 'forms.id', '=', 'form_responses.form_id')
            ->whereColumn('forms.masjid_id', '!=', 'form_responses.masjid_id')
            ->count();

        $this->assertSame(0, $mismatched, 'a form_response was stored against another organisation\'s form');
    }

    #[Test]
    public function the_offerings_own_form_still_registers_normally(): void
    {
        // The regression floor: the filter must refuse the foreign form and
        // nothing else. The factory pins the form to the same masjid.
        $offering = Offering::factory()->forMasjid($this->masjidA)->create(['slug' => 'own-form']);

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
        ]);

        $registration = app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjidA->id]),
            ['full_name' => 'Yusuf Ali']
        );

        $this->assertSame('confirmed', $registration->status);
        $this->assertSame(
            $this->masjidA->id,
            (int) FormResponse::query()->findOrFail($registration->form_response_id)->masjid_id
        );
    }

    // ============================= helpers =============================

    /**
     * An offering owned by masjid A whose `intake_form_id` points at a form
     * owned by masjid B — the shape a seeder or an import can produce and the
     * admin FormRequest cannot.
     *
     * @return array{0: Offering, 1: FeePlan}
     */
    private function crossTenantOffering(): array
    {
        $foreignForm = Form::create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'B only intake',
            'slug' => 'b-only-intake-' . uniqid(),
            'schema' => ['fields' => [
                ['key' => 'b_only_question', 'type' => 'text', 'label' => 'B only', 'required' => true],
            ]],
            'is_active' => true,
        ]);

        $offering = Offering::factory()->forMasjid($this->masjidA)->create(['slug' => 'borrowed-form']);

        // Not mass-assignable through any admin path in this shape — set it the
        // way a bad import would.
        $offering->forceFill(['intake_form_id' => $foreignForm->id])->save();

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering->fresh(), $plan];
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
            // The public registration surface asks this — see
            // PublicRegistrationCrmGateTest.
            'crm_enabled' => true,
        ], $overrides));
    }
}
