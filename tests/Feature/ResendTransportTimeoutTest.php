<?php

namespace Tests\Feature;

use App\Mail\Transport\ResendWithTimeouts;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Member\MemberSignupService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * A Resend that accepts the connection and never answers must not hang a
 * request (review finding F2, 2026-09-17). See ResendWithTimeouts.
 *
 * The "Resend" here is a socket on 127.0.0.1 that the kernel accepts
 * connections on and nothing ever reads from or answers, reached through the
 * RESEND_BASE_URL override resend-php already reads. That is the hang itself,
 * not a mock of it: before the fix, the first test below never returned.
 * Nothing leaves the machine and no real key is used.
 *
 * What this file pins:
 *  - the `resend` mailer is ours, and its calls give up after the configured
 *    time instead of waiting for ever;
 *  - on both doors that send "Your password was set" inline, a silent Resend
 *    costs a few seconds, not an error: the password is saved, the response
 *    is the success, and the warning is logged;
 *  - the shipped limits are set, and well below nginx's 60 s.
 */
class ResendTransportTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD = 'jasmine-lantern-42-quiet';

    /** Seconds the test lets a call take before the limit has plainly failed. */
    private const WITHIN = 8.0;

    /** @var resource|null */
    private $silentServer = null;

    private string|false $previousBaseUrl = false;

    /** @var list<MessageLogged> */
    private array $logged = [];

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Could not open the silent server: {$errstr}");
        $this->silentServer = $server;

        $this->previousBaseUrl = getenv('RESEND_BASE_URL');
        putenv('RESEND_BASE_URL=http://' . stream_socket_get_name($server, false));

        config([
            'mail.default' => 'resend',
            'services.resend.key' => 're_test_not_a_real_key',
            'services.resend.connect_timeout' => 1,
            'services.resend.timeout' => 2,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });

        $this->asANewRequest();
        $this->masjid = Masjid::create([
            'name' => 'Masjid An-Nur',
            'email' => 'office@masjidannur.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->silentServer)) {
            fclose($this->silentServer);
        }

        putenv($this->previousBaseUrl === false ? 'RESEND_BASE_URL' : 'RESEND_BASE_URL=' . $this->previousBaseUrl);

        parent::tearDown();
    }

    #[Test]
    public function a_resend_call_that_is_never_answered_gives_up_instead_of_waiting_for_ever(): void
    {
        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());

        $started = microtime(true);

        try {
            Mail::mailer('resend')->raw('ping', fn ($message) => $message->to('nobody@test.local')->subject('ping'));
            $this->fail('A send to a server that never answers reported success.');
        } catch (TransportException $e) {
            // The limit, not something else, is what ended it.
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(1.5, $elapsed, 'It failed before the limit, so the test did not reach the silent server.');
        $this->assertLessThan(self::WITHIN, $elapsed);
    }

    #[Test]
    public function a_silent_resend_still_saves_a_portal_password_and_answers_ok(): void
    {
        $parent = $this->contact('portal.silent@test.local', ['login_enabled_at' => now()]);
        $token = $parent->createFamilyToken()->plainTextToken;
        $this->asANewRequest();

        $started = microtime(true);

        $this->withToken($token)
            ->putJson("/api/family/masjids/{$this->masjid->id}/password", [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk()->assertJsonPath('data.has_password', true);

        $this->assertLessThan(self::WITHIN, microtime(true) - $started);
        $this->assertTrue(Hash::check(self::GOOD, $parent->fresh()->getAuthPassword()));
        $this->assertNoticeFailureLogged($parent);
    }

    #[Test]
    public function a_silent_resend_still_resets_an_app_password_and_signs_the_member_in(): void
    {
        $email = 'app.silent@test.local';
        $member = $this->contact($email, ['signup_source' => 'app', 'verified_at' => now()]);
        $code = $this->mintCode($email);

        $started = microtime(true);

        $response = $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/verify-code", [
            'email' => $email,
            'code' => $code,
            'password' => self::GOOD,
        ])->assertOk();

        $this->assertLessThan(self::WITHIN, microtime(true) - $started);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertTrue(Hash::check(self::GOOD, $member->fresh()->getAuthPassword()));
        $this->assertNoticeFailureLogged($member);
    }

    #[Test]
    public function the_shipped_limits_are_set_and_well_below_nginxs_sixty_seconds(): void
    {
        // The values in config/services.php, not the ones this file set.
        $shipped = require base_path('config/services.php');

        $this->assertGreaterThan(0, $shipped['resend']['connect_timeout']);
        $this->assertGreaterThan(0, $shipped['resend']['timeout']);
        // Guzzle's `timeout` covers the whole call, connection included.
        $this->assertLessThanOrEqual(15, $shipped['resend']['timeout']);

        // A missing value must not become Guzzle's 0, which means "no limit".
        config(['services.resend.connect_timeout' => null, 'services.resend.timeout' => null]);
        $guzzle = $this->guzzleOf(ResendWithTimeouts::client('re_test_not_a_real_key'));
        $this->assertEquals(ResendWithTimeouts::DEFAULT_CONNECT_TIMEOUT, $guzzle->getConfig('connect_timeout'));
        $this->assertEquals(ResendWithTimeouts::DEFAULT_TIMEOUT, $guzzle->getConfig('timeout'));
    }

    // ------------------------------------------------------------ helpers

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** @param  array<string, mixed>  $extra */
    private function contact(string $email, array $extra): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill(array_merge([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Maryam',
            'last_name' => 'Parent',
            'email' => $email,
            'login_email' => $email,
        ], $extra))->save();

        return $contact->refresh();
    }

    /** A live code row written directly, so requesting one does not wait on the silent server too. */
    private function mintCode(string $email): string
    {
        $code = '482915';

        $service = app(MemberSignupService::class);
        $hash = (new \ReflectionMethod($service, 'hash'))->invoke($service, $code);

        AppSignupCode::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'email' => $email,
            'code_hash' => $hash,
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->asANewRequest();

        return $code;
    }

    private function assertNoticeFailureLogged(Contact $contact): void
    {
        $failures = array_values(array_filter(
            $this->logged,
            fn (MessageLogged $e) => $e->message === 'password set notice delivery failed',
        ));

        $this->assertCount(1, $failures, 'The stuck send left no log line.');
        $this->assertSame('warning', $failures[0]->level);
        $this->assertSame((int) $contact->id, (int) $failures[0]->context['contact_id']);
        $this->assertSame(TransportException::class, $failures[0]->context['exception']);
    }

    private function guzzleOf(\Resend\Client $client): \GuzzleHttp\Client
    {
        $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);
        $guzzle = (new ReflectionProperty($transporter, 'client'))->getValue($transporter);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $guzzle);

        return $guzzle;
    }
}
