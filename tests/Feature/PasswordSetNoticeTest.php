<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Mail\PasswordSetNoticeMail;
use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Services\Family\FamilyPasswordService;
use App\Support\MailGreeting;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * "Your password was set" (owner, 2026-09-17: "Yes, send it" — "One short
 * email to the account's address after any password is set").
 *
 * What this file pins:
 *
 *  - ONCE, TO THE LOGIN ADDRESS, on each of the three doors that set a
 *    contact's password: the app's create-account, the app's forgot-password,
 *    and the family portal's own set-password. Never to `contacts.email`.
 *  - NEVER FOR A WRITE THAT DID NOT HAPPEN: a refused verify-code, a 422, a
 *    wrong code, a rolled-back transaction. And not before the outermost
 *    transaction commits.
 *  - NOTHING IN IT OPENS ANYTHING: the rendered HTML and text carry no
 *    password, hash, code, token or link, not even one a stranger planted as
 *    a first name through the public registration form.
 *  - A FAILED SEND COSTS NOTHING: the password is still set, the response is
 *    still the success, and a warning is logged (production's level).
 *  - Removing a password sends nothing (DECISIONS.md 2026-09-17).
 */
class PasswordSetNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** Long enough for the rule and not a published phrase (see FamilyPasswordTest::GOOD). */
    private const GOOD = 'jasmine-lantern-42-quiet';

    /** @var list<MessageLogged> */
    private array $logged = [];

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Organisation names and addresses with no digits, so the "no six-digit
        // code anywhere in the body" check below cannot trip on a test fixture.
        $this->masjid = $this->makeMasjid('Masjid An-Nur');

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    // ------------------------------------------------------ sent, once each

    #[Test]
    public function creating_an_account_in_the_app_sends_one_notice_to_the_new_login_address(): void
    {
        $email = 'amina.create@test.local';
        $code = $this->requestCode($email);

        $response = $this->verify($email, $code, [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'password' => self::GOOD,
        ])->assertOk()->assertJsonPath('data.created', true);

        $mail = $this->theOneNotice();

        $this->assertTrue($mail->hasTo($email));
        $this->assertSame($email, $mail->loginEmail);
        $this->assertSame('Amina', $mail->recipientName);
        $this->assertTrue($mail->usesApp);
        $this->assertFalse($mail->usesFamilyPortal);

        $this->assertCarriesNothingThatOpensAnything($mail, [
            self::GOOD,
            $code,
            (string) $response->json('data.token'),
        ]);

        [$html, $text] = $this->rendered($mail);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Masjid An-Nur', $body);
            $this->assertStringContainsString($email, $body);
            $this->assertStringContainsString('Forgot password?', $body);
            $this->assertStringNotContainsString('family portal', $body, 'This member has no family login; the portal is not theirs to use.');
        }
    }

    #[Test]
    public function forgot_password_in_the_app_sends_one_notice_to_the_login_address_and_not_the_office_address(): void
    {
        $login = 'bilal.login@test.local';
        $office = 'household.office@test.local';
        $contact = $this->appMember($login, password: 'copper-kettle-19-morning', extra: ['email' => $office]);

        $code = $this->requestCode($login);

        $response = $this->verify($login, $code, ['password' => self::GOOD])
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertTrue(Hash::check(self::GOOD, $contact->fresh()->getAuthPassword()));

        $mail = $this->theOneNotice();
        $this->assertTrue($mail->hasTo($login));
        $this->assertFalse($mail->hasTo($office));
        Mail::assertNotSent(PasswordSetNoticeMail::class, fn (PasswordSetNoticeMail $m) => $m->hasTo($office));

        // One recipient, and nobody copied.
        $this->assertCount(1, $mail->to);
        $this->assertSame([], $mail->cc);
        $this->assertSame([], $mail->bcc);

        $this->assertCarriesNothingThatOpensAnything($mail, [
            self::GOOD,
            'copper-kettle-19-morning',
            $code,
            (string) $response->json('data.token'),
        ]);
    }

    #[Test]
    public function setting_a_password_in_the_family_portal_sends_one_notice_to_the_login_address(): void
    {
        $parent = $this->portalParent('parent.login@test.local', ['email' => 'roster.household@test.local']);
        $token = $this->familyToken($parent);

        $this->withToken($token)
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk()->assertJsonPath('data.has_password', true);

        $mail = $this->theOneNotice();
        $this->assertTrue($mail->hasTo('parent.login@test.local'));
        Mail::assertNotSent(PasswordSetNoticeMail::class, fn (PasswordSetNoticeMail $m) => $m->hasTo('roster.household@test.local'));
        $this->assertCount(1, $mail->to);

        $this->assertFalse($mail->usesApp);
        $this->assertTrue($mail->usesFamilyPortal);

        $this->assertCarriesNothingThatOpensAnything($mail, [self::GOOD, $token]);

        [$html, $text] = $this->rendered($mail);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('family portal', $body);
            $this->assertStringContainsString('Change my password', $body);
            // This parent has never used an app here, and the school may not
            // have one. Telling them to use it would be a made-up claim.
            $this->assertStringNotContainsString('in the app', $body);
            $this->assertStringNotContainsString('Forgot password?', $body);
        }
    }

    #[Test]
    public function a_parent_who_uses_both_the_app_and_the_portal_is_told_both_ways_back(): void
    {
        $parent = $this->portalParent('both.doors@test.local', ['verified_at' => now()]);

        $this->withToken($this->familyToken($parent))
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk();

        $mail = $this->theOneNotice();
        $this->assertTrue($mail->usesApp);
        $this->assertTrue($mail->usesFamilyPortal);

        [$html, $text] = $this->rendered($mail);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Forgot password?', $body);
            $this->assertStringContainsString('Change my password', $body);
            $this->assertStringContainsString('contact Masjid An-Nur', $body);
        }
    }

    // ---------------------------------------------------- never sent

    #[Test]
    public function a_wrong_code_with_a_password_sends_nothing_and_sets_nothing(): void
    {
        $email = 'wrong.code@test.local';
        $contact = $this->appMember($email);
        $code = $this->requestCode($email);

        $this->verify($email, $this->wrongCode($code), ['password' => self::GOOD])->assertStatus(410);

        Mail::assertNotSent(PasswordSetNoticeMail::class);
        $this->assertFalse($contact->fresh()->hasFamilyPassword());
    }

    #[Test]
    public function a_refused_verify_code_with_a_correct_code_sends_nothing(): void
    {
        // A revoked contact: issue() mails it no code, so the row is written
        // directly, and redeem() refuses it inside the transaction.
        $email = 'revoked.member@test.local';
        $contact = $this->appMember($email, extra: ['login_revoked_at' => now()]);
        $code = $this->mintCode($email);

        $this->verify($email, $code, ['password' => self::GOOD])->assertStatus(410);

        Mail::assertNotSent(PasswordSetNoticeMail::class);
        $this->assertFalse($contact->fresh()->hasFamilyPassword());

        // And a replayed code, after a real reset, sends nothing more.
        $live = 'replayed.code@test.local';
        $this->appMember($live);
        $liveCode = $this->requestCode($live);
        $this->verify($live, $liveCode, ['password' => self::GOOD])->assertOk();
        $this->verify($live, $liveCode, ['password' => 'copper-kettle-19-morning'])->assertStatus(410);

        Mail::assertSent(PasswordSetNoticeMail::class, 1);
    }

    #[Test]
    public function a_422_sends_nothing(): void
    {
        // A short password: refused by the request rules before the code is read.
        $short = 'short.password@test.local';
        $contact = $this->appMember($short);
        $code = $this->requestCode($short);
        $this->verify($short, $code, ['password' => 'too-short'])->assertStatus(422);
        $this->assertFalse($contact->fresh()->hasFamilyPassword());

        // A new member with a blank name: the correct code is matched, the
        // transaction is rolled back, and the code survives.
        $newcomer = 'blank.name@test.local';
        $newCode = $this->requestCode($newcomer);
        $this->verify($newcomer, $newCode, [
            'first_name' => 'Hafsa',
            'last_name' => '',
            'password' => self::GOOD,
        ])->assertStatus(422);
        $this->assertNull(Contact::withoutMasjidScope()->where('login_email', $newcomer)->first());
        $this->assertNull($this->codeRow($newcomer)->consumed_at);

        // The portal: two passwords that do not match.
        $parent = $this->portalParent('mismatch.parent@test.local');
        $this->withToken($this->familyToken($parent))
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => 'copper-kettle-19-morning',
            ])->assertStatus(422);
        $this->assertFalse($parent->fresh()->hasFamilyPassword());

        Mail::assertNotSent(PasswordSetNoticeMail::class);
    }

    #[Test]
    public function signing_in_or_removing_a_password_sends_nothing(): void
    {
        // A code sign-in with no password.
        $email = 'code.only@test.local';
        $this->appMember($email, password: self::GOOD);
        $code = $this->requestCode($email);
        $this->verify($email, $code)->assertOk();

        // A password sign-in.
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/password", [
            'email' => $email,
            'password' => self::GOOD,
        ])->assertOk();

        // The portal's "Remove it".
        $parent = $this->portalParent('removes.it@test.local', [
            'password' => Hash::make(self::GOOD),
            'password_set_at' => now(),
        ]);
        $this->asANewRequest();
        $this->withToken($this->familyToken($parent))
            ->deleteJson($this->portalPasswordUrl())
            ->assertOk()
            ->assertJsonPath('data.has_password', false);

        // A code sign-in that adopts an address and drops a password left on
        // the record from another address. A notice here would tell this
        // mailbox's reader that the record had a password under someone
        // else's address.
        $household = 'household.adopted@test.local';
        $office = $this->appMember($household, password: self::GOOD, verified: false, extra: [
            'login_email' => null,
            'signup_source' => null,
        ]);
        $adoptCode = $this->requestCode($household);
        $this->verify($household, $adoptCode)->assertOk();
        $this->assertFalse($office->fresh()->hasFamilyPassword(), 'The leftover password is dropped.');

        Mail::assertNotSent(PasswordSetNoticeMail::class);
    }

    #[Test]
    public function a_rolled_back_write_sends_nothing_and_leaves_no_password(): void
    {
        $contact = $this->appMember('rolled.back@test.local');

        try {
            DB::transaction(function () use ($contact): void {
                app(FamilyPasswordService::class)->set($contact, self::GOOD);

                // set()'s own transaction has committed INTO this one. Whatever
                // fails after it must take the notice down with the password.
                throw new RuntimeException('something after the write failed');
            });
            $this->fail('The transaction should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('something after the write failed', $e->getMessage());
        }

        $this->assertFalse($contact->fresh()->hasFamilyPassword());
        Mail::assertNotSent(PasswordSetNoticeMail::class);
    }

    #[Test]
    public function the_notice_waits_for_the_outermost_commit(): void
    {
        $contact = $this->appMember('outer.commit@test.local');
        $sentInside = null;

        DB::transaction(function () use ($contact, &$sentInside): void {
            app(FamilyPasswordService::class)->set($contact, self::GOOD);

            // set() has returned, and the code-burning transaction around it
            // (here, this one) has not committed yet.
            $sentInside = Mail::sent(PasswordSetNoticeMail::class)->count();
        });

        $this->assertSame(0, $sentInside, 'The notice went out before the outer transaction committed.');
        Mail::assertSent(PasswordSetNoticeMail::class, 1);
        Mail::assertSent(PasswordSetNoticeMail::class, fn (PasswordSetNoticeMail $m) => $m->hasTo('outer.commit@test.local'));
    }

    // ------------------------------------------------ a failed send

    #[Test]
    public function a_failed_send_in_the_portal_keeps_the_password_and_logs_a_warning(): void
    {
        $address = 'relay.down@test.local';
        $parent = $this->portalParent($address);
        $token = $this->familyToken($parent);

        $this->makeTheRelayFail($address);

        $this->withToken($token)
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk()->assertJsonPath('data.has_password', true);

        $this->assertTrue(Hash::check(self::GOOD, $parent->fresh()->getAuthPassword()));
        $this->assertDeliveryFailureLogged($parent, $address);
    }

    #[Test]
    public function a_failed_send_in_the_app_still_resets_the_password_and_signs_the_member_in(): void
    {
        $address = 'relay.down.app@test.local';
        $contact = $this->appMember($address);
        $code = $this->requestCode($address);

        $this->makeTheRelayFail($address);

        $token = $this->verify($address, $code, ['password' => self::GOOD])
            ->assertOk()
            ->json('data.token');

        $this->assertNotEmpty($token);
        $this->assertTrue(Hash::check(self::GOOD, $contact->fresh()->getAuthPassword()));
        $this->assertNotNull($this->codeRow($address)->consumed_at);
        $this->assertDeliveryFailureLogged($contact, $address);
    }

    // ------------------------------------------- a name that is not a name

    #[Test]
    public function a_web_address_planted_as_a_first_name_reaches_neither_the_code_email_nor_the_notice(): void
    {
        // Review finding F1, reproduced end to end. A stranger, not signed in,
        // registers for a free program with the victim's address and a "name"
        // that is a web address. The registration keeps its first word as
        // first_name next to that address, with no login address.
        $victim = 'victim.planted@test.local';
        $link = 'https://evil.example/secure-your-account';

        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'planted-name']);
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->asANewRequest();
        $this->postJson('/api/v1/offerings/planted-name/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => "{$link} now", 'email' => $victim],
            'data' => ['full_name' => 'Anyone'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertOk();

        $planted = Contact::withoutMasjidScope()->where('email', $victim)->sole();
        $this->assertSame($link, $planted->first_name, 'The premise: the form stores the address-shaped name.');
        $this->assertNull($planted->login_email);

        // The stranger asks for a sign-in code to that address, as anyone can.
        // The genuine code email must not greet the reader with the link.
        $code = $this->requestCode($victim);
        $codeMail = Mail::sent(FamilyLoginCodeMail::class, fn (FamilyLoginCodeMail $m) => $m->hasTo($victim))->last();
        $this->assertNull($codeMail->recipientName);
        $codeHtml = (string) $codeMail->render();
        $this->assertStringContainsString('Assalamu alaikum,', $codeHtml);
        $this->assertStringNotContainsString('evil', $codeHtml);
        $this->assertStringNotContainsStringIgnoringCase('http', $codeHtml);

        // Later the victim creates an account with that address. The app links
        // the record by its address and ignores the name they type.
        $this->verify($victim, $code, [
            'first_name' => 'Victoria',
            'last_name' => 'Real',
            'password' => self::GOOD,
        ])->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame($link, $planted->fresh()->first_name);

        $mail = $this->theOneNotice();
        $this->assertTrue($mail->hasTo($victim));
        $this->assertNull($mail->recipientName);
        $this->assertCarriesNothingThatOpensAnything($mail, [self::GOOD, $code]);

        [$html, $text] = $this->rendered($mail);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Assalamu alaikum,', $body);
            $this->assertStringNotContainsString('evil', $body);
        }
    }

    #[Test]
    public function the_greeting_keeps_real_names_and_drops_anything_a_mail_app_could_turn_into_a_link(): void
    {
        $kept = [
            'Amina' => 'Amina',
            'Abdul-Rahman' => 'Abdul-Rahman',
            "O'Neil" => "O'Neil",
            'D’Souza' => 'D’Souza',
            'Mary Ann' => 'Mary Ann',
            '  Bilal  ' => 'Bilal',
            'عائشة' => 'عائشة',
            'ʿAbd' => 'ʿAbd',
            'Zoë' => 'Zoë',
            'Mohd.' => 'Mohd.',
            'Abd. Rahman' => 'Abd. Rahman',
            // Combining marks directly after a letter: Arabic vowel marks, a
            // decomposed "Zoë", a dot below, Devanagari vowel signs.
            'مُحَمَّد' => 'مُحَمَّد',
            "Zoe\u{0308}" => "Zoe\u{0308}",
            "H\u{0323}asan" => "H\u{0323}asan",
            'प्रिया' => 'प्रिया',
        ];

        foreach ($kept as $name => $printed) {
            $this->assertSame($printed, MailGreeting::safeName($name), "A real name was dropped: {$name}");
            $this->assertSame("Assalamu alaikum {$printed},", MailGreeting::for($name));
        }

        $dropped = [
            null,
            '',
            '   ',
            'https://evil.example/secure-your-account',
            'http://x',
            'evil.example',
            'www.evil',
            'Evil.Com',
            'bob@evil.test',
            'Amina1',
            'x:y',
            'a/b',
            'ftp:evil',
            'Amina<b>',
            "Amina\nVisit",
            "Amina\u{202E}",          // right-to-left override
            "Ami\u{200B}na",          // zero-width space
            'ｅｖｉｌ．ｅｘａｍｐｌｅ', // full-width letters and dot
            str_repeat('a', MailGreeting::MAX_NAME_LENGTH + 1),
            '-Amina',
            // A zero-width mark after each dot (review finding G1). Each of
            // these printed before, and the reader saw the address.
            "www.\u{034F}evil.\u{034F}example",   // combining grapheme joiner
            "evil.\u{034F}example",
            "paypal.\u{FE0F}com",                 // variation selector-16
            "paypal.\u{FE00}com",                 // variation selector-1
            "paypal.\u{E0100}com",                // variation selector-17
            "paypal.\u{180B}com",                 // Mongolian free variation selector
            "paypal.\u{180F}com",
            "paypal.\u{17B4}com",                 // Khmer inherent vowel (invisible)
            "paypal.\u{0301}com",                 // a visible mark: no mark after a full stop
            "paypal.\u{0301}\u{0301}com",
            // Invisible letters and marks anywhere, even with no full stop.
            "Ami\u{034F}na",
            "Amina\u{FE0F}",
            "Ami\u{3164}na",                      // Hangul filler, a letter that shows nothing
            "\u{3164}",
            "\u{115F}Amina",
            "Ami\u{FFA0}na",
            "Amina\u{00AD}",                      // soft hyphen
            // Letters and marks that look like a full stop.
            "paypal\u{A4F8}com",                  // Lisu tone letter
            "paypal\u{1D16D}com",                 // combining augmentation dot
            // A mark after the space or the punctuation, never after a letter.
            "Amina \u{0301}Rahman",
            "Abd.\u{0301} Rahman",
            "Amina-\u{0301}",
            "\u{0301}Amina",
        ];

        foreach ($dropped as $name) {
            $this->assertNull(MailGreeting::safeName($name), 'Printed a name that is not a name: ' . json_encode($name));
            $this->assertSame('Assalamu alaikum,', MailGreeting::for($name));
        }

        $this->assertSame(
            str_repeat('a', MailGreeting::MAX_NAME_LENGTH),
            MailGreeting::safeName(str_repeat('a', MailGreeting::MAX_NAME_LENGTH)),
        );

        // Both mailables clean the name themselves, whoever builds them.
        $notice = new PasswordSetNoticeMail(orgName: 'Masjid An-Nur', loginEmail: 'a@test.local', setAt: 'now', recipientName: 'evil.example');
        $this->assertNull($notice->recipientName);
        $code = new FamilyLoginCodeMail(orgName: 'Masjid An-Nur', code: '000000', expiresInMinutes: 10, recipientName: 'evil.example');
        $this->assertNull($code->recipientName);
        $this->assertSame('Amina', (new FamilyLoginCodeMail(orgName: 'X', code: '000000', expiresInMinutes: 10, recipientName: 'Amina'))->recipientName);
    }

    #[Test]
    public function no_invisible_letter_or_mark_and_no_full_stop_lookalike_is_ever_printed(): void
    {
        // Checked against ICU's own Unicode data rather than MailGreeting's
        // list, so a character the list misses, now or in a later Unicode
        // version, fails here. IntlChar and Spoofchecker come with ext-intl,
        // which composer.json does not require; without it this test skips.
        if (! class_exists(\IntlChar::class) || ! class_exists(\Spoofchecker::class)) {
            $this->markTestSkipped('ext-intl is not loaded; this check needs ICU data.');
        }

        $spoof = new \Spoofchecker();
        $checked = ['invisible' => 0, 'looks like a full stop' => 0];

        for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $char = \IntlChar::chr($cp);
            if ($char === null || preg_match('/^[\p{L}\p{M}]$/u', $char) !== 1) {
                continue; // anything else already fails the shape check
            }

            if (\IntlChar::hasBinaryProperty($cp, \IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT)) {
                $kind = 'invisible';
            } elseif ($spoof->areConfusable('.', $char)) {
                $kind = 'looks like a full stop';
            } else {
                continue;
            }
            $checked[$kind]++;

            // Where each could hide: after a letter (a mark there is
            // otherwise allowed), after a full stop, and alone.
            foreach (["A{$char}b", "a.{$char}b", $char] as $name) {
                $this->assertNull(
                    MailGreeting::safeName($name),
                    sprintf('U+%04X (%s, %s) was printed in %s', $cp, \IntlChar::charName($cp), $kind, json_encode($name)),
                );
            }
        }

        // The loop must have found what the list is about (Unicode 15: 267
        // invisible letters and marks, 2 full-stop lookalikes).
        $this->assertGreaterThanOrEqual(267, $checked['invisible']);
        $this->assertGreaterThanOrEqual(2, $checked['looks like a full stop']);
    }

    // ------------------------------------------------ what it looks like

    #[Test]
    public function the_notice_is_sent_inline_never_queued(): void
    {
        $this->assertFalse(is_subclass_of(PasswordSetNoticeMail::class, ShouldQueue::class));

        $parent = $this->portalParent('inline.send@test.local');
        $this->withToken($this->familyToken($parent))
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk();

        Mail::assertSent(PasswordSetNoticeMail::class, 1);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_notice_says_which_organisation_and_when_in_its_timezone(): void
    {
        $this->masjid->forceFill(['timezone' => 'America/Toronto', 'email' => 'office.annur@test.local'])->save();
        $this->travelTo(Carbon::parse('2026-09-17 19:04:00', 'UTC'));

        $parent = $this->portalParent('toronto.time@test.local');
        $this->withToken($this->familyToken($parent))
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk();

        $mail = $this->theOneNotice();

        // From the organisation by name, at the configured sending address;
        // replies go to the organisation.
        $this->assertTrue($mail->hasFrom(config('mail.from.address'), 'Masjid An-Nur'));
        $this->assertTrue($mail->hasReplyTo('office.annur@test.local'));

        // A generic subject: a lock screen must not say which school.
        $this->assertTrue($mail->hasSubject('Your password was set'));

        [$html, $text] = $this->rendered($mail);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Thu 17 Sep 2026, 3:04 PM EDT', $body);
            $this->assertStringContainsString('Masjid An-Nur', $body);
        }

        // An organisation with no timezone of its own says UTC, by name.
        Mail::fake();
        $other = $this->makeMasjid('Masjid Al-Huda');
        $utcParent = $this->portalParent('utc.time@test.local', [], $other);
        $this->withToken($this->familyToken($utcParent))
            ->putJson("/api/family/masjids/{$other->id}/password", [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk();

        [$utcHtml, $utcText] = $this->rendered($this->theOneNotice());
        $this->assertStringContainsString('Thu 17 Sep 2026, 7:04 PM UTC', $utcHtml);
        $this->assertStringContainsString('Thu 17 Sep 2026, 7:04 PM UTC', $utcText);
        $this->assertStringContainsString('Masjid Al-Huda', $utcText);
        $this->assertStringNotContainsString('Masjid An-Nur', $utcText);
    }

    #[Test]
    public function the_text_part_prints_an_organisation_name_as_written(): void
    {
        $this->masjid->forceFill(['name' => 'Masjid & Community Centre'])->save();

        $parent = $this->portalParent('ampersand.org@test.local');
        $this->withToken($this->familyToken($parent))
            ->putJson($this->portalPasswordUrl(), [
                'password' => self::GOOD,
                'password_confirmation' => self::GOOD,
            ])->assertOk();

        [$html, $text] = $this->rendered($this->theOneNotice());

        $this->assertStringContainsString('Masjid & Community Centre', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringContainsString('Masjid &amp; Community Centre', $html);
    }

    // ------------------------------------------------------------ helpers

    private function makeMasjid(string $name): Masjid
    {
        $this->asANewRequest();

        return Masjid::create([
            'name' => $name,
            'email' => 'office@' . strtolower(str_replace(' ', '', preg_replace('/[^A-Za-z ]/', '', $name))) . '.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /**
     * An app member as a past sign-in left it: verified, with a password only
     * when one is given.
     *
     * @param  array<string, mixed>  $extra
     */
    private function appMember(string $email, ?string $password = null, bool $verified = true, array $extra = []): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill(array_merge([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $email,
            'login_email' => $email,
            'signup_source' => 'app',
            'verified_at' => $verified ? now() : null,
            'password' => $password === null ? null : Hash::make($password),
            'password_set_at' => $password === null ? null : now(),
        ], $extra))->save();

        return $contact->refresh();
    }

    /**
     * A parent the office gave a family login, and nothing else.
     *
     * @param  array<string, mixed>  $extra
     */
    private function portalParent(string $loginEmail, array $extra = [], ?Masjid $masjid = null): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill(array_merge([
            'masjid_id' => ($masjid ?? $this->masjid)->id,
            'first_name' => 'Maryam',
            'last_name' => 'Parent',
            'email' => $loginEmail,
            'login_email' => $loginEmail,
            'login_enabled_at' => now(),
        ], $extra))->save();

        return $contact->refresh();
    }

    private function familyToken(Contact $parent): string
    {
        $token = $parent->createFamilyToken()->plainTextToken;
        $this->asANewRequest();

        return $token;
    }

    private function portalPasswordUrl(): string
    {
        return "/api/family/masjids/{$this->masjid->id}/password";
    }

    /** The newest sign-in code mailed to this address. */
    private function requestCode(string $email): string
    {
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(202);

        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo($email) && ! $mail->isForAccountDeletion(),
        )->last()?->code;

        $this->assertNotNull($code, "No sign-in code was mailed to {$email}.");

        return $code;
    }

    /** @param  array<string, mixed>  $extra */
    private function verify(string $email, string $code, array $extra = []): TestResponse
    {
        $this->asANewRequest();

        $response = $this->postJson(
            "/api/mobile/masjids/{$this->masjid->id}/auth/verify-code",
            array_merge(['email' => $email, 'code' => $code], $extra),
        );

        $this->asANewRequest();

        return $response;
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    private function codeRow(string $email): AppSignupCode
    {
        return AppSignupCode::withoutMasjidScope()->where('email', $email)->orderByDesc('id')->firstOrFail();
    }

    /** A live code row written directly, for addresses `issue()` will not mail. */
    private function mintCode(string $email): string
    {
        $code = '482915';

        $service = app(\App\Services\Member\MemberSignupService::class);
        $hash = (new \ReflectionMethod($service, 'hash'))->invoke($service, $code);

        AppSignupCode::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'email' => $email,
            'code_hash' => $hash,
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }

    private function theOneNotice(): PasswordSetNoticeMail
    {
        Mail::assertSent(PasswordSetNoticeMail::class, 1);

        return Mail::sent(PasswordSetNoticeMail::class)->sole();
    }

    /**
     * The HTML and plain-text parts exactly as they would be sent.
     *
     * @return array{0: string, 1: string}
     */
    private function rendered(PasswordSetNoticeMail $mail): array
    {
        /** @var array{0: string, 1: string} $parts */
        $parts = (fn () => $this->renderForAssertions())->call($mail);

        $this->assertNotSame('', $parts[0], 'The HTML part rendered empty.');
        $this->assertNotSame('', $parts[1], 'The text part rendered empty.');

        return $parts;
    }

    /**
     * No password, no hash, no code, no token, no link — in either part.
     *
     * @param  list<string>  $secrets  every credential that existed in this flow
     */
    private function assertCarriesNothingThatOpensAnything(PasswordSetNoticeMail $mail, array $secrets): void
    {
        [$html, $text] = $this->rendered($mail);

        foreach (['html' => $html, 'text' => $text] as $part => $body) {
            foreach (array_filter($secrets) as $secret) {
                $this->assertStringNotContainsString($secret, $body, "The {$part} part carries a credential.");
            }

            // A bcrypt/argon digest, any six-digit run that could be a code,
            // and any kind of link or click target.
            $this->assertStringNotContainsString('$2y$', $body);
            $this->assertStringNotContainsString('$argon', $body);
            $this->assertDoesNotMatchRegularExpression('/(?<!\d)\d{6}(?!\d)/', $body, "The {$part} part has a six-digit run.");
            $this->assertStringNotContainsStringIgnoringCase('http', $body);
            $this->assertStringNotContainsStringIgnoringCase('href', $body);
            $this->assertStringNotContainsStringIgnoringCase('www.', $body);
            $this->assertStringNotContainsStringIgnoringCase('<a ', $body);
            $this->assertStringNotContainsStringIgnoringCase('<img', $body);
            $this->assertStringNotContainsStringIgnoringCase('token', $body);
        }

        // Nothing credential-shaped in the mailable's own public state either.
        $state = json_encode(get_object_vars($mail));
        foreach (array_filter($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, (string) $state);
        }
    }

    /**
     * The next send throws the way a relay outage does, AFTER the address is
     * accepted, and the exception quotes the address, as transport errors can.
     */
    private function makeTheRelayFail(string $address): void
    {
        $pending = Mockery::mock();
        $pending->shouldReceive('send')
            ->once()
            ->andThrow(new TransportException("Connection refused while sending to {$address}"));

        Mail::shouldReceive('to')->once()->with($address)->andReturn($pending);
    }

    private function assertDeliveryFailureLogged(Contact $contact, string $address): void
    {
        $failures = array_values(array_filter(
            $this->logged,
            fn (MessageLogged $e) => $e->message === 'password set notice delivery failed',
        ));

        $this->assertCount(1, $failures, 'The failed send left no log line.');

        // Warning is what production records (LOG_LEVEL=warning).
        $this->assertSame('warning', $failures[0]->level);
        $this->assertSame((int) $contact->id, (int) $failures[0]->context['contact_id']);
        $this->assertSame((int) $contact->masjid_id, (int) $failures[0]->context['masjid_id']);
        $this->assertSame(TransportException::class, $failures[0]->context['exception']);

        // The exception message quoted the address; the log line must not.
        $ours = $failures[0]->message . json_encode($failures[0]->context);
        $this->assertStringNotContainsString($address, $ours, 'The failure line carries the address.');

        foreach ($this->logged as $event) {
            $logged = $event->message . json_encode($event->context);
            $this->assertStringNotContainsString(self::GOOD, $logged, 'A log line carries the password.');
        }
    }
}
