<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Member\MemberSignupService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An address SEVERAL contacts hold is nobody's to sign in with (found 2026-10-01).
 *
 * Two members of one family are often on file under one address, each with no login yet. The
 * resolver answered "no contact" for that exactly as it does for an address nobody holds, and
 * the code door then created a THIRD contact for whoever proved the mailbox: a new, empty
 * account, with none of that person's gifts or orders, whose `login_email` then won every later
 * sign-in, so merging the two originals no longer brought the history back. The door's own
 * docblock already promised the one 410 for "an address matching two contacts".
 *
 * Now `MemberSignupService::holdersOf()` tells nobody, one and several apart, and several is a
 * refused redeem: the one 410, nothing created or changed, the code spent, and a log line that
 * names the contacts' ids (never the address) so the office can merge them.
 */
class MemberSignInSharedAddressTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED = 'family@shared-address.test';

    /** Byte for byte what every refused verify-code answers. */
    private const GONE = '{"status":"error","message":"That code is no longer usable. Please request a new one.","data":{}}';

    private Masjid $masjid;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->masjid = $this->makeMasjid();

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    #[Test]
    public function a_code_for_an_address_two_contacts_share_creates_no_third_contact_even_with_a_name(): void
    {
        $one = $this->contact(null, self::SHARED);
        $two = $this->contact(null, self::SHARED);
        $before = [$this->stored($one), $this->stored($two)];
        $contacts = Contact::withoutMasjidScope()->withTrashed()->count();

        $code = $this->requestCode(self::SHARED);
        $response = $this->verify(self::SHARED, $code, [
            'first_name' => 'Amal',
            'last_name' => 'Rahman',
            'password' => 'copper-kettle-19-morning',
        ]);

        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent(), 'the same 410 as every other refused code');

        $this->assertSame($contacts, Contact::withoutMasjidScope()->withTrashed()->count(), 'no third contact');
        $this->assertSame($before, [$this->stored($one), $this->stored($two)], 'neither contact was linked, verified or given a password');
        $this->assertSame(0, $one->tokens()->count() + $two->tokens()->count(), 'nobody was signed in');
    }

    #[Test]
    public function without_a_name_it_is_the_same_410_and_never_the_new_member_name_prompt(): void
    {
        $this->contact(null, self::SHARED);
        $this->contact(null, self::SHARED);

        $code = $this->requestCode(self::SHARED);
        $response = $this->verify(self::SHARED, $code);

        // The 422 "tell us your name" would say "nobody here has this address", which is false.
        $response->assertStatus(410);
        $this->assertSame(self::GONE, $response->getContent());
    }

    #[Test]
    public function the_code_is_spent_and_the_office_is_told_which_contacts_without_the_address(): void
    {
        $one = $this->contact(null, self::SHARED);
        $two = $this->contact(null, self::SHARED);

        $code = $this->requestCode(self::SHARED);
        $this->verify(self::SHARED, $code, ['first_name' => 'Amal', 'last_name' => 'Rahman'])->assertStatus(410);

        // Spent: the same code a second time is refused before anything is resolved.
        $this->logged = [];
        $this->verify(self::SHARED, $code, ['first_name' => 'Amal', 'last_name' => 'Rahman'])->assertStatus(410);
        $this->assertSame([], $this->warnings(), 'a replayed code is refused at the gate, not as a shared address again');

        // A fresh code, so the refusal's own log line is the one read.
        $this->logged = [];
        $this->verify(self::SHARED, $this->requestCode(self::SHARED))->assertStatus(410);

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame($this->masjid->id, $warnings[0]->context['masjid_id']);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $warnings[0]->context['contact_ids']);

        foreach ($this->logged as $event) {
            $this->assertStringNotContainsString('shared-address', $event->message . json_encode($event->context), 'an address reached the log');
        }
    }

    #[Test]
    public function once_the_office_has_merged_them_the_one_contact_signs_in_with_its_history(): void
    {
        $kept = $this->contact(null, self::SHARED);
        $duplicate = $this->contact(null, self::SHARED);

        $this->verify(self::SHARED, $this->requestCode(self::SHARED))->assertStatus(410);

        // What the office's merge leaves: one live contact at the address.
        $duplicate->delete();

        $response = $this->verify(self::SHARED, $this->requestCode(self::SHARED));

        $response->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame($kept->id, $response->json('data.contact.id'), 'the person lands on their own record');
        $this->assertSame(self::SHARED, $this->stored($kept)['login_email']);
    }

    #[Test]
    public function a_contact_that_already_signs_in_with_the_address_is_not_made_ambiguous_by_office_duplicates(): void
    {
        // `login_email` comes first: the member who already signs in with the address keeps doing so,
        // whatever other contacts carry it in the office's `email` column.
        $member = $this->contact(self::SHARED, self::SHARED);
        $this->contact(null, self::SHARED);
        $this->contact(null, self::SHARED);

        $response = $this->verify(self::SHARED, $this->requestCode(self::SHARED));

        $response->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame($member->id, $response->json('data.contact.id'));
    }

    #[Test]
    public function an_address_nobody_holds_still_makes_a_new_member(): void
    {
        $this->contact(null, 'someone-else@shared-address.test');

        $response = $this->verify(self::SHARED, $this->requestCode(self::SHARED), ['first_name' => 'Amal', 'last_name' => 'Rahman']);

        $response->assertOk()->assertJsonPath('data.created', true);
        $this->assertSame(self::SHARED, $response->json('data.contact.login_email'));
    }

    #[Test]
    public function the_resolver_tells_nobody_one_and_several_apart(): void
    {
        $service = app(MemberSignupService::class);
        app(TenantContext::class)->set($this->masjid->id);

        $none = $service->holdersOf(self::SHARED);
        $this->assertTrue($none->isNone());
        $this->assertFalse($none->isOne());
        $this->assertFalse($none->isSeveral());
        $this->assertNull($none->contact);

        $first = $this->contact(null, self::SHARED);
        app(TenantContext::class)->set($this->masjid->id);
        $one = $service->holdersOf(self::SHARED);
        $this->assertTrue($one->isOne());
        $this->assertFalse($one->isSeveral());
        $this->assertSame($first->id, $one->contact->id);

        $second = $this->contact(null, self::SHARED);
        app(TenantContext::class)->set($this->masjid->id);
        $several = $service->holdersOf(self::SHARED);
        $this->assertTrue($several->isSeveral());
        $this->assertFalse($several->isNone());
        $this->assertNull($several->contact, 'several is never a contact');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $several->contactIds);

        // Another organisation's contacts at the same address never count here.
        $other = $this->makeMasjid();
        app(TenantContext::class)->set($other->id);
        $this->assertTrue($service->holdersOf(self::SHARED)->isNone());
    }

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(): Masjid
    {
        $this->asANewRequest();

        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@shared-address.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    /** A real request clears the guard cache and the tenant; so must a test between requests. */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** A contact as the office left it: `$loginEmail` null is a contact that has never signed in. */
    private function contact(?string $loginEmail, ?string $officeEmail): Contact
    {
        $this->asANewRequest();

        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $officeEmail,
            'login_email' => $loginEmail,
            'verified_at' => $loginEmail === null ? null : now(),
        ])->save();

        return $contact->refresh();
    }

    /** @return array<string, mixed> */
    private function stored(Contact $contact): array
    {
        return Contact::withoutMasjidScope()->withTrashed()->whereKey($contact->getKey())->firstOrFail()->getAttributes();
    }

    /** @return list<MessageLogged> */
    private function warnings(): array
    {
        return array_values(array_filter(
            $this->logged,
            fn (MessageLogged $event) => $event->level === 'warning' && str_contains($event->message, 'several contacts hold the address'),
        ));
    }

    private function requestCode(string $typed): string
    {
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$this->masjid->id}/auth/request-code", ['email' => $typed])
            ->assertStatus(202);

        $code = Mail::sent(
            FamilyLoginCodeMail::class,
            fn (FamilyLoginCodeMail $mail) => $mail->hasTo(mb_strtolower($typed)) && ! $mail->isForAccountDeletion(),
        )->last()?->code;

        $this->assertNotNull($code, 'No sign-in code was mailed.');

        return $code;
    }

    /** @param  array<string, mixed>  $extra */
    private function verify(string $email, string $code, array $extra = []): TestResponse
    {
        $this->asANewRequest();

        return $this->postJson(
            "/api/mobile/masjids/{$this->masjid->id}/auth/verify-code",
            array_merge(['email' => $email, 'code' => $code], $extra),
        );
    }
}
