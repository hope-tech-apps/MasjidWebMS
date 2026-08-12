<?php

namespace Tests\Feature\Sms;

use App\Services\Sms\NullSmsProvider;
use App\Services\Sms\PhoneNumber;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsNotConfiguredException;
use App\Services\Sms\SmsProviderFactory;
use App\Services\Sms\TwilioSmsProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The provider seam and the Twilio adapter (T-009).
 *
 * Every assertion about Twilio here runs against `Http::fake()`. No packet
 * leaves the machine, and the factory's default is the refusing adapter, so the
 * suite could not reach a carrier even if this file were deleted.
 */
class SmsProviderTest extends TestCase
{
    // ---------- the factory's safe default ----------

    #[Test]
    public function an_unconfigured_deployment_gets_the_refusing_adapter(): void
    {
        config([
            'services.sms.driver' => null,
            'services.twilio.account_sid' => null,
            'services.twilio.auth_token' => null,
        ]);

        $provider = app(SmsProviderFactory::class)->make();

        $this->assertInstanceOf(NullSmsProvider::class, $provider);
        $this->assertFalse($provider->isConfigured());
    }

    #[Test]
    public function the_refusing_adapter_throws_rather_than_reporting_a_phantom_send(): void
    {
        $this->expectException(SmsNotConfiguredException::class);

        (new NullSmsProvider())->send(new SmsMessage('+16135550111', 'hello', from: '+16135550100'));
    }

    #[Test]
    public function a_twilio_driver_without_credentials_degrades_to_the_refusing_adapter(): void
    {
        config([
            'services.sms.driver' => 'twilio',
            'services.twilio.account_sid' => null,
            'services.twilio.auth_token' => null,
        ]);

        // Fails SOFT: resolution must not throw. The refusal happens on the
        // request that asked to send, never at boot.
        $this->assertInstanceOf(NullSmsProvider::class, app(SmsProviderFactory::class)->make());
    }

    #[Test]
    public function credentials_alone_are_enough_to_select_twilio(): void
    {
        config([
            'services.sms.driver' => null,
            'services.twilio.account_sid' => 'AC0000000000',
            'services.twilio.auth_token' => 'secret',
        ]);

        $this->assertInstanceOf(TwilioSmsProvider::class, app(SmsProviderFactory::class)->make());
    }

    #[Test]
    public function the_pinned_none_driver_beats_present_credentials(): void
    {
        config([
            'services.sms.driver' => 'none',
            'services.twilio.account_sid' => 'AC0000000000',
            'services.twilio.auth_token' => 'secret',
        ]);

        // What phpunit.xml pins, so no test can put traffic on a network by
        // accident.
        $this->assertInstanceOf(NullSmsProvider::class, app(SmsProviderFactory::class)->make());
    }

    // ---------- the Twilio adapter ----------

    private function configureTwilio(): void
    {
        config([
            'services.twilio.account_sid' => 'AC0000000000',
            'services.twilio.auth_token' => 'secret',
            'services.twilio.api_base' => 'https://api.twilio.com/2010-04-01',
        ]);
    }

    #[Test]
    public function it_posts_the_message_to_the_accounts_messages_endpoint(): void
    {
        $this->configureTwilio();

        Http::fake([
            '*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201),
        ]);

        $result = (new TwilioSmsProvider())->send(new SmsMessage(
            to: '+16135550111',
            body: 'Masjid an-Nur: Snow closure. Reply STOP to unsubscribe.',
            from: '+16135550100',
        ));

        $this->assertSame('SM123', $result->providerMessageId);
        $this->assertSame('twilio', $result->provider);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC0000000000/Messages.json'
                && $request['To'] === '+16135550111'
                && $request['From'] === '+16135550100'
                && str_contains($request['Body'], 'Reply STOP to unsubscribe.')
                && ! isset($request['MessagingServiceSid']);
        });
    }

    #[Test]
    public function it_prefers_the_messaging_service_when_the_tenant_has_one(): void
    {
        $this->configureTwilio();

        Http::fake(['*' => Http::response(['sid' => 'SM124'], 201)]);

        (new TwilioSmsProvider())->send(new SmsMessage(
            to: '+16135550111',
            body: 'body',
            messagingServiceSid: 'MG0000000000',
        ));

        Http::assertSent(function ($request) {
            // The 10DLC campaign registers against the messaging service, and
            // provider-side opt-out is enforced there.
            return $request['MessagingServiceSid'] === 'MG0000000000' && ! isset($request['From']);
        });
    }

    #[Test]
    public function a_provider_rejection_throws_without_quoting_the_number(): void
    {
        $this->configureTwilio();

        Http::fake([
            '*' => Http::response([
                'code' => 21610,
                // Twilio's own message quotes the destination; ours must not
                // repeat it into a delivery row an admin screen renders.
                'message' => 'The message From/To pair violates a blacklist rule: +16135550111',
            ], 400),
        ]);

        try {
            (new TwilioSmsProvider())->send(new SmsMessage('+16135550111', 'body', from: '+16135550100'));
            $this->fail('Expected the adapter to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('21610', $e->getMessage());
            $this->assertStringNotContainsString('+16135550111', $e->getMessage());
        }
    }

    #[Test]
    public function an_unconfigured_twilio_adapter_refuses_before_making_a_request(): void
    {
        config(['services.twilio.account_sid' => null, 'services.twilio.auth_token' => null]);

        Http::fake();

        $this->expectException(SmsNotConfiguredException::class);

        try {
            (new TwilioSmsProvider())->send(new SmsMessage('+16135550111', 'body', from: '+16135550100'));
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---------- normalisation, the key everything else is matched on ----------

    #[Test]
    public function it_normalises_the_shapes_a_human_actually_types(): void
    {
        config(['services.sms.default_country_code' => '1']);

        $this->assertSame('+16135550142', PhoneNumber::e164('+1 613 555 0142'));
        $this->assertSame('+16135550142', PhoneNumber::e164('(613) 555-0142'));
        $this->assertSame('+16135550142', PhoneNumber::e164('1-613-555-0142'));
        $this->assertSame('+16135550142', PhoneNumber::e164('613.555.0142'));
        $this->assertSame('+441632960961', PhoneNumber::e164('00441632960961'));
        $this->assertSame('+441632960961', PhoneNumber::e164('+44 1632 960961'));
    }

    #[Test]
    public function it_refuses_rather_than_guessing_when_the_country_is_ambiguous(): void
    {
        config(['services.sms.default_country_code' => '1']);

        // Eleven bare digits that are not led by the default country code could
        // be anywhere. A guess here produces a plausible number that reaches a
        // stranger and matches no suppression row.
        $this->assertNull(PhoneNumber::e164('441632960961'));
        $this->assertNull(PhoneNumber::e164('5550142'));
        $this->assertNull(PhoneNumber::e164('613-555-0142 ext 4'));
        $this->assertNull(PhoneNumber::e164('call the office'));
        $this->assertNull(PhoneNumber::e164(''));
        $this->assertNull(PhoneNumber::e164(null));
    }

    #[Test]
    public function differently_punctuated_numbers_collapse_to_the_same_suppression_key(): void
    {
        // The property the whole suppression list rests on.
        $this->assertSame(
            PhoneNumber::e164('(613) 555-0142'),
            PhoneNumber::e164('+1 613 555 0142'),
        );
    }
}
