<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\Masjid;
use App\Support\FormNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A registration nobody is told about is only half-accepted.
 *
 * These tests cover the two things that decide whether that promise holds: that mail
 * actually goes out on a real submission, and that mail failing can never take the
 * registration with it.
 *
 * The privacy assertions are deliberately negative — they name the exact free-text answers
 * (allergies, medications) that must not appear in an inbox. A future change that widens
 * what the email carries fails here rather than in someone's mailbox.
 */
class FormNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjid = $this->makeMasjid();
        $this->form = $this->makeForm($this->masjid);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Burlington Masjid',
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    private function makeForm(Masjid $masjid, array $settingOverrides = []): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'camp-' . uniqid(),
            'name' => 'Burlington Masjid Camp 2026',
            'schema' => [
                'sections' => [
                    [
                        'id' => 'registrant',
                        'title' => 'Your Information',
                        'fields' => [
                            ['name' => 'registrantFirstName', 'label' => 'First name', 'type' => 'text', 'required' => true],
                            ['name' => 'registrantLastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                            ['name' => 'registrantEmail', 'label' => 'Email', 'type' => 'email', 'required' => true],
                            ['name' => 'registrantPhone', 'label' => 'Phone', 'type' => 'tel', 'required' => true],
                        ],
                    ],
                    [
                        'id' => 'attendees',
                        'title' => 'Who Is Attending?',
                        'repeatable' => true,
                        'minEntries' => 1,
                        'maxEntries' => 6,
                        'fields' => [
                            ['name' => 'firstName', 'label' => 'First name', 'type' => 'text', 'required' => true],
                            ['name' => 'lastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                            ['name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true, 'min' => 7],
                            [
                                'name' => 'group', 'label' => 'Program track', 'type' => 'select', 'required' => true,
                                'options' => [
                                    ['value' => 'brothers', 'label' => 'Brothers'],
                                    ['value' => 'sisters', 'label' => 'Sisters'],
                                ],
                            ],
                            ['name' => 'allergies', 'label' => 'Allergies', 'type' => 'textarea'],
                            ['name' => 'medications', 'label' => 'Current medications', 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
            'settings' => array_merge([
                'identity' => [
                    'name' => ['registrantFirstName', 'registrantLastName'],
                    'email' => 'registrantEmail',
                    'phone' => 'registrantPhone',
                ],
                'fee' => [
                    'currency' => 'USD',
                    'perEntryOfSection' => 'attendees',
                    'amount' => 140,
                    'tiers' => [
                        ['label' => 'Early bird', 'amount' => 100, 'until' => '2099-01-01'],
                        ['label' => 'Day of camp', 'amount' => 140],
                    ],
                ],
                'notifyEmails' => ['coordinator@example.com'],
                'successNextSteps' => ['Complete your payment.'],
                'paymentNote' => 'Card payments on the masjid terminal carry a 3% service charge.',
            ], $settingOverrides),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'data' => [
                'registrantFirstName' => 'Moneeb',
                'registrantLastName' => 'Sayed',
                'registrantEmail' => 'moneeb@example.com',
                'registrantPhone' => '336-555-0100',
                'attendees' => [
                    [
                        'firstName' => 'Moneeb', 'lastName' => 'Sayed', 'age' => 29, 'group' => 'brothers',
                        'allergies' => 'Severe peanut allergy, carries an EpiPen',
                        'medications' => 'Albuterol inhaler',
                    ],
                    [
                        'firstName' => 'Amal', 'lastName' => 'Khdour', 'age' => 55, 'group' => 'sisters',
                    ],
                ],
            ],
        ], $overrides);
    }

    private function submit(array $payload = null, ?Form $form = null)
    {
        $form = $form ?: $this->form;

        return $this->postJson(
            "/api/v1/forms/{$form->id}/responses",
            $payload ?: $this->payload(),
            ['masjid-id' => (string) $form->masjid_id]
        );
    }

    // ------------------------------------------------------------------ it sends

    #[Test]
    public function it_emails_the_coordinators_when_a_registration_lands(): void
    {
        Mail::fake();

        $this->submit()->assertOk();

        Mail::assertQueued(FormResponseSubmitted::class, function ($mail) {
            return $mail->hasTo('coordinator@example.com')
                && $mail->registrantName === 'Moneeb Sayed'
                && $mail->entryCount === 2
                && $mail->amountLine === '$200.00'
                && $mail->tierLabel === 'Early bird';
        });
    }

    #[Test]
    public function it_emails_a_receipt_back_to_whoever_registered(): void
    {
        Mail::fake();

        $this->submit()->assertOk();

        Mail::assertQueued(FormSubmissionReceipt::class, function ($mail) {
            return $mail->hasTo('moneeb@example.com')
                && $mail->amountLine === '$200.00'
                && $mail->paymentNote !== null;
        });
    }

    #[Test]
    public function the_receipt_states_the_amount_stored_on_the_response(): void
    {
        Mail::fake();

        $this->submit()->assertOk();

        // The stored total is what the person agreed to owe. If the email were to
        // recompute it, a price step would silently restate somebody's bill — so assert
        // the two are the same number rather than that each is $200 by coincidence.
        $stored = (float) $this->form->responses()->firstOrFail()->amount_due;

        Mail::assertQueued(
            FormSubmissionReceipt::class,
            fn ($mail) => $mail->amountLine === '$' . number_format($stored, 2)
        );
    }

    #[Test]
    public function a_form_that_charges_nothing_shows_no_total(): void
    {
        Mail::fake();

        $form = $this->makeForm($this->makeMasjid(), ['fee' => null]);

        $this->submit(null, $form)->assertOk();

        Mail::assertQueued(FormSubmissionReceipt::class, fn ($mail) => $mail->amountLine === null);
        Mail::assertQueued(FormResponseSubmitted::class, fn ($mail) => $mail->amountLine === null);
    }

    // ------------------------------------------------------------------ what it withholds

    #[Test]
    public function the_coordinator_email_carries_names_and_choices_but_never_health_details(): void
    {
        $this->submit()->assertOk();

        $body = $this->renderedMail(FormResponseSubmitted::class);

        // Present: who is coming, and the structured answers a coordinator plans around.
        $this->assertStringContainsString('Moneeb Sayed', $body);
        $this->assertStringContainsString('Amal Khdour', $body);
        $this->assertStringContainsString('Brothers', $body);
        $this->assertStringContainsString('Age 29', $body);
        $this->assertStringContainsString('$200.00', $body);

        // Absent: free text, which is where health details live.
        $this->assertStringNotContainsString('peanut', strtolower($body));
        $this->assertStringNotContainsString('EpiPen', $body);
        $this->assertStringNotContainsString('Albuterol', $body);
        $this->assertStringNotContainsString('inhaler', strtolower($body));
    }

    #[Test]
    public function the_people_summary_drops_free_text_answers(): void
    {
        $this->submit()->assertOk();

        $people = FormNotifier::people($this->form, $this->form->responses()->firstOrFail());

        $this->assertCount(2, $people);
        $this->assertSame('Moneeb Sayed', $people[0]['name']);
        $this->assertStringContainsString('Age 29', $people[0]['detail']);
        $this->assertStringNotContainsString('EpiPen', $people[0]['detail']);
    }

    // ------------------------------------------------------------------ recipients

    #[Test]
    public function it_falls_back_to_the_masjid_address_when_no_coordinators_are_configured(): void
    {
        $masjid = $this->makeMasjid();
        $form = $this->makeForm($masjid, ['notifyEmails' => []]);

        $this->assertSame(
            [strtolower($masjid->email)],
            FormNotifier::coordinatorRecipients($form, $masjid)
        );
    }

    #[Test]
    public function it_accepts_a_comma_separated_list_and_discards_what_is_not_an_address(): void
    {
        $masjid = $this->makeMasjid();
        $form = $this->makeForm($masjid, [
            'notifyEmails' => 'Amal@Example.com, not-an-address, kassim@example.com',
        ]);

        $this->assertSame(
            ['amal@example.com', 'kassim@example.com'],
            FormNotifier::coordinatorRecipients($form, $masjid)
        );
    }

    #[Test]
    public function a_form_can_switch_the_submitter_receipt_off(): void
    {
        Mail::fake();

        $form = $this->makeForm($this->makeMasjid(), ['confirmationEmail' => false]);

        $this->submit(null, $form)->assertOk();

        Mail::assertQueued(FormResponseSubmitted::class);
        Mail::assertNotQueued(FormSubmissionReceipt::class);
    }

    // ------------------------------------------------------------------ nothing sends

    #[Test]
    public function a_honeypot_submission_writes_nothing_and_notifies_nobody(): void
    {
        Mail::fake();

        $this->submit($this->payload(['website' => 'http://spam.example']))->assertOk();

        $this->assertSame(0, $this->form->responses()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_rejected_submission_notifies_nobody(): void
    {
        Mail::fake();

        $this->submit(['data' => ['registrantFirstName' => 'Moneeb']])->assertStatus(422);

        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------------------ mail must not cost a registration

    #[Test]
    public function the_registration_survives_the_mailer_throwing(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));
        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->submit()->assertOk();

        // The thing that actually matters: the row is there, and the person was told yes.
        $response = $this->form->responses()->firstOrFail();
        $this->assertSame('Moneeb Sayed', $response->respondent_name);
        $this->assertEquals(200, (float) $response->amount_due);
    }

    /** The rendered HTML of the first mail of the given class, via the array transport. */
    private function renderedMail(string $class): string
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'No mail was sent.');

        foreach ($messages as $message) {
            $body = $message->getOriginalMessage()->getHtmlBody() ?? '';

            // The coordinator mail is the one linking into the admin panel.
            $isCoordinator = str_contains($body, 'Open Form Responses');

            if ($class === FormResponseSubmitted::class ? $isCoordinator : ! $isCoordinator) {
                return $body;
            }
        }

        $this->fail("No {$class} message was sent.");
    }
}
