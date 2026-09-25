<?php

namespace Tests\Feature\Studio;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\FormPaymentReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * S9's payment half: Stripe returns a payer who has just paid to the form page's
 * origin, so beyond FORMS_PAYMENT_RETURN_ORIGINS the only origins accepted are
 * the confirmed (corsAdmitted) hosts of THE FORM'S OWN organisation.
 *
 * The rule is pinned on FormPaymentReturn directly, and its wiring through the
 * public submit and "Return to payment" on a form whose id is another
 * organisation's id: FormPaymentCheckoutTest only sends env-listed origins,
 * which never reach the organisation check, so a controller passing the wrong
 * id would otherwise stay green.
 */
class FormPaymentReturnDomainTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    private const ENV_ORIGIN = 'https://sundayschool.burlingtonmasjid.com';

    private Masjid $org;

    private Masjid $other;

    /** @var list<array<string,mixed>> the params of every Checkout Session the stubbed Stripe was asked for */
    public static array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'forms.payment_return_origins' => [self::ENV_ORIGIN],
            'forms.submit_per_hour' => 100,
            'services.stripe.platform_fee_percentage' => 0,
        ]);

        $this->org = $this->makeOrg();
        $this->other = $this->makeOrg();
    }

    private function base(?string $origin, int $masjidId, string $path = '/festival'): ?string
    {
        $request = Request::create('/api/v1/forms/1/responses', 'POST', ['return_path' => $path], [], [], $origin === null ? [] : ['HTTP_ORIGIN' => $origin]);

        return FormPaymentReturn::base($request, $masjidId, ['form_id' => 1]);
    }

    #[Test]
    public function a_confirmed_origin_is_accepted_for_its_own_masjids_form_and_refused_for_another_masjids(): void
    {
        $this->makeDomain($this->org, 'pay.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);

        $this->assertSame('https://pay.example.org/festival', $this->base('https://pay.example.org', $this->org->id));
        $this->assertSame('https://pay.example.org', FormPaymentReturn::allowedOrigin('https://pay.example.org', $this->org->id));

        Log::spy();
        $this->assertNull($this->base('https://pay.example.org', $this->other->id), "another organisation's form");
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'refused a return address') && $context['origin'] === 'https://pay.example.org')
            ->once();

        // With no organisation to check against, only the env list counts.
        $this->assertNull(FormPaymentReturn::allowedOrigin('https://pay.example.org'));
    }

    #[Test]
    public function a_pending_or_unconfirmed_rows_origin_is_refused_for_its_own_masjids_form_too(): void
    {
        $this->makeDomain($this->org, 'pending.example.org', MasjidDomain::STATUS_PENDING, ['serving_confirmed_at' => now()]);
        $this->makeDomain($this->org, 'unconfirmed.example.org', MasjidDomain::STATUS_MANUAL);
        $this->makeDomain($this->org, 'failed.example.org', MasjidDomain::STATUS_FAILED, ['serving_confirmed_at' => now()]);
        $this->makeDomain($this->org, 'reserved.example.org', MasjidDomain::STATUS_RESERVED, [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'serving_confirmed_at' => now(),
        ]);
        $this->makeDomain($this->org, 'control.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);

        $this->assertSame('https://control.example.org/festival', $this->base('https://control.example.org', $this->org->id), 'the control row');

        foreach (['pending', 'unconfirmed', 'failed', 'reserved'] as $label) {
            $this->assertNull($this->base("https://{$label}.example.org", $this->org->id), $label);
        }
    }

    #[Test]
    public function a_trashed_organisations_confirmed_origin_is_refused(): void
    {
        $this->makeDomain($this->org, 'pay.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $this->assertNotNull($this->base('https://pay.example.org', $this->org->id), 'the control');

        $this->org->delete();

        $this->assertNull($this->base('https://pay.example.org', $this->org->id));
    }

    #[Test]
    public function only_the_exact_bare_https_origin_of_the_row_matches(): void
    {
        $this->makeDomain($this->org, 'pay.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);

        // Shapes a browser on our site never sends are refused WITHOUT a lookup.
        // Asserted on the query log, not only on the answer: SQLite's '=' is
        // case-sensitive, so 'PAY.example.org' would miss the row here even if it
        // were looked up, while MySQL's _ci collation would find it. Only the
        // absence of the query pins the lower-case rule on both.
        DB::enableQueryLog();

        foreach ([
            'http://pay.example.org',
            'https://pay.example.org:443',
            'https://PAY.example.org',
            'https://Pay.Example.Org',
            'https://pay.example.org/',
        ] as $origin) {
            DB::flushQueryLog();
            $this->assertNull($this->base($origin, $this->org->id), $origin);
            $this->assertSame([], DB::getQueryLog(), "{$origin} must not reach masjid_domains");
        }

        DB::disableQueryLog();

        // Well-formed but not the row's host: looked up, and matched exactly.
        foreach ([
            'https://pay.example.org.',
            'https://evil.pay.example.org',
            'https://pay.example.org.evil.test',
        ] as $origin) {
            $this->assertNull($this->base($origin, $this->org->id), $origin);
        }

        // And the return path is still checked on a table-admitted origin.
        $this->assertNull($this->base('https://pay.example.org', $this->org->id, '//evil.example'));
    }

    #[Test]
    public function an_env_listed_origin_is_accepted_without_reading_the_table(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(self::ENV_ORIGIN . '/festival', $this->base(self::ENV_ORIGIN, $this->org->id));
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function the_public_submit_builds_the_stripe_return_on_its_own_organisations_confirmed_host_and_refuses_anothers(): void
    {
        [$form] = $this->payableFormWhoseIdIsTheOtherOrgsId();
        $this->makeDomain($this->org, 'pay.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $this->makeDomain($this->other, 'other.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);

        $this->submitTo($form, 'https://pay.example.org')->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1');
        $this->assertStringStartsWith('https://pay.example.org/festival?form=' . $form->id . '&', self::$sessions[0]['success_url']);
        $this->assertStringStartsWith('https://pay.example.org/festival?form=' . $form->id . '&', self::$sessions[0]['cancel_url']);

        // The other organisation's confirmed host, on this organisation's form.
        $this->submitTo($form, 'https://other.example.org')->assertStatus(422)
            ->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->assertCount(1, self::$sessions, 'no page opened on a refused address');
        $this->assertSame(1, FormResponse::count(), 'and no row written for it');
    }

    #[Test]
    public function return_to_payment_builds_the_stripe_return_on_the_rows_organisations_confirmed_host_and_refuses_anothers(): void
    {
        [$form] = $this->payableFormWhoseIdIsTheOtherOrgsId();
        $this->makeDomain($this->org, 'pay.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $this->makeDomain($this->other, 'other.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);

        // Written through the env-listed origin, so this test depends only on the reopen's wiring.
        $this->submitTo($form, self::ENV_ORIGIN)->assertOk();
        $row = FormResponse::sole();
        $this->assertNotSame((int) $row->masjid_id, (int) $row->form_id, 'the premise: the two ids differ');

        // The stubbed Stripe reports every page expired, so a reopen that gets through opens a new one.
        $this->reopenFrom($row, 'https://pay.example.org')->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_2');
        $this->assertStringStartsWith('https://pay.example.org/festival?form=' . $form->id . '&', self::$sessions[1]['success_url']);

        $this->reopenFrom($row, 'https://other.example.org')->assertStatus(422)
            ->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->assertCount(2, self::$sessions, 'no page opened on a refused address');
    }

    #[Test]
    public function a_database_error_refuses_the_return_and_logs_a_warning(): void
    {
        Log::spy();
        Schema::drop('masjid_domains');

        $this->assertNull($this->base('https://pay.example.org', $this->org->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'could not read masjid_domains'))
            ->once();
    }

    /**
     * A card-payable form of $this->org whose id is $this->other's id, so a
     * controller that passed the form's (or the row's form's) id where the
     * organisation's id belongs would check the OTHER organisation's hosts: its
     * own confirmed host would then be refused and the other's accepted.
     *
     * @return array{0: Form}
     */
    private function payableFormWhoseIdIsTheOtherOrgsId(): array
    {
        $this->org->forceFill(['stripe_account_id' => 'acct_test_s9_wiring', 'stripe_charges_enabled' => true])->save();

        $form = new Form([
            'masjid_id' => $this->org->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [
                ['id' => 'contact', 'title' => 'You', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => ['amount' => 15, 'currency' => 'USD'],
                'payment' => ['online' => true],
            ],
            'is_active' => true,
        ]);
        $form->id = $this->other->id;
        $form->save();

        $this->assertNotSame($this->org->id, $form->id, 'the premise: form id and masjid id differ');

        self::$sessions = [];
        Mail::fake();
        $this->stubStripe();

        return [$form];
    }

    private function submitTo(Form $form, string $origin): TestResponse
    {
        return $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => ['fullName' => 'Amal Yusuf'],
            'return_path' => '/festival',
            'client_submission_key' => (string) Str::uuid(),
        ], ['masjid-id' => (string) $form->masjid_id, 'Origin' => $origin]);
    }

    private function reopenFrom(FormResponse $row, string $origin): TestResponse
    {
        return $this->postJson("/api/v1/form-responses/{$row->uuid}/checkout", ['return_path' => '/festival'], [
            'masjid-id' => (string) $row->masjid_id,
            'Origin' => $origin,
        ]);
    }

    /** Stripe through the service's protected seams: pages cs_test_1, cs_test_2…, each already expired when asked about. */
    private function stubStripe(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, fn ($app) => new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
        {
            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                FormPaymentReturnDomainTest::$sessions[] = $params;
                $n = count(FormPaymentReturnDomainTest::$sessions);

                return ['id' => "cs_test_{$n}", 'url' => "https://checkout.stripe.test/pay/cs_test_{$n}", 'payment_intent' => null];
            }

            protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
            {
                return ['status' => 'expired', 'url' => null];
            }

            protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
            {
            }
        });
    }
}
