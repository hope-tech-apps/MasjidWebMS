<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-form notification recipients, from the builder's Save to the actual envelope.
 *
 * The client asked for the vendor-booth form's responses to reach the office rather than
 * the general address (2026-09-22). `settings.notifyEmails` already did that; what these
 * tests pin is the half that nothing else covered — that a list SAVED THROUGH THE ADMIN
 * ENDPOINT is the list a real submission is actually delivered to.
 *
 * Why the endpoint and not a hand-built Form row: FormNotificationTest already proves
 * FormNotifier::coordinatorRecipients() picks the right addresses out of a settings array,
 * and it builds that array with Form::create(). That leaves the save untested, which is
 * exactly where a write-only field hides — `settings` is validated key by key and
 * `validated()` DROPS any key without a rule, so a recipients list could be accepted with
 * a 201, stored nowhere, and every status assertion would still pass while the office
 * heard nothing. So each test here saves the way the builder saves, submits the way a
 * family submits, and reads the ADDRESSES OFF THE MAILABLE.
 *
 * Every assertion is on the full recipient set rather than on one address being present.
 * "Also mailed the general address" and "mailed only the office" are different outcomes
 * to the person who asked for this, and hasTo() alone cannot tell them apart.
 */
class FormNotifyRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        $this->masjid = Masjid::create([
            'name' => 'Vendor Org ' . uniqid(),
            'email' => 'general-office@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    // ------------------------------------------------------------------ helpers

    /** The document the builder PUTs. `notifyEmails` is absent unless a test sets it. */
    private function document(array $settings = []): array
    {
        return [
            'slug' => 'vendor-booth-' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Vendor Booth Application',
            'schema' => ['sections' => [[
                'id' => 'vendor',
                'title' => 'Your Booth',
                'fields' => [
                    ['name' => 'vendorName', 'label' => 'Business name', 'type' => 'text', 'required' => true],
                    ['name' => 'vendorEmail', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ],
            ]]],
            'settings' => array_merge([
                'identity' => ['name' => 'vendorName', 'email' => 'vendorEmail'],
            ], $settings),
        ];
    }

    private function createForm(array $settings = []): Form
    {
        $response = $this->postJson("/api/admin/masjids/{$this->masjid->id}/forms", $this->document($settings));

        $response->assertCreated();

        return Form::findOrFail($response->json('data.id') ?? $response->json('id'));
    }

    /** Re-saving the whole document is how the builder updates: buildPayload() sends all of it. */
    private function saveForm(Form $form, array $settings = []): void
    {
        $document = $this->document($settings);
        $document['slug'] = $form->slug;

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", $document)->assertOk();
    }

    private function submit(Form $form)
    {
        return $this->postJson(
            "/api/v1/forms/{$form->id}/responses",
            ['data' => ['vendorName' => 'Najd Spices', 'vendorEmail' => 'vendor@example.com']],
            ['masjid-id' => (string) $form->masjid_id]
        );
    }

    /**
     * Who the coordinators' mail was addressed to, sorted.
     *
     * Reads the envelope rather than asking FormNotifier again: a test that re-derives the
     * expected recipients from the same function it is checking would pass on a form whose
     * settings never saved.
     *
     * @return array<int,string>
     */
    private function coordinatorRecipientsOf(): array
    {
        $addresses = null;

        Mail::assertQueued(FormResponseSubmitted::class, function (FormResponseSubmitted $mail) use (&$addresses) {
            $addresses = collect($mail->to)->pluck('address')->sort()->values()->all();

            return true;
        });

        return $addresses ?? [];
    }

    // ------------------------------------------------------------------ the tests

    #[Test]
    public function a_form_saved_with_recipients_notifies_exactly_those_addresses(): void
    {
        Mail::fake();

        $form = $this->createForm(['notifyEmails' => ['najd@office.test', 'events@office.test']]);

        // The save has to have STORED them; a 201 alone would not say so.
        $this->assertSame(
            ['najd@office.test', 'events@office.test'],
            $form->fresh()->settings['notifyEmails']
        );

        $this->submit($form)->assertOk();

        $this->assertSame(
            ['events@office.test', 'najd@office.test'],
            $this->coordinatorRecipientsOf()
        );
    }

    #[Test]
    public function the_saved_recipients_replace_the_masjid_address_rather_than_adding_to_it(): void
    {
        Mail::fake();

        $form = $this->createForm(['notifyEmails' => ['najd@office.test']]);

        $this->submit($form)->assertOk();

        // The help text under the field promises "instead of, not as well as". If the
        // general address were still copied, the whole request would be unmet while every
        // hasTo('najd@office.test') assertion carried on passing.
        $this->assertSame(['najd@office.test'], $this->coordinatorRecipientsOf());
        $this->assertNotContains($this->masjid->email, $this->coordinatorRecipientsOf());
    }

    #[Test]
    public function a_form_saved_with_no_recipients_falls_back_to_the_masjid_address(): void
    {
        Mail::fake();

        $form = $this->createForm();

        $this->assertArrayNotHasKey('notifyEmails', $form->fresh()->settings ?? []);

        $this->submit($form)->assertOk();

        $this->assertSame([strtolower($this->masjid->email)], $this->coordinatorRecipientsOf());
    }

    #[Test]
    public function clearing_the_recipients_returns_the_form_to_the_masjid_address(): void
    {
        $form = $this->createForm(['notifyEmails' => ['najd@office.test']]);

        // What the builder sends once the last row is removed: buildPayload() omits the key
        // entirely rather than sending [], and `notifyEmails` is a MANAGED key, so it is not
        // carried over from the loaded settings either. If either of those changed, the old
        // list would survive the clear and this form would keep mailing someone the admin
        // believes they removed.
        $this->saveForm($form);

        $this->assertArrayNotHasKey('notifyEmails', $form->fresh()->settings ?? []);

        Mail::fake();
        $this->submit($form)->assertOk();

        $recipients = $this->coordinatorRecipientsOf();

        $this->assertSame([strtolower($this->masjid->email)], $recipients);
        $this->assertNotContains('najd@office.test', $recipients);
    }

    #[Test]
    public function recipients_survive_a_save_that_does_not_touch_them(): void
    {
        $form = $this->createForm(['notifyEmails' => ['najd@office.test']]);

        // Reload-and-save with the list unchanged, the way an admin editing the wording
        // would: the round trip must not quietly drop it.
        $this->saveForm($form, ['notifyEmails' => ['najd@office.test']]);

        Mail::fake();
        $this->submit($form)->assertOk();

        $this->assertSame(['najd@office.test'], $this->coordinatorRecipientsOf());
    }

    #[Test]
    public function the_endpoint_refuses_what_the_builder_refuses(): void
    {
        // The browser-side checks in FormBuilder.vue mirror these two rules. Pinning them
        // here is what stops those messages inventing a refusal the server does not make,
        // or missing one it does.
        //
        // Not assertJsonValidationErrors(): this API answers a 422 with its own envelope,
        // { status: 'failed', data: { '<dotted key>': [message] } }, not Laravel's
        // { message, errors }. That flat dotted key IS the contract the builder reads —
        // serverFieldErrors() keys serverFieldErrorsByKey by it and fieldIssue() looks the
        // row up by the same string — so the key is what the assertion is about.
        $badAddress = $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/forms",
            $this->document(['notifyEmails' => ['najd@office.test', 'not-an-address']])
        )->assertStatus(422);

        $this->assertArrayHasKey('settings.notifyEmails.1', $badAddress->json('data'));

        // Row 0 was fine, so nothing should be reported against it.
        $this->assertArrayNotHasKey('settings.notifyEmails.0', $badAddress->json('data'));

        $eleven = array_map(fn ($n) => "coordinator{$n}@office.test", range(1, 11));

        $tooMany = $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/forms",
            $this->document(['notifyEmails' => $eleven])
        )->assertStatus(422);

        // Keyed at the list, not a row — which is why the builder renders the cap message
        // under the Add button rather than beside an address.
        $this->assertArrayHasKey('settings.notifyEmails', $tooMany->json('data'));

        // Ten is accepted, so the builder's cap is the rule's cap and not one short of it.
        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/forms",
            $this->document(['notifyEmails' => array_slice($eleven, 0, 10)])
        )->assertCreated();

        // No length bound of its own lives in these rules — `email:` carries it. Under
        // `email:rfc` alone a 100,012-character address validated and was written whole
        // into the settings json column, then handed to Mail::to(). The refusal is keyed
        // at the ROW, which is what lets the builder say so beside the address.
        $tooLong = $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/forms",
            $this->document(['notifyEmails' => [str_repeat('a', 100000) . '@example.com']])
        )->assertStatus(422);

        $this->assertArrayHasKey('settings.notifyEmails.0', $tooLong->json('data'));

        // And nothing ordinary is caught by it: a real office address is long.
        $long = 'registrations.vendor-booth@fall-festival.masjid-example.org';

        $this->assertSame([$long], $this->createForm(['notifyEmails' => [$long]])->fresh()->settings['notifyEmails']);
    }

    #[Test]
    public function an_address_that_could_never_be_delivered_is_refused_rather_than_stored(): void
    {
        // THE SAVE AND THE SEND HAVE TO AGREE ABOUT WHAT AN ADDRESS IS.
        //
        // `email:rfc` accepts `office@intranet`; FILTER_VALIDATE_EMAIL, which is what
        // coordinatorRecipients() screens with, does not. Saved under the rfc-only rule
        // this form stored the office, showed it in the builder, dropped it at send time
        // and fell back to the masjid's general address — the whole point of the feature
        // undone, with a 201 and a filled-in field saying it had worked.
        $refused = $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/forms",
            $this->document(['notifyEmails' => ['office@intranet']])
        )->assertStatus(422);

        $this->assertArrayHasKey('settings.notifyEmails.0', $refused->json('data'));

        // The send-time half, which is WHY the rule reads `email:rfc,filter`: a row that
        // reached the column another way — a seeded template, a hand-edited settings blob
        // — still silently falls back, and only the save-time refusal can prevent that.
        Mail::fake();

        $form = $this->createForm(['notifyEmails' => ['najd@office.test']]);
        $form->forceFill(['settings' => array_merge($form->settings, [
            'notifyEmails' => ['office@intranet'],
        ])])->save();

        $this->submit($form)->assertOk();

        $recipients = $this->coordinatorRecipientsOf();

        $this->assertSame([strtolower($this->masjid->email)], $recipients);
        $this->assertNotContains('office@intranet', $recipients);
    }

    #[Test]
    public function recipients_are_lowercased_before_delivery(): void
    {
        Mail::fake();

        // The builder keeps the admin's own capitalisation in the field; FormNotifier
        // lowercases on the way out. Both are true at once, so assert each where it applies.
        $form = $this->createForm(['notifyEmails' => ['Najd@Office.test']]);

        $this->assertSame(['Najd@Office.test'], $form->fresh()->settings['notifyEmails']);

        $this->submit($form)->assertOk();

        $this->assertSame(['najd@office.test'], $this->coordinatorRecipientsOf());
    }
}
