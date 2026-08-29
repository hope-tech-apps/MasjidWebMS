<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use App\Models\User;
use App\Support\FormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ==========================================================================
 * THE TWO DOORS ACCEPT THE SAME DOCUMENTS — proved, not asserted in a comment
 * ==========================================================================
 *
 * `ImportFormCommand`'s docblock has promised twice that a file "cannot
 * introduce a form the builder would have rejected", and has been measurably
 * false twice. Round six found the promise covered `schema` and not `settings`
 * (where the fee lives) and fixed that; the reviewer then found the rewritten
 * promise still false elsewhere. THIS FILE IS WHY THERE IS NO THIRD TIME: one
 * fixture table, every door, identical verdicts. A rule added to one door and
 * not the others fails here rather than in a committee's camp.
 *
 * The four divergences measured on this branch before the fix, each fed through
 * `form:import`, `POST /forms`, `PUT /forms/{id}` (whole document) and
 * `PUT /forms/{id}` (partial):
 *
 *   1. capacity 2000000        import ACCEPT   POST 422   PUT 422
 *   2. is_active null          import ACCEPT storing `true`
 *                              POST   201    storing `false`
 *                              — both "succeeded", and the two rows disagreed
 *                                about whether the public can see the form
 *   3. PUT {settings} only     NO CROSS-CHECK AT ALL. `perEntryOfSection`
 *                              pointing at a section that does not exist:
 *                              import REJECT, POST 422, PUT 200 — and the camp
 *                              fee went from $200.00 to $0.00 (below).
 *   4. PUT {schema} only       the mirror image: a schema that DELETES the
 *                              repeatable section the stored fee is charged per
 *                              entry of, accepted 200, leaving
 *                              `perEntryOfSection: "attendees"` pointing at
 *                              nothing.
 *
 * Plus the pair round six left, which turned out to be the subtlest of the five
 * because it lives in MIDDLEWARE rather than in either file. `TrimStrings` and
 * `ConvertEmptyStringsToNull` are global here, so a blank `until` is null before
 * any rule can see it on POST or PUT — the API stores NULL and means "no
 * cut-off". A JSON file passes through no middleware, so `form:import` stored
 * `''` verbatim, and `Form::resolveTier()` then read `''` and `'   '` two
 * opposite ways. One document, two forms, two prices. The importer now
 * normalises exactly as the HTTP stack does; TieredFeeTest owns the read half.
 *
 * Blank is therefore ACCEPTED everywhere and means what the builder has always
 * meant by it. It is not the "unreadable steps up" rule and must not be confused
 * with it: a blank is not a date somebody got wrong, it is a date somebody did
 * not give.
 */
class FormDoorEquivalenceTest extends TestCase
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

    // =====================================================================
    // THE TABLE
    // =====================================================================

    /**
     * One mutation each, on an otherwise-valid camp document, so a difference in
     * verdict can only be about the thing being mutated.
     *
     * @return array<string,array{0:callable,1:bool}> label => [mutation, should be accepted]
     */
    private function documents(): array
    {
        return [
            // ---------------------------------------------------- accepted
            'baseline' => [fn (&$d) => null, true],
            'until omitted on the last tier' => [function (&$d) {
                unset($d['settings']['fee']['tiers'][2]['until']);
            }, true],
            'until explicitly null' => [function (&$d) {
                $d['settings']['fee']['tiers'][2]['until'] = null;
            }, true],
            // A BLANK is the third spelling of "no cut-off", not a refusal — see
            // App\Rules\TierCutoff. What matters is that every door agrees, and
            // every_door_stores_the_same_form proves they store the same null.
            "until BLANK ''" => [function (&$d) {
                $d['settings']['fee']['tiers'][2]['until'] = '';
            }, true],
            "until BLANK '   '" => [function (&$d) {
                $d['settings']['fee']['tiers'][2]['until'] = '   ';
            }, true],
            // Same reason: `TrimStrings` means the API has always seen this as
            // the bare date, so the importer must too.
            "until ' 2026-08-14 ' padded with spaces" => [function (&$d) {
                $d['settings']['fee']['tiers'][0]['until'] = ' 2026-08-14 ';
            }, true],
            'capacity 500' => [function (&$d) { $d['capacity'] = 500; }, true],
            'is_active false' => [function (&$d) { $d['is_active'] = false; }, true],
            'no settings at all' => [function (&$d) { unset($d['settings']); }, true],
            'a flat fee, no tiers' => [function (&$d) {
                unset($d['settings']['fee']['tiers']);
            }, true],

            // ---------------------------------------------------- refused
            // The cut-off contract, in every shape (App\Rules\TierCutoff).
            "until unpadded '2026-8-14'" => [function (&$d) {
                $d['settings']['fee']['tiers'][0]['until'] = '2026-8-14';
            }, false],
            'until prose' => [function (&$d) {
                $d['settings']['fee']['tiers'][0]['until'] = 'next friday';
            }, false],
            'until not a real date' => [function (&$d) {
                $d['settings']['fee']['tiers'][0]['until'] = '2026-13-45';
            }, false],
            'until an integer' => [function (&$d) {
                $d['settings']['fee']['tiers'][0]['until'] = 20260814;
            }, false],

            // The two the importer transcribed differently.
            'capacity 2000000' => [function (&$d) { $d['capacity'] = 2000000; }, false],

            // The rest of the document.
            'a tier with no amount' => [function (&$d) {
                unset($d['settings']['fee']['tiers'][0]['amount']);
            }, false],
            'a negative fee' => [function (&$d) { $d['settings']['fee']['amount'] = -50; }, false],
            'eleven tiers' => [function (&$d) {
                $d['settings']['fee']['tiers'] = array_fill(0, 11, ['amount' => 10]);
            }, false],
            'a four-letter currency' => [function (&$d) {
                $d['settings']['fee']['currency'] = 'USDD';
            }, false],
            'a malformed notification address' => [function (&$d) {
                $d['settings']['notifyEmails'] = ['not-an-address'];
            }, false],
            'a description over 2000 chars' => [function (&$d) {
                $d['description'] = str_repeat('x', 2001);
            }, false],
            'a window that closes before it opens' => [function (&$d) {
                $d['opens_at'] = '2026-09-01';
                $d['closes_at'] = '2026-08-01';
            }, false],
            'perEntryOfSection naming no section' => [function (&$d) {
                $d['settings']['fee']['perEntryOfSection'] = 'attendee';
            }, false],
            'identity.name naming no question' => [function (&$d) {
                $d['settings']['identity']['name'] = 'fullNam';
            }, false],
            'a schema the builder refuses' => [function (&$d) {
                $d['schema']['sections'][0]['fields'][] = [
                    'name' => 'pick', 'label' => 'Pick', 'type' => 'select', 'required' => true,
                ];
            }, false],
        ];
    }

    /**
     * EVERY door, EVERY document, one verdict.
     *
     * The `PUT` column is the whole document onto an existing row, which is what
     * the builder's "save" actually sends. The PARTIAL writes get their own test
     * below, because their divergence is not about a rule — it is about a check
     * that was not run at all.
     */
    #[Test]
    public function every_door_accepts_and_refuses_the_same_documents(): void
    {
        $divergences = [];

        foreach ($this->documents() as $label => [$mutate, $shouldAccept]) {
            $doc = $this->doc($mutate);
            // Per-CASE, not per-document: two mutations can produce the identical
            // document (unsetting a key that was not there), and a slug derived
            // from the content would then collide with the previous row and read
            // as a refusal that is really a unique-index hit.
            $key = substr(md5($label), 0, 12);

            $verdicts = [
                'form:import' => $this->importVerdict($doc, 'import-' . $key),
                'POST' => $this->postVerdict($doc, 'post-' . $key),
                'PUT' => $this->putVerdict($doc),
            ];

            $expected = $shouldAccept ? 'ACCEPT' : 'REFUSE';

            foreach ($verdicts as $door => $verdict) {
                if ($verdict !== $expected) {
                    $divergences[] = "{$label}: {$door} said {$verdict}, expected {$expected}";
                }
            }
        }

        $this->assertSame([], $divergences, "The doors disagree:\n" . implode("\n", $divergences));
    }

    /**
     * ACCEPTING the same document is half of it. The other half is STORING the
     * same form — measured, `is_active: null` was accepted by both and stored as
     * `true` by one and `false` by the other, which is the difference between a
     * form the public can see and one it cannot.
     */
    #[Test]
    public function every_door_stores_the_same_form(): void
    {
        $shapes = [
            'baseline' => fn (&$d) => null,
            'is_active null' => function (&$d) { $d['is_active'] = null; },
            'is_active absent' => function (&$d) { unset($d['is_active']); },
            'is_active false' => function (&$d) { $d['is_active'] = false; },
            'capacity 500' => function (&$d) { $d['capacity'] = 500; },
            'no settings' => function (&$d) { unset($d['settings']); },
            // The middleware case: a file sees no middleware, so this is the one
            // the importer has to reproduce by hand.
            "until BLANK ''" => function (&$d) { $d['settings']['fee']['tiers'][2]['until'] = ''; },
            "until BLANK '   '" => function (&$d) { $d['settings']['fee']['tiers'][2]['until'] = '   '; },
            "until ' 2026-08-14 '" => function (&$d) { $d['settings']['fee']['tiers'][0]['until'] = ' 2026-08-14 '; },
            'until null' => function (&$d) { $d['settings']['fee']['tiers'][2]['until'] = null; },
        ];

        foreach ($shapes as $label => $mutate) {
            $doc = $this->doc($mutate);

            $imported = $this->importedForm($doc, 'imported-' . md5($label));
            $posted = $this->postedForm($doc, 'posted-' . md5($label));

            $this->assertNotNull($imported, "{$label}: the importer wrote nothing");
            $this->assertNotNull($posted, "{$label}: the API wrote nothing");

            $this->assertSame(
                $this->comparable($imported),
                $this->comparable($posted),
                "{$label}: the two doors stored different forms"
            );
        }
    }

    /**
     * Recursive ksort, so the comparison is about WHAT is stored and not about
     * the order the two doors happen to write the keys in.
     *
     * That difference is real and deliberately not a finding: `settings` is a
     * JSON object read by key, and the admin door emits it in the validator's
     * rule order because it goes through `$request->safe()->all()`. Numeric
     * lists (`schema.sections`, `fee.tiers`) keep their order under ksort, which
     * is what matters — a tier schedule is read in order.
     */
    private static function sortDeep(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }

        ksort($value);

        return $value;
    }

    // =====================================================================
    // THE PARTIAL WRITE — the door an administrator actually uses
    // =====================================================================

    /**
     * ONE SINGULAR TYPO, A 200, AND THE CAMP IS FREE.
     *
     * This is the measured case in full. `amountDue()` multiplies the tier price
     * by the number of rows at `$data[$perEntryOfSection]`; point that at a
     * section nobody submits and the product is zero — and it is STORED on each
     * response at submission time, so it is not a rendering artefact the next
     * save repairs. It is what the family agreed to pay.
     */
    #[Test]
    public function a_partial_write_cannot_point_the_fee_at_a_section_that_does_not_exist(): void
    {
        $form = $this->seedForm();
        $submission = ['attendees' => [['childName' => 'A'], ['childName' => 'B']]];

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        $this->assertSame(200.0, FormSchema::for($form)->amountDue($submission), 'two attendees at the early-bird $100');

        $response = $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", [
            'settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => [
                    'currency' => 'USD',
                    'amount' => 140,
                    // Singular. The section is called "attendees".
                    'perEntryOfSection' => 'attendee',
                    'tiers' => [
                        ['label' => 'Early bird', 'amount' => 100, 'until' => '2026-08-14'],
                        ['label' => 'Day of camp', 'amount' => 140],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertSame(
            200.0,
            FormSchema::for($form->fresh())->amountDue($submission),
            'A refused write must leave the fee exactly as it was.'
        );

        Carbon::setTestNow();
    }

    /** The same hole, running the other way. */
    #[Test]
    public function a_partial_write_cannot_delete_the_section_the_stored_fee_is_charged_per_entry_of(): void
    {
        $form = $this->seedForm();

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", [
            'schema' => ['sections' => [[
                'id' => 'main',
                'title' => 'Main',
                'fields' => [['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
            ]]],
        ])->assertStatus(422);

        $this->assertSame(
            'attendees',
            $form->fresh()->feeRule()['perEntryOfSection'],
            'The stored fee still names a section that still exists.'
        );
    }

    /** …and the identity map, whose question a schema edit can delete just as easily. */
    #[Test]
    public function a_partial_write_cannot_delete_the_question_the_identity_map_names(): void
    {
        $form = $this->seedForm();

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", [
            'schema' => ['sections' => [
                [
                    'id' => 'main',
                    'title' => 'Main',
                    // `fullName` renamed out from under settings.identity.name.
                    'fields' => [['name' => 'guardianName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
                ],
                [
                    'id' => 'attendees',
                    'title' => 'Attendees',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'fields' => [['name' => 'childName', 'label' => 'Child', 'type' => 'text', 'required' => true]],
                ],
            ]],
        ])->assertStatus(422);

        $this->assertSame('fullName', $form->fresh()->identityMap()['name']);
    }

    /**
     * THE OTHER HALF OF EVERY REFUSAL: what it still lets through.
     *
     * A cross-check that runs on partial writes must not turn ordinary editing
     * into a fight. Renaming a form, moving its window, switching it off, or
     * editing the schema and the settings TOGETHER are all still one request.
     */
    #[Test]
    public function ordinary_partial_edits_are_untouched(): void
    {
        $form = $this->seedForm();
        $url = "/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}";

        $this->putJson($url, ['name' => 'Camp 2027'])->assertOk();
        $this->putJson($url, ['is_active' => false])->assertOk();
        $this->putJson($url, ['capacity' => 60])->assertOk();
        $this->putJson($url, ['opens_at' => '2026-07-01', 'closes_at' => '2026-08-30'])->assertOk();

        // Renaming a section AND re-pointing the settings at it, in one write.
        $this->putJson($url, [
            'schema' => ['sections' => [
                [
                    'id' => 'main',
                    'title' => 'Main',
                    'fields' => [['name' => 'guardianName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
                ],
                [
                    'id' => 'campers',
                    'title' => 'Campers',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'fields' => [['name' => 'childName', 'label' => 'Child', 'type' => 'text', 'required' => true]],
                ],
            ]],
            'settings' => [
                'identity' => ['name' => 'guardianName'],
                'fee' => [
                    'currency' => 'USD',
                    'perEntryOfSection' => 'campers',
                    'amount' => 140,
                    'tiers' => [['label' => 'Day of camp', 'amount' => 140]],
                ],
            ],
        ])->assertOk();

        $fresh = $form->fresh();
        $this->assertSame('campers', $fresh->feeRule()['perEntryOfSection']);
        $this->assertSame('guardianName', $fresh->identityMap()['name']);
        $this->assertSame('Camp 2027', $fresh->name);
    }

    /**
     * WHAT THE NEW CHECK REFUSES THAT USED TO PASS, measured rather than argued.
     *
     * Rows written before the cross-check existed — or through the PATCH hole it
     * closes — can already carry settings that name nothing. Running the check on
     * the EFFECTIVE pair means an edit to such a form can now be refused for a
     * breakage the edit did not cause. Measured, on a form whose stored
     * `perEntryOfSection` is the singular typo:
     *
     *     name only                    200
     *     capacity only                200
     *     is_active only               200
     *     window only                  200
     *     schema only (unrelated)      422  settings.fee.perEntryOfSection
     *     settings only (the repair)   200
     *
     * So the cost is exactly one shape: touching the SCHEMA of an already-broken
     * form is refused until the reference is repaired, and the refusal names the
     * field. That is deliberate rather than tolerated — the alternative is
     * subtracting the pre-existing problems and letting a camp whose fee computes
     * to $0.00 stay editable with nothing ever mentioning it. This whole finding
     * is a camp fee zeroed by a 200; a loud refusal that names the field is the
     * fix, and the repair is one request.
     */
    #[Test]
    public function an_already_broken_form_stays_editable_except_where_the_break_is(): void
    {
        $broken = fn () => Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'broken-' . uniqid(),
            'name' => 'Broken Camp',
            'schema' => $this->doc()['schema'],
            'settings' => [
                'identity' => ['name' => 'fullName'],
                // Singular. The section is "attendees". A row the PATCH hole
                // could produce, and its fee is $0 because of it.
                'fee' => ['currency' => 'USD', 'amount' => 140, 'perEntryOfSection' => 'attendee'],
            ],
        ]);

        foreach ([
            'name' => ['name' => 'Renamed Camp'],
            'capacity' => ['capacity' => 60],
            'is_active' => ['is_active' => false],
            'window' => ['opens_at' => '2026-07-01', 'closes_at' => '2026-08-30'],
        ] as $label => $payload) {
            $form = $broken();
            $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", $payload)
                ->assertOk("editing {$label} must not be blocked by a break it did not cause");
        }

        // The one shape that IS refused — and it says which field.
        $form = $broken();
        $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", [
            'schema' => $this->doc()['schema'],
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'settings.fee.perEntryOfSection' => [
                    '"attendee" is not a repeatable section, so the fee cannot be charged per entry of it.',
                ],
            ]);

        // …and the repair is one request, through the same door.
        $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", [
            'settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => ['currency' => 'USD', 'amount' => 140, 'perEntryOfSection' => 'attendees'],
            ],
        ])->assertOk();
    }

    /** A partial write applies the cut-off rule too — it is one ruleset. */
    #[Test]
    public function a_partial_write_applies_the_cutoff_contract(): void
    {
        $form = $this->seedForm();
        $url = "/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}";

        foreach (['2026-8-14', 'next friday', '2026-13-45'] as $bad) {
            $this->putJson($url, ['settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => [
                    'currency' => 'USD',
                    'perEntryOfSection' => 'attendees',
                    'tiers' => [['label' => 'Early bird', 'amount' => 100, 'until' => $bad]],
                ],
            ]])->assertStatus(422, "until " . var_export($bad, true) . ' should be refused');
        }

        // …and the legitimate spellings of "no cut-off" still pass.
        $this->putJson($url, ['settings' => [
            'identity' => ['name' => 'fullName'],
            'fee' => [
                'currency' => 'USD',
                'perEntryOfSection' => 'attendees',
                'tiers' => [['label' => 'Day of camp', 'amount' => 140]],
            ],
        ]])->assertOk();

        foreach ([null, '', '   '] as $blank) {
            $this->putJson($url, ['settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => [
                    'currency' => 'USD',
                    'perEntryOfSection' => 'attendees',
                    'tiers' => [['label' => 'Day of camp', 'amount' => 140, 'until' => $blank]],
                ],
            ]])->assertOk('until ' . var_export($blank, true) . ' is a spelling of "no cut-off"');

            $this->assertNull(
                $form->fresh()->settings['fee']['tiers'][0]['until'] ?? null,
                'every blank spelling stores the same null'
            );
        }
    }

    // ------------------------------------------------------------- fixtures

    /** @param  callable|null  $mutate */
    private function doc($mutate = null): array
    {
        $doc = [
            'slug' => 'camp-x',
            'name' => 'Camp X',
            'description' => 'A camp.',
            'schema' => ['sections' => [
                [
                    'id' => 'main',
                    'title' => 'Main',
                    'fields' => [['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
                ],
                [
                    'id' => 'attendees',
                    'title' => 'Attendees',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'fields' => [['name' => 'childName', 'label' => 'Child', 'type' => 'text', 'required' => true]],
                ],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => [
                    'currency' => 'USD',
                    'perEntryOfSection' => 'attendees',
                    'amount' => 140,
                    'tiers' => [
                        ['label' => 'Early bird', 'amount' => 100, 'until' => '2026-08-14'],
                        ['label' => 'Standard', 'amount' => 120, 'until' => '2026-09-03'],
                        ['label' => 'Day of camp', 'amount' => 140],
                    ],
                ],
            ],
        ];

        if ($mutate) {
            $mutate($doc);
        }

        return $doc;
    }

    private function seedForm(): Form
    {
        $doc = $this->doc();
        $doc['slug'] = 'seed-' . uniqid();
        $doc['masjid_id'] = $this->masjid->id;

        return Form::create($doc);
    }

    private function importVerdict(array $doc, ?string $slug = null): string
    {
        $doc['slug'] = $slug ?? 'import-' . substr(md5(json_encode($doc)), 0, 12);
        $relative = 'database/forms/__test_equivalence.json';
        file_put_contents(base_path($relative), json_encode($doc));

        try {
            $code = $this->artisan('form:import', [
                'masjid' => $this->masjid->id,
                'path' => $relative,
            ])->run();

            return $code === 0 ? 'ACCEPT' : 'REFUSE';
        } finally {
            @unlink(base_path($relative));
        }
    }

    private function postVerdict(array $doc, ?string $slug = null): string
    {
        $doc['slug'] = $slug ?? 'post-' . substr(md5(json_encode($doc)), 0, 12);

        return $this->postJson("/api/admin/masjids/{$this->masjid->id}/forms", $doc)->status() === 201
            ? 'ACCEPT'
            : 'REFUSE';
    }

    /** The whole document onto an existing row — what the builder's save sends. */
    private function putVerdict(array $doc): string
    {
        $form = $this->seedForm();
        $doc['slug'] = $form->slug;

        return $this->putJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}", $doc)->status() === 200
            ? 'ACCEPT'
            : 'REFUSE';
    }

    private function importedForm(array $doc, string $slug): ?Form
    {
        $this->importVerdict($doc, $slug);

        return Form::where('masjid_id', $this->masjid->id)->where('slug', $slug)->first();
    }

    private function postedForm(array $doc, string $slug): ?Form
    {
        $doc['slug'] = $slug;
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/forms", $doc);

        return Form::where('masjid_id', $this->masjid->id)->where('slug', $slug)->first();
    }

    /** Everything about the stored row except the identity of the row itself. */
    private function comparable(Form $form): array
    {
        return self::sortDeep([
            'name' => $form->name,
            'description' => $form->description,
            'schema' => $form->schema,
            'settings' => $form->settings,
            'is_active' => $form->is_active,
            'opens_at' => $form->opens_at?->toIso8601String(),
            'closes_at' => $form->closes_at?->toIso8601String(),
            'capacity' => $form->capacity,
        ]);
    }
}
