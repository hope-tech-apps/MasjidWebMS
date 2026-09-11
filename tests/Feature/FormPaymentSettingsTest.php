<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payment half of a form's settings contract (DECISIONS.md 2026-09-11), at
 * the doors an administrator writes a form through: POST, PUT and form:import.
 *
 *  - `payment.online`, `payment.staffCodes` and `payment.allowFeeCoverage` are
 *    real booleans whatever spelling arrived — a form-encoded "true" included
 *    (.claude/rules/shipping.md) — and nonsense is refused, never read as off.
 *  - NEVER FREE BY ACCIDENT (festival brief, blocker 1): with either switch on,
 *    the fee is charged per entry of a repeatable section demanding at least one
 *    entry, every price is at least 50¢ in whole cents, and a card payment is in
 *    USD. Each refusal names its field.
 *  - A form that takes no payment keeps every shape it could always have.
 *  - The group link is a chat.whatsapp.com invite and nothing else.
 *  - `payment.eventDate`, the day staff codes end with, is a calendar date or nothing.
 *
 * FormDoorEquivalenceTest proves the three doors agree on the same documents;
 * this file proves what each refusal says and that nothing is written.
 */
class FormPaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const INVITE = 'https://chat.whatsapp.com/AbCdEf1234567890';

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
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
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

    // ------------------------------------------------------------- accepted

    #[Test]
    public function a_festival_form_that_may_take_money_is_accepted(): void
    {
        $doc = $this->doc();

        $this->postJson($this->url(), $doc)->assertStatus(201);

        $form = Form::where('slug', $doc['slug'])->firstOrFail();

        $this->assertSwitches($doc['slug'], true, true, true);
        $this->assertSame(self::INVITE, $form->whatsappUrl());
        $this->assertSame('Join the festival group', $form->settings['whatsappLabel']);
        $this->assertTrue($form->takesOnlinePayment());
        $this->assertTrue($form->takesStaffCodes());
        $this->assertTrue($form->allowsFeeCoverage());
    }

    /**
     * The stricter rules apply only once money is switched on. Every existing
     * form — Burlington's camp included — keeps every shape it could always
     * save: a flat fee, an optional list, a $0 tier, another currency.
     */
    #[Test]
    public function a_form_that_takes_no_payment_keeps_every_shape_it_could_always_have(): void
    {
        $switchedOff = [
            'no payment settings at all' => fn (&$d) => null,
            'the switches present but off' => function (&$d) {
                $d['settings']['payment'] = ['online' => false, 'staffCodes' => false, 'allowFeeCoverage' => true];
            },
        ];

        $shapes = [
            'a flat fee' => function (&$d) { unset($d['settings']['fee']['perEntryOfSection']); },
            'an attendee list that may be empty' => function (&$d) { $d['schema']['sections'][1]['minEntries'] = 0; },
            'a $0 tier' => function (&$d) { $d['settings']['fee']['tiers'][1]['amount'] = 0; },
            'Canadian dollars' => function (&$d) { $d['settings']['fee']['currency'] = 'CAD'; },
            'fractions of a cent' => function (&$d) { $d['settings']['fee']['tiers'][1]['amount'] = 25.005; },
        ];

        foreach ($switchedOff as $offLabel => $switchOff) {
            foreach ($shapes as $shapeLabel => $shape) {
                $doc = $this->doc(function (&$d) use ($switchOff, $shape) {
                    unset($d['settings']['payment']);
                    $switchOff($d);
                    $shape($d);
                });

                $response = $this->postJson($this->url(), $doc);

                $this->assertSame(
                    201,
                    $response->status(),
                    "{$offLabel}, {$shapeLabel}: refused — " . $response->getContent()
                );
            }
        }
    }

    /** Cash by code is not limited to dollars; only the card path is. */
    #[Test]
    public function only_card_payment_is_limited_to_us_dollars(): void
    {
        $doc = $this->doc(function (&$d) {
            $d['settings']['fee']['currency'] = 'CAD';
            $d['settings']['payment'] = ['staffCodes' => true];
        });

        $this->postJson($this->url(), $doc)->assertStatus(201);
    }

    // -------------------------------------------------------------- refused

    #[Test]
    public function every_way_a_paying_form_could_charge_nothing_is_refused_by_name(): void
    {
        $cases = [
            'card payment on a flat fee' => [function (&$d) {
                unset($d['settings']['fee']['perEntryOfSection']);
                $d['settings']['payment'] = ['online' => true];
            }, 'settings.fee.perEntryOfSection', 'per entry of a repeatable section'],

            'staff codes on a flat fee' => [function (&$d) {
                unset($d['settings']['fee']['perEntryOfSection']);
                $d['settings']['payment'] = ['staffCodes' => true];
            }, 'settings.fee.perEntryOfSection', 'per entry of a repeatable section'],

            // FormSchema adds a `min:` rule only for minEntries > 0, so an empty
            // attendee list would post straight through and owe $0.
            'an attendee list that may be empty' => [function (&$d) {
                $d['schema']['sections'][1]['minEntries'] = 0;
            }, 'settings.fee.perEntryOfSection', 'at least one entry'],

            'an attendee list with no minimum at all' => [function (&$d) {
                unset($d['schema']['sections'][1]['minEntries']);
            }, 'settings.fee.perEntryOfSection', 'at least one entry'],

            'the fee per entry of a section that does not exist' => [function (&$d) {
                $d['settings']['fee']['perEntryOfSection'] = 'attendee';
            }, 'settings.fee.perEntryOfSection', 'is not a repeatable section'],

            'the fee per entry of a section that is not repeatable' => [function (&$d) {
                $d['settings']['fee']['perEntryOfSection'] = 'main';
            }, 'settings.fee.perEntryOfSection', 'is not a repeatable section'],

            // Not the tier in force today: EVERY tier is checked, or the form turns
            // free on the day this one takes over.
            'a $0 tier later in the schedule' => [function (&$d) {
                $d['settings']['fee']['tiers'][1]['amount'] = 0;
            }, 'settings.fee.tiers.1.amount', 'at least $0.50'],

            'a 49¢ tier' => [function (&$d) {
                $d['settings']['fee']['tiers'][0]['amount'] = 0.49;
            }, 'settings.fee.tiers.0.amount', 'at least $0.50'],

            'a flat amount under 50¢ beside the tiers' => [function (&$d) {
                $d['settings']['fee']['amount'] = 0.25;
            }, 'settings.fee.amount', 'at least $0.50'],

            'a price in fractions of a cent' => [function (&$d) {
                $d['settings']['fee']['tiers'][1]['amount'] = 25.005;
            }, 'settings.fee.tiers.1.amount', 'whole cents'],

            'no price at all' => [function (&$d) {
                $d['settings']['fee'] = ['currency' => 'USD', 'perEntryOfSection' => 'attendees'];
            }, 'settings.fee', 'needs a price'],

            'no fee settings at all' => [function (&$d) {
                unset($d['settings']['fee']);
            }, 'settings.fee', 'needs a price'],

            'card payment in Canadian dollars' => [function (&$d) {
                $d['settings']['fee']['currency'] = 'CAD';
            }, 'settings.fee.currency', 'US dollars'],

            'staff codes alone with a $0 tier' => [function (&$d) {
                $d['settings']['payment'] = ['staffCodes' => true];
                $d['settings']['fee']['tiers'][1]['amount'] = 0;
            }, 'settings.fee.tiers.1.amount', 'at least $0.50'],
        ];

        foreach ($cases as $label => [$mutate, $field, $fragment]) {
            $doc = $this->doc($mutate);
            $response = $this->postJson($this->url(), $doc);

            $this->assertSame(422, $response->status(), "{$label}: accepted — " . $response->getContent());

            $errors = $this->errors($response);

            $this->assertArrayHasKey($field, $errors, "{$label}: refused, but not on {$field} — " . json_encode($errors));
            $this->assertStringContainsString($fragment, implode(' ', (array) $errors[$field]), $label);
            $this->assertFalse(Form::where('slug', $doc['slug'])->exists(), "{$label}: a refused form was written");
        }
    }

    // ------------------------------------------------------------- transport

    #[Test]
    public function the_switches_survive_every_spelling_a_browser_sends(): void
    {
        // postJson carrying the strings a form-encoded body carries.
        $doc = $this->doc(function (&$d) {
            $d['settings']['payment'] = ['online' => 'true', 'staffCodes' => '1', 'allowFeeCoverage' => 'on'];
        });
        $this->postJson($this->url(), $doc)->assertStatus(201);
        $this->assertSwitches($doc['slug'], true, true, true);

        // "false" is a non-empty string, which PHP reads as TRUE. It must arrive as
        // OFF — and a form that is off may carry a flat fee.
        $doc = $this->doc(function (&$d) {
            unset($d['settings']['fee']['perEntryOfSection']);
            $d['settings']['payment'] = ['online' => 'false', 'staffCodes' => '0', 'allowFeeCoverage' => 'off'];
        });
        $this->postJson($this->url(), $doc)->assertStatus(201);
        $this->assertSwitches($doc['slug'], false, false, false);

        // A real form post: schema and settings as the JSON strings the builder's
        // FormData screens send, decoded in prepareForValidation.
        $doc = $this->doc(function (&$d) {
            $d['settings']['payment'] = ['online' => 'true', 'staffCodes' => 'false', 'allowFeeCoverage' => 'true'];
        });
        $this->post($this->url(), [
            'slug' => $doc['slug'],
            'name' => $doc['name'],
            'schema' => json_encode($doc['schema']),
            'settings' => json_encode($doc['settings']),
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertSwitches($doc['slug'], true, false, true);

        // …and settings as nested form fields, the other shape a form post takes.
        $doc = $this->doc(function (&$d) {
            $d['settings']['payment'] = ['online' => 'on', 'staffCodes' => '1', 'allowFeeCoverage' => '0'];
        });
        $this->post($this->url(), [
            'slug' => $doc['slug'],
            'name' => $doc['name'],
            'schema' => json_encode($doc['schema']),
            'settings' => $doc['settings'],
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertSwitches($doc['slug'], true, true, false);

        // Nonsense is refused, never quietly read as "off".
        $doc = $this->doc(function (&$d) { $d['settings']['payment']['online'] = 'maybe'; });
        $response = $this->postJson($this->url(), $doc)->assertStatus(422);
        $this->assertArrayHasKey('settings.payment.online', $this->errors($response));
        $this->assertFalse(Form::where('slug', $doc['slug'])->exists());
    }

    // ---------------------------------------------------------- partial PUT

    #[Test]
    public function a_partial_write_cannot_switch_payment_on_for_a_form_that_could_owe_nothing(): void
    {
        $doc = $this->doc(function (&$d) {
            unset($d['settings']['payment']);
            $d['schema']['sections'][1]['minEntries'] = 0;
        });
        $this->postJson($this->url(), $doc)->assertStatus(201);
        $form = Form::where('slug', $doc['slug'])->firstOrFail();

        // Settings only: the STORED schema still lets the attendee list be empty.
        $settings = $doc['settings'];
        $settings['payment'] = ['online' => 'true'];

        $response = $this->putJson($this->url($form), ['settings' => $settings])->assertStatus(422);

        $this->assertStringContainsString(
            'at least one entry',
            implode(' ', (array) ($this->errors($response)['settings.fee.perEntryOfSection'] ?? []))
        );
        $this->assertArrayNotHasKey('payment', $form->fresh()->settings);

        // Both halves in one write, with the minimum put right: accepted.
        $schema = $doc['schema'];
        $schema['sections'][1]['minEntries'] = 1;

        $this->putJson($this->url($form), ['schema' => $schema, 'settings' => $settings])->assertOk();
        $this->assertTrue($form->fresh()->takesOnlinePayment());
    }

    #[Test]
    public function a_partial_write_cannot_loosen_a_paying_form_into_a_free_one(): void
    {
        $doc = $this->doc();
        $this->postJson($this->url(), $doc)->assertStatus(201);
        $form = Form::where('slug', $doc['slug'])->firstOrFail();

        // Schema only: making the attendee list optional under a stored paying fee.
        $schema = $doc['schema'];
        $schema['sections'][1]['minEntries'] = 0;

        $this->putJson($this->url($form), ['schema' => $schema])->assertStatus(422);
        $this->assertSame(1, $form->fresh()->schema['sections'][1]['minEntries']);

        // Settings only: a $0 tier.
        $settings = $doc['settings'];
        $settings['fee']['tiers'][0]['amount'] = 0;

        $this->putJson($this->url($form), ['settings' => $settings])->assertStatus(422);
        $this->assertEquals(20, $form->fresh()->settings['fee']['tiers'][0]['amount']);

        // Switching payment OFF is always allowed (the Wix fallback), and then the
        // looser shape is too.
        $settings['payment'] = ['online' => false, 'staffCodes' => false];

        $this->putJson($this->url($form), ['settings' => $settings])->assertOk();
        $this->assertFalse($form->fresh()->takesOnlinePayment());
    }

    // ---------------------------------------------------------- form:import

    #[Test]
    public function the_importer_reads_the_switches_as_the_api_does_and_refuses_what_it_refuses(): void
    {
        $doc = $this->doc(function (&$d) {
            $d['settings']['payment'] = ['online' => 'true', 'staffCodes' => 'off', 'allowFeeCoverage' => '1'];
        });
        $this->assertSame(0, $this->import($doc));
        $this->assertSwitches($doc['slug'], true, false, true);

        $doc = $this->doc(function (&$d) { $d['schema']['sections'][1]['minEntries'] = 0; });
        $this->assertNotSame(0, $this->import($doc), 'a paying form whose attendee list may be empty');
        $this->assertFalse(Form::where('slug', $doc['slug'])->exists());

        $doc = $this->doc(function (&$d) { $d['settings']['payment']['online'] = 'maybe'; });
        $this->assertNotSame(0, $this->import($doc), 'a switch that is not a yes or a no');
        $this->assertFalse(Form::where('slug', $doc['slug'])->exists());

        $doc = $this->doc(function (&$d) { $d['settings']['whatsappUrl'] = 'https://evil.example/join'; });
        $this->assertNotSame(0, $this->import($doc), 'a group link that is not a WhatsApp invite');
        $this->assertFalse(Form::where('slug', $doc['slug'])->exists());
    }

    // ----------------------------------------------------------- event day

    #[Test]
    public function the_event_date_is_a_calendar_date_or_nothing(): void
    {
        $doc = $this->doc(function (&$d) { $d['settings']['payment']['eventDate'] = '2026-10-17'; });
        $this->postJson($this->url(), $doc)->assertStatus(201);
        $this->assertSame('2026-10-17', Form::where('slug', $doc['slug'])->firstOrFail()->eventDate());

        foreach (['17/10/2026', '2026-10-17 09:00', '2026-02-30', 'the festival', 20261017] as $bad) {
            $doc = $this->doc(function (&$d) use ($bad) { $d['settings']['payment']['eventDate'] = $bad; });
            $response = $this->postJson($this->url(), $doc);

            $this->assertSame(422, $response->status(), var_export($bad, true) . ': accepted');
            $this->assertArrayHasKey('settings.payment.eventDate', $this->errors($response), var_export($bad, true));
            $this->assertFalse(Form::where('slug', $doc['slug'])->exists());
        }

        // Left out, as on every form before it existed.
        $doc = $this->doc();
        $this->postJson($this->url(), $doc)->assertStatus(201);
        $this->assertNull(Form::where('slug', $doc['slug'])->firstOrFail()->eventDate());
    }

    // ------------------------------------------------------------- WhatsApp

    #[Test]
    public function only_a_whatsapp_invite_link_is_accepted_as_the_group_link(): void
    {
        $urls = [
            self::INVITE => true,
            'http://chat.whatsapp.com/AbCdEf1234567890' => false,
            'https://chat.whatsapp.com.evil.example/AbCdEf1234567890' => false,
            'https://evil.example/https://chat.whatsapp.com/AbCdEf1234567890' => false,
            'javascript:alert(document.cookie)' => false,
            'https://chat.whatsapp.com/short' => false,
            'https://chat.whatsapp.com/AbCdEf1234567890?utm=x' => false,
            'https://chat.whatsapp.com/AbCdEf1234567890/../../x' => false,
        ];

        foreach ($urls as $url => $accepted) {
            $doc = $this->doc(function (&$d) use ($url) { $d['settings']['whatsappUrl'] = $url; });
            $response = $this->postJson($this->url(), $doc);

            if ($accepted) {
                $this->assertSame(201, $response->status(), "{$url}: refused — " . $response->getContent());

                continue;
            }

            $this->assertSame(422, $response->status(), "{$url}: accepted");
            $this->assertArrayHasKey('settings.whatsappUrl', $this->errors($response), $url);
            $this->assertStringContainsString(
                'https://chat.whatsapp.com/',
                implode(' ', (array) $this->errors($response)['settings.whatsappUrl']),
                'the refusal says what a group link must look like'
            );
        }

        $doc = $this->doc(function (&$d) { $d['settings']['whatsappLabel'] = str_repeat('x', 81); });
        $response = $this->postJson($this->url(), $doc)->assertStatus(422);
        $this->assertArrayHasKey('settings.whatsappLabel', $this->errors($response));
    }

    // -------------------------------------------------------------- helpers

    private function url(?Form $form = null): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms" . ($form ? "/{$form->id}" : '');
    }

    /**
     * A festival form that may take money: charged per attendee, at least one
     * attendee, every tier at least 50¢, in dollars.
     */
    private function doc(?callable $mutate = null): array
    {
        $doc = [
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [
                [
                    'id' => 'main',
                    'title' => 'You',
                    'fields' => [
                        ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ],
                ],
                [
                    'id' => 'attendees',
                    'title' => 'Attendees',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'fields' => [['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
                ],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => [
                    'currency' => 'USD',
                    'perEntryOfSection' => 'attendees',
                    'tiers' => [
                        ['label' => 'Early bird', 'amount' => 20, 'until' => '2026-10-01'],
                        ['label' => 'Standard', 'amount' => 25],
                    ],
                ],
                'payment' => ['online' => true, 'staffCodes' => true, 'allowFeeCoverage' => true],
                'whatsappUrl' => self::INVITE,
                'whatsappLabel' => 'Join the festival group',
            ],
        ];

        if ($mutate) {
            $mutate($doc);
        }

        return $doc;
    }

    /** @return array<string,mixed> the field errors in BaseFormRequest's { status, data } envelope */
    private function errors(TestResponse $response): array
    {
        return (array) $response->json('data');
    }

    /** The stored switches are REAL booleans, not the strings that arrived. */
    private function assertSwitches(string $slug, bool $online, bool $staffCodes, bool $cover): void
    {
        $payment = Form::where('slug', $slug)->firstOrFail()->settings['payment'] ?? [];

        $this->assertSame(
            ['online' => $online, 'staffCodes' => $staffCodes, 'allowFeeCoverage' => $cover],
            [
                'online' => $payment['online'] ?? 'absent',
                'staffCodes' => $payment['staffCodes'] ?? 'absent',
                'allowFeeCoverage' => $payment['allowFeeCoverage'] ?? 'absent',
            ]
        );
    }

    private function import(array $doc): int
    {
        $relative = 'database/forms/__test_payment_settings.json';
        file_put_contents(base_path($relative), json_encode($doc));

        try {
            return $this->artisan('form:import', [
                'masjid' => $this->masjid->id,
                'path' => $relative,
            ])->run();
        } finally {
            @unlink(base_path($relative));
        }
    }
}
