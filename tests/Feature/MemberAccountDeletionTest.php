<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\AppSignupCode;
use App\Models\AppointmentRequest;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\ContactServiceInterest;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Service;
use App\Services\Member\MemberAccountDeletion;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Delete account" from the app: `DELETE /api/mobile/masjids/{id}/me`, and the
 * sign-out release `DELETE .../me/device` that moved out of `crm` with it.
 *
 * The owner's rule (2026-09-14) is "remove the login, keep office records", so
 * most of this file is about the line between the two: a contact the app created
 * with nothing on file is erased, and a contact the office knows about in ANY way
 * keeps its record and loses only the login. Every case asserts the parts that
 * always happen too (tokens, handsets, interests, codes), because a deletion that
 * kept the record and forgot to release the phone would still send that person's
 * notifications to whoever holds it next.
 */
class MemberAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    // ------------------------------------------------------------ the erase path

    #[Test]
    public function a_member_the_app_created_with_nothing_on_file_is_erased(): void
    {
        Mail::fake();

        $masjid = $this->makeMasjid();
        $email = 'new-' . uniqid() . '@test.local';

        // Signed up through the real doors, so "app sign-up created it" is judged
        // on the columns sign-up actually writes rather than on a fixture.
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(202);
        $code = $this->lastCodeSentTo($email);

        $this->asANewRequest();
        $token = $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/verify-code", [
            'email' => $email,
            'code' => $code,
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
        ])->assertOk()->assertJsonPath('data.created', true)->json('data.token');

        $this->asANewRequest();
        $member = Contact::withoutMasjidScope()->where('login_email', $email)->firstOrFail();

        $phone = $this->device($masjid, $member);
        $tablet = $this->device($masjid, $member);
        $neighbour = $this->appMember($masjid);
        $neighboursPhone = $this->device($masjid, $neighbour);
        $this->interest($masjid, $member);

        // A second sign-in code, requested and never used.
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(202);
        $this->asANewRequest();

        $response = $this->deleteAccount($masjid, $token);

        $response->assertOk();
        $this->assertSame('{"status":"success","data":{}}', $response->getContent());

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($member->id));
        $this->assertSame(0, $this->tokenCount($member));

        // Released, not deleted: the handsets still exist and still get
        // broadcasts to everyone. A neighbour's phone is not touched.
        $this->assertNull($phone->fresh()->contact_id);
        $this->assertNull($tablet->fresh()->contact_id);
        $this->assertSame((int) $neighbour->id, (int) $neighboursPhone->fresh()->contact_id);

        $this->assertSame(0, ContactServiceInterest::withoutMasjidScope()->where('contact_id', $member->id)->count());
        $this->assertSame(0, AppSignupCode::withoutMasjidScope()->where('email', $email)->count());

        $log = $this->deletionLog();
        $this->assertNotNull($log, 'No warning was logged, and production runs LOG_LEVEL=warning.');
        $this->assertSame('warning', $log->level);
        $this->assertSame(MemberAccountDeletion::OUTCOME_ERASED, $log->context['outcome']);
        $this->assertSame(MemberAccountDeletion::VIA_APP, $log->context['via']);
        $this->assertSame([], $log->context['kept_because']);
        $this->assertSame(2, $log->context['devices_released']);
        $this->assertSame(1, $log->context['tokens_revoked']);
        $this->assertSame(1, $log->context['interests_removed']);
        $this->assertStringNotContainsString($email, (string) json_encode($log->context));

        // The token died with the account, and the refusal still decodes.
        $again = $this->deleteAccount($masjid, $token);
        $again->assertStatus(401);
        $this->assertStringContainsString('"data":{}', $again->getContent());
    }

    // ------------------------------------------------------------- the keep path

    /** @return array<string, array{0: string, 1: string}> */
    public static function officeRecords(): array
    {
        return [
            'the office created the contact' => ['staff_authored', 'contacts.signup_source'],
            'the office added a phone number' => ['phone', 'contacts.phone'],
            'the office wrote a note' => ['notes', 'contacts.notes'],
            'the office corrected the email' => ['email_edited', 'contacts.email'],
            'a gift' => ['donation', 'donations'],
            'a place in a class' => ['class_member', 'group_memberships'],
            'a guardian of a child' => ['guardian', 'group_memberships'],
            'a form response from the same address' => ['form_response', 'form_responses'],
            'an appointment request from the same address' => ['appointment_request', 'appointment_requests'],
            'a family login the office turned on' => ['family_login', 'contacts.login_enabled_at'],
        ];
    }

    #[Test]
    #[DataProvider('officeRecords')]
    public function a_contact_the_office_knows_is_kept_and_only_the_login_goes(string $record, string $reason): void
    {
        $masjid = $this->makeMasjid();
        $member = $this->appMember($masjid);

        $this->plant($record, $masjid, $member);

        $this->asANewRequest();
        $member->refresh();

        $token = $this->tokenFor($member);
        $phone = $this->device($masjid, $member);
        $this->interest($masjid, $member);

        $response = $this->deleteAccount($masjid, $token);

        $response->assertOk();
        $this->assertSame('{"status":"success","data":{}}', $response->getContent());

        $kept = Contact::withoutMasjidScope()->find($member->id);
        $this->assertNotNull($kept, "The office's record was deleted ({$record}).");
        $this->assertNull($kept->verified_at);
        $this->assertNull($kept->login_enabled_at);
        // Not revoked: that is the office cutting someone off, and it would stop
        // this person from ever signing up again.
        $this->assertNull($kept->login_revoked_at);
        // What the office wrote is exactly as it was.
        $this->assertSame($member->first_name, $kept->first_name);
        $this->assertSame($member->phone, $kept->phone);
        $this->assertSame($member->notes, $kept->notes);
        $this->assertSame($member->email, $kept->email);

        $this->assertSame(0, $this->tokenCount($member));
        $this->assertNull($phone->fresh()->contact_id);
        $this->assertSame(0, ContactServiceInterest::withoutMasjidScope()->where('contact_id', $member->id)->count());

        $log = $this->deletionLog();
        $this->assertNotNull($log);
        $this->assertSame(MemberAccountDeletion::OUTCOME_LOGIN_REMOVED, $log->context['outcome']);
        $this->assertContains($reason, $log->context['kept_because']);

        $this->deleteAccount($masjid, $token)->assertStatus(401);
    }

    #[Test]
    public function ending_a_family_login_is_recorded_and_its_password_goes_with_it(): void
    {
        $masjid = $this->makeMasjid();
        $member = $this->appMember($masjid);
        $this->plant('family_login', $masjid, $member);
        $this->asANewRequest();

        $this->deleteAccount($masjid, $this->tokenFor($member->refresh()))->assertOk();

        $kept = Contact::withoutMasjidScope()->findOrFail($member->id);
        $this->assertNull($kept->getRawOriginal('password'));
        $this->assertNull($kept->password_set_at);

        $event = ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $member->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'The access-history panel was not told the login ended.');
        $this->assertSame(ContactLoginEvent::ACTION_REVOKED, $event->action);
        $this->assertSame($member->login_email, $event->login_email);
        // No operator did this, and the panel shows an empty actor as exactly that.
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->actor_name);
    }

    // ------------------------------------------------------------- the boundary

    #[Test]
    public function the_same_person_at_another_organisation_is_untouched(): void
    {
        $here = $this->makeMasjid();
        $there = $this->makeMasjid();
        $email = 'shared-' . uniqid() . '@test.local';

        $member = $this->appMember($here, $email);
        $twin = $this->appMember($there, $email);

        $token = $this->tokenFor($member);
        $twinsToken = $this->tokenFor($twin);
        $this->device($here, $member);
        $twinsPhone = $this->device($there, $twin);
        $this->interest($here, $member);
        $this->interest($there, $twin);

        $this->deleteAccount($here, $token)->assertOk();

        $twin->refresh();
        $this->assertNotNull($twin->verified_at);
        $this->assertSame(1, $this->tokenCount($twin));
        $this->assertSame((int) $twin->id, (int) $twinsPhone->fresh()->contact_id);
        $this->assertSame(1, ContactServiceInterest::withoutMasjidScope()->where('contact_id', $twin->id)->count());

        // And the twin's own session still works.
        $this->getJson(
            "/api/mobile/masjids/{$there->id}/interests",
            ['Authorization' => 'Bearer ' . $twinsToken],
        )->assertOk();
        $this->asANewRequest();
    }

    #[Test]
    public function an_organisation_with_its_crm_switched_off_can_still_release_a_phone_and_delete(): void
    {
        $masjid = $this->makeMasjid(crm: false);
        $member = $this->appMember($masjid);
        $token = $this->tokenFor($member);
        $phone = $this->device($masjid, $member);
        $auth = ['Authorization' => 'Bearer ' . $token];

        // Claiming a handset stays behind the CRM switch...
        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$masjid->id}/me/device", ['device_id' => $phone->device_id], $auth)
            ->assertStatus(403);
        $this->asANewRequest();

        // ...releasing one does not.
        $release = $this->deleteJson("/api/mobile/masjids/{$masjid->id}/me/device", ['device_id' => $phone->device_id], $auth);
        $this->asANewRequest();

        $release->assertOk();
        $this->assertSame('{"status":"success","data":{}}', $release->getContent());
        $this->assertNull($phone->fresh()->contact_id);

        $this->deleteAccount($masjid, $token)->assertOk();
        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($member->id));
    }

    #[Test]
    public function every_refusal_from_the_leaving_routes_carries_a_data_key(): void
    {
        $masjid = $this->makeMasjid();
        $other = $this->makeMasjid();
        $member = $this->appMember($masjid);
        $token = $this->tokenFor($member);
        $auth = ['Authorization' => 'Bearer ' . $token];

        // No token at all.
        $anonymous = $this->deleteAccount($masjid, null);
        $anonymous->assertStatus(401);
        $this->assertSame('{"status":"error","message":"Unauthenticated.","data":{}}', $anonymous->getContent());

        $anonymousRelease = $this->deleteJson("/api/mobile/masjids/{$masjid->id}/me/device", ['device_id' => 'x']);
        $this->asANewRequest();
        $anonymousRelease->assertStatus(401);
        $this->assertStringContainsString('"data":{}', $anonymousRelease->getContent());

        // A token pointed at another organisation: refused, and nothing happens.
        $foreign = $this->deleteAccount($other, $token);
        $foreign->assertStatus(403);
        $this->assertStringContainsString('"data":{}', $foreign->getContent());
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);
        $this->assertSame(1, $this->tokenCount($member));

        // A validation refusal keeps the field errors it already carries.
        $invalid = $this->deleteJson("/api/mobile/masjids/{$masjid->id}/me/device", [], $auth);
        $this->asANewRequest();
        $invalid->assertStatus(422);
        $invalid->assertJsonPath('status', 'failed');
        $this->assertArrayHasKey('device_id', (array) $invalid->json('data'));

        // A member whose app login is no longer on.
        Contact::withoutMasjidScope()->whereKey($member->id)->update(['verified_at' => null]);
        $stale = $this->deleteAccount($masjid, $token);
        $stale->assertStatus(401);
        $this->assertStringContainsString('"data":{}', $stale->getContent());

        // Routes outside the leaving group keep their bodies exactly as before.
        $interests = $this->getJson("/api/mobile/masjids/{$masjid->id}/interests");
        $this->asANewRequest();
        $interests->assertStatus(401);
        $this->assertStringNotContainsString('"data"', $interests->getContent());
    }

    #[Test]
    public function a_refused_sign_in_code_carries_a_data_key(): void
    {
        Mail::fake();

        $masjid = $this->makeMasjid();
        $email = 'late-' . uniqid() . '@test.local';

        $this->asANewRequest();
        $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(202);
        $code = $this->lastCodeSentTo($email);
        $this->asANewRequest();

        $response = $this->postJson("/api/mobile/masjids/{$masjid->id}/auth/verify-code", [
            'email' => $email,
            'code' => $code === '000000' ? '111111' : '000000',
            'first_name' => 'Late',
            'last_name' => 'Comer',
        ]);
        $this->asANewRequest();

        $response->assertStatus(410);
        $this->assertSame(
            '{"status":"error","message":"That code is no longer usable. Please request a new one.","data":{}}',
            $response->getContent(),
        );
    }

    #[Test]
    public function deleting_by_address_refuses_to_run_without_a_bound_organisation(): void
    {
        // Unbound means no tenant filter: an address lookup would search every
        // organisation in the database.
        $masjid = $this->makeMasjid();
        $member = $this->appMember($masjid);
        $this->asANewRequest();

        $this->assertNull(
            app(MemberAccountDeletion::class)->deleteByAddress((string) $member->login_email, MemberAccountDeletion::VIA_WEB)
        );
        $this->assertNotNull(Contact::withoutMasjidScope()->findOrFail($member->id)->verified_at);
    }

    // ---------------------------------------------------------------- fixtures

    private function makeMasjid(bool $crm = true): Masjid
    {
        $this->asANewRequest();

        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => $crm,
        ]);
    }

    /**
     * Requests in one test share the container, so the tenant and the guard's
     * cached user must be dropped between them, as a real request would.
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** A contact with exactly the columns MemberSignupService writes when it creates one. */
    private function appMember(Masjid $masjid, ?string $email = null): Contact
    {
        $this->asANewRequest();
        $email ??= 'member-' . uniqid() . '@test.local';

        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $masjid->id,
            'first_name' => 'App',
            'last_name' => 'Member',
            'email' => $email,
            'login_email' => $email,
            'signup_source' => 'app',
            'verified_at' => now(),
        ])->save();

        return $contact->refresh();
    }

    private function plant(string $record, Masjid $masjid, Contact $member): void
    {
        $this->asANewRequest();

        match ($record) {
            'staff_authored' => $member->forceFill(['signup_source' => null])->save(),
            'phone' => $member->forceFill(['phone' => '+17045550100'])->save(),
            'notes' => $member->forceFill(['notes' => 'Volunteers at the Friday kitchen.'])->save(),
            'email_edited' => $member->forceFill(['email' => 'office-typed-' . uniqid() . '@test.local'])->save(),
            'donation' => Donation::factory()->create([
                'masjid_id' => $masjid->id,
                'fund_id' => $this->fund($masjid)->id,
                'contact_id' => $member->id,
            ]),
            'class_member' => GroupMembership::withoutMasjidScope()->create([
                'masjid_id' => $masjid->id,
                'group_id' => Group::factory()->create(['masjid_id' => $masjid->id])->id,
                'contact_id' => $member->id,
                'role' => GroupMembership::ROLE_MEMBER,
            ]),
            'guardian' => $this->guardianEdge($masjid, $member),
            'form_response' => FormResponse::create([
                'form_id' => Form::factory()->create(['masjid_id' => $masjid->id])->id,
                'masjid_id' => $masjid->id,
                'data' => ['full_name' => 'App Member'],
                // Upper-cased on purpose: the match is on the address, not on
                // how somebody happened to type it into a form.
                'respondent_email' => strtoupper((string) $member->login_email),
                'submitted_at' => now(),
            ]),
            'appointment_request' => AppointmentRequest::factory()->create([
                'masjid_id' => $masjid->id,
                'email' => $member->login_email,
            ]),
            'family_login' => $member->forceFill([
                'login_enabled_at' => now(),
                'password' => Hash::make('a-long-family-password'),
                'password_set_at' => now(),
            ])->save(),
        };

        $this->asANewRequest();
    }

    private function fund(Masjid $masjid): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => 'General',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }

    /** The member is the GUARDIAN of a child the office has in a class. */
    private function guardianEdge(Masjid $masjid, Contact $member): GroupMembership
    {
        $group = Group::factory()->create(['masjid_id' => $masjid->id]);
        $child = Contact::factory()->create(['masjid_id' => $masjid->id, 'email' => null]);

        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        return GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $member->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);
    }

    private function tokenFor(Contact $contact): string
    {
        return $contact->createMemberToken()->plainTextToken;
    }

    private function device(Masjid $masjid, ?Contact $contact): MobileAppUser
    {
        return MobileAppUser::create([
            'masjid_id' => $masjid->id,
            'contact_id' => $contact?->id,
            'device_id' => 'device-' . uniqid('', true),
            'user_agent' => 'PHPUnit',
        ]);
    }

    private function interest(Masjid $masjid, Contact $contact): ContactServiceInterest
    {
        $this->asANewRequest();

        $service = Service::factory()->create(['masjid_id' => $masjid->id]);

        return ContactServiceInterest::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'contact_id' => $contact->id,
            'service_id' => $service->id,
        ]);
    }

    private function deleteAccount(Masjid $masjid, ?string $token)
    {
        $this->asANewRequest();

        $response = $this->deleteJson(
            "/api/mobile/masjids/{$masjid->id}/me",
            [],
            $token === null ? [] : ['Authorization' => 'Bearer ' . $token],
        );

        $this->asANewRequest();

        return $response;
    }

    private function tokenCount(Contact $contact): int
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', $contact->getMorphClass())
            ->where('tokenable_id', $contact->id)
            ->count();
    }

    private function lastCodeSentTo(string $email): string
    {
        $code = null;

        Mail::assertSent(FamilyLoginCodeMail::class, function (FamilyLoginCodeMail $mail) use (&$code, $email) {
            if ($mail->hasTo($email) && ! $mail->isForAccountDeletion()) {
                $code = $mail->code;
            }

            return true;
        });

        $this->assertNotNull($code, "No sign-in code was mailed to {$email}.");

        return $code;
    }

    private function deletionLog(): ?MessageLogged
    {
        return collect($this->logged)
            ->filter(fn (MessageLogged $event) => $event->message === 'Member account deleted')
            ->last();
    }
}
