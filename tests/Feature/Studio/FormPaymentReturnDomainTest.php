<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Support\FormPaymentReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * S9's payment half: Stripe returns a payer who has just paid to the form page's
 * origin, so beyond FORMS_PAYMENT_RETURN_ORIGINS the only origins accepted are
 * the confirmed (corsAdmitted) hosts of THE FORM'S OWN organisation.
 */
class FormPaymentReturnDomainTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    private const ENV_ORIGIN = 'https://sundayschool.burlingtonmasjid.com';

    private Masjid $org;

    private Masjid $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms.payment_return_origins' => [self::ENV_ORIGIN]]);

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

        foreach ([
            'http://pay.example.org',
            'https://pay.example.org:443',
            'https://PAY.example.org',
            'https://pay.example.org/',
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
    public function a_database_error_refuses_the_return_and_logs_a_warning(): void
    {
        Log::spy();
        Schema::drop('masjid_domains');

        $this->assertNull($this->base('https://pay.example.org', $this->org->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'could not read masjid_domains'))
            ->once();
    }
}
