<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\EmailSuppression;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Fund;
use App\Models\HistoricalImportRecord;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\TenantContext;
use App\Support\ZakatDesignation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/**
 * Imports an organisation's Wix order history — store orders and Wix Events
 * ticket orders — into Manara's donation and registration history
 * (`crm:import-wix-orders`; DECISIONS.md 2026-09-25, "Wix order history").
 *
 * ---------------------------------------------------------------------------
 * WHERE EACH LINE GOES
 * ---------------------------------------------------------------------------
 *
 *  - GIVING (Zakat-ul-Fitr, iftar, Qurbani, shelter and student sponsorships,
 *    relief drives) → one Donation per line, into a fund found by name or
 *    created INACTIVE and NON-RECEIPTABLE so it is never offered to a donor.
 *  - TICKETS (festivals, the Hajj simulation, workshops, the 2018 summer
 *    sessions, and every Wix Events order) → one Registration per order and
 *    event, against an UNPUBLISHED offering (`is_active` false) that shares one
 *    inactive intake form and has one inactive fee plan. Paid orders are
 *    `confirmed`/`paid` with one settled ledger row; Wix's abandoned checkouts
 *    (canceled, declined) are `cancelled`/`canceled` with none.
 *  - ANYTHING ELSE (festival food tickets, the 2021 prayer rugs) → no donation
 *    and no seat: the line is kept on the HistoricalOrder only, marked
 *    `order_only`. A purchase is not a gift, and a food ticket is not a seat.
 *
 * A product name the catalogue below does not know is not guessed at: the
 * import refuses to write until somebody decides where it belongs.
 *
 * ---------------------------------------------------------------------------
 * WHAT EVERY IMPORTED ROW SAYS, AND WHAT NONE OF THEM DOES
 * ---------------------------------------------------------------------------
 *
 * Every donation and registration carries `source = historical`, the
 * `historical_order_id` of its order, and a note naming the Wix order number
 * and the processor (Square, PayPal, or "the Wix checkout" when the export does
 * not say). None carries a Stripe id, and none is ever attributed to Manara.
 *
 * Nothing is sent. No receipt is issued (ReceiptService declines historical
 * gifts), no email, SMS or push is dispatched, no webhook or renderer purge is
 * raised: this class creates rows with Eloquent inside one transaction and calls
 * no mailer, notifier, Stripe client or queue. No model it writes has an
 * observer that sends anything. The seat counter, rosters and the reaper are
 * untouched (a historical offering's `registration_count` stays 0; its rows are
 * never pending).
 *
 * A buyer is linked to the contact holding the same email (case-insensitive;
 * the oldest if several do). A buyer with no contact gets one, and the address
 * is held against broadcast email (EmailSuppression::REASON_ORDER_HISTORY_HOLD)
 * unless the organisation already has a suppression row for it — an active one
 * already holds it, and a released one is the person's own request to be
 * mailed, which an import must not overturn. Run the Wix contact import FIRST:
 * then buyers are linked to contacts that already carry their Wix consent, and
 * a hold is written only for a buyer that import did not bring over.
 *
 * ---------------------------------------------------------------------------
 * SAFE BY CONSTRUCTION (the crm:import-ledger / schools:import-roster shape)
 * ---------------------------------------------------------------------------
 *
 *  - DRY RUN by default; `run(..., execute: true)` writes, all or nothing.
 *  - IDEMPOTENT: HistoricalOrder is unique per (organisation, source, order
 *    number), so an order already imported is skipped on every later run.
 *  - REVERSIBLE: `undo()` removes a batch's orders, donations and
 *    registrations (their ledger rows and adjustments go with them), then the
 *    scaffolding the batch recorded in historical_import_records — each piece
 *    only if nothing else now uses it and nobody has changed it since.
 *  - The TENANT must be bound to the organisation by the caller (the command
 *    does it); a mismatch is a LogicException, never a write into the wrong
 *    organisation.
 */
class WixOrderHistoryImporter
{
    public const FORM_SLUG = 'wix-order-history';
    public const OFFERING_SLUG_PREFIX = 'wix-';
    public const PLAN_LABEL = 'Bought on the old Wix site';

    /**
     * Giving products → [fund name, fund type]. Keys are product names as sold,
     * lower-cased with runs of whitespace collapsed (Wix's names carry double
     * spaces). Zakat-ul-Fitr has its own fund type; every other fund is
     * `general`, because naming iftar or Qurbani `sadaqah` would be a ruling
     * the import has no business making.
     */
    private const GIVING = [
        'zakat-ul-fitr (per person)' => ['Zakat-ul-Fitr', 'fitra'],
        'zakat-ul-fitr 2020' => ['Zakat-ul-Fitr', 'fitra'],
        'individual iftar' => ['Iftar', 'general'],
        'quarter iftar' => ['Iftar', 'general'],
        'half iftar' => ['Iftar', 'general'],
        'full iftar' => ['Iftar', 'general'],
        'iftar sponsorship for 50 people' => ['Iftar', 'general'],
        'iftar sponsorship for 100 people' => ['Iftar', 'general'],
        '10 meals in ramadan' => ['Iftar', 'general'],
        'general - qurbani / udhiyah donation' => ['Qurbani / Udhiyah', 'general'],
        'gaza - qurbani / udhiyah donation' => ['Qurbani / Udhiyah', 'general'],
        'gaza - 1/2 qurbani / udhiyah donation' => ['Qurbani / Udhiyah', 'general'],
        'shelter donation' => ['General Donation', 'general'],
        'fence sections donation' => ['General Donation', 'general'],
        'one college student one year sponsorship' => ['General Donation', 'general'],
        'one school student one year sponsorship' => ['General Donation', 'general'],
        '60 bread bag for syria' => ['General Donation', 'general'],
    ];

    /**
     * Ticket products → [offering name, offering kind]. `{year}` is the year of
     * the order in the organisation's timezone, so each season's festival is its
     * own offering. The Career DNA workshop ran across a new year, so it is one.
     */
    private const TICKETS = [
        'eid festival ticket' => ['Eid Festival {year}', Offering::KIND_EVENT],
        'fall festival ticket' => ['Fall Festival {year}', Offering::KIND_EVENT],
        "kid's hajj simulation" => ["Kid's Hajj Simulation {year}", Offering::KIND_EVENT],
        'hajj simulation & eid adha bazaar' => ['Hajj Simulation & Eid Adha Bazaar {year}', Offering::KIND_EVENT],
        'stop guessing, start doing: your career dna revealed' => ['Stop Guessing, Start Doing: Your Career DNA Revealed', Offering::KIND_EVENT],
        '1st session 6/25-7/11' => ['Summer {year}: 1st Session 6/25-7/11', Offering::KIND_PROGRAM],
        '2nd session 7/16-8/1' => ['Summer {year}: 2nd Session 7/16-8/1', Offering::KIND_PROGRAM],
    ];

    /** Purchases that are neither a gift nor a seat: kept on the order only. */
    private const ORDER_ONLY = [
        '$30 food ticket for $25',
        '$30 food tickets for only $25',
        'new prayer rug',
    ];

    public function __construct(private readonly EmailSuppressionService $suppressions)
    {
    }

    /**
     * Plan the import and, when `$execute`, write it in one transaction.
     *
     * Returns the summary either way — counts and money only, never a name or
     * an address — plus `blocking`, the reasons it will not write (unreadable
     * orders, unknown products, a slug in use, totals that do not reconcile).
     * With `$execute` and anything blocking, nothing is written.
     *
     * @param  list<array<string, mixed>>  $orders  WixOrderExport::read()['orders']
     * @param  list<string>  $problems  WixOrderExport::read()['problems']
     * @return array<string, mixed>
     */
    public function run(Masjid $masjid, array $orders, array $problems, string $batch, bool $execute): array
    {
        $this->assertBound($masjid);

        $plan = $this->plan($masjid, $orders, $problems);
        $summary = $plan['summary'];
        $summary['written'] = false;

        if (! $execute || $summary['blocking'] !== []) {
            return $summary;
        }

        DB::transaction(fn () => $this->write($masjid, $plan, $batch));
        $summary['written'] = true;

        return $summary;
    }

    // ------------------------------------------------------------------ planning

    /** @return array{summary: array<string, mixed>, orders: list<array>, funds: array, offerings: array, form: ?array} */
    private function plan(Masjid $masjid, array $orders, array $problems): array
    {
        $timezone = $masjid->timezone ?: (string) config('app.timezone', 'UTC');
        $blocking = array_values($problems);

        // Oldest first, so the earliest order names a contact the import has to
        // create, and a re-read of the same export plans the same rows.
        usort($orders, fn ($a, $b) => [$a['ordered_at']->format('Y-m-d H:i:s.u'), $a['source'], $a['order_number']]
            <=> [$b['ordered_at']->format('Y-m-d H:i:s.u'), $b['source'], $b['order_number']]);

        $seen = [];
        $existing = HistoricalOrder::query()->get(['source', 'order_number'])
            ->mapWithKeys(fn ($o) => [$o->source . '|' . $o->order_number => true])->all();

        $contactsByEmail = $this->contactsByEmail();
        $fundsByName = Fund::query()->get()->keyBy(fn (Fund $f) => $this->key($f->name));
        $importedOfferingIds = HistoricalImportRecord::query()
            ->where('record_type', HistoricalImportRecord::TYPE_OFFERING)->pluck('record_id')->all();

        $summary = [
            'orders' => [
                HistoricalOrder::SOURCE_WIX_STORES => ['count' => 0, 'paid' => 0, 'unpaid' => 0, 'paid_minor' => 0],
                HistoricalOrder::SOURCE_WIX_EVENTS => ['count' => 0, 'paid' => 0, 'unpaid' => 0, 'paid_minor' => 0],
            ],
            'already_imported' => 0,
            'to_import' => 0,
            'donations' => ['count' => 0, 'minor' => 0, 'by_fund' => []],
            'registrations' => ['confirmed' => 0, 'cancelled' => 0, 'paid_minor' => 0, 'wix_fee_minor' => 0, 'by_offering' => []],
            'order_only' => ['lines' => 0, 'minor' => 0],
            'providers' => [],
            'contacts' => ['linked' => 0, 'created' => 0, 'ambiguous' => 0, 'no_email' => 0],
            'funds_to_create' => [],
            'offerings_to_create' => [],
            'unrecognised_products' => [],
            'reconciles' => true,
            'blocking' => [],
        ];

        $planned = [];
        $funds = [];
        $offerings = [];
        $newContacts = [];
        $linkedContacts = [];
        $ambiguous = [];
        $importPaidMinor = 0;

        foreach ($orders as $order) {
            $source = $order['source'];
            $paid = $order['status'] === HistoricalOrder::STATUS_PAID;

            $summary['orders'][$source]['count']++;
            $summary['orders'][$source][$paid ? 'paid' : 'unpaid']++;
            if ($paid) {
                $summary['orders'][$source]['paid_minor'] += $order['total_minor'];
            }

            $id = $source . '|' . $order['order_number'];
            if (isset($seen[$id])) {
                $blocking[] = "Order {$order['order_number']} ({$source}) appears twice in the export.";

                continue;
            }
            $seen[$id] = true;

            if (isset($existing[$id])) {
                $summary['already_imported']++;

                continue;
            }

            $summary['to_import']++;
            $year = $order['ordered_at']->setTimezone($timezone)->format('Y');

            // Contact: linked by email, or created (and held) once per address.
            $contact = null;
            if ($order['email'] === null) {
                $summary['contacts']['no_email']++;
            } elseif (isset($contactsByEmail[$order['email']])) {
                $ids = $contactsByEmail[$order['email']];
                $contact = ['id' => $ids[0]];
                $linkedContacts[$ids[0]] = true;
                if (count($ids) > 1) {
                    $ambiguous[$order['email']] = true;
                }
            } else {
                $newContacts[$order['email']] ??= [
                    'email' => $order['email'],
                    'first_name' => $order['first_name'],
                    'last_name' => $order['last_name'],
                ];
                $contact = ['new' => $order['email']];
            }

            // Lines: classify, and spread the order's discount over them in order.
            $remainingDiscount = $order['discount_minor'];
            $lines = [];
            $donations = [];
            $registrations = [];

            foreach ($order['lines'] as $i => $line) {
                $gross = $line['quantity'] * $line['unit_minor'];
                $discount = min($remainingDiscount, $gross);
                $remainingDiscount -= $discount;
                $net = $gross - $discount;
                $name = $this->key($line['name']);

                if ($source === HistoricalOrder::SOURCE_WIX_EVENTS) {
                    $offeringName = $this->eventOfferingName($order['event']['title'], $year);
                    $kind = HistoricalOrder::RECORDED_AS_REGISTRATION;
                    $target = $offeringName;
                    $this->noteOffering($offerings, $offeringName, Offering::KIND_EVENT, $order['event']['starts_at'], $line['unit_minor']);
                } elseif (isset(self::GIVING[$name])) {
                    [$fundName, $fundType] = self::GIVING[$name];
                    $kind = HistoricalOrder::RECORDED_AS_DONATION;
                    $target = $fundName;
                    $funds[$this->key($fundName)] ??= ['name' => $fundName, 'type' => $fundType];
                } elseif (isset(self::TICKETS[$name])) {
                    [$template, $offeringKind] = self::TICKETS[$name];
                    $offeringName = str_replace('{year}', $year, $template);
                    $kind = HistoricalOrder::RECORDED_AS_REGISTRATION;
                    $target = $offeringName;
                    $this->noteOffering($offerings, $offeringName, $offeringKind, null, $line['unit_minor']);
                } elseif (in_array($name, self::ORDER_ONLY, true)) {
                    $kind = HistoricalOrder::RECORDED_AS_ORDER_ONLY;
                    $target = null;
                } else {
                    $summary['unrecognised_products'][$line['name']] = ($summary['unrecognised_products'][$line['name']] ?? 0) + 1;
                    $kind = null;
                    $target = null;
                }

                $lines[] = [
                    'name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit_minor' => $line['unit_minor'],
                    'options' => $line['options'],
                    'discount_minor' => $discount,
                    'recorded_as' => $kind,
                    'recorded_in' => $target,
                ];

                if ($kind === HistoricalOrder::RECORDED_AS_DONATION && $paid) {
                    $donations[] = ['line' => $i, 'fund' => $this->key($target), 'amount' => $net];
                    $summary['donations']['count']++;
                    $summary['donations']['minor'] += $net;
                    $summary['donations']['by_fund'][$target]['count'] = ($summary['donations']['by_fund'][$target]['count'] ?? 0) + 1;
                    $summary['donations']['by_fund'][$target]['minor'] = ($summary['donations']['by_fund'][$target]['minor'] ?? 0) + $net;
                } elseif ($kind === HistoricalOrder::RECORDED_AS_REGISTRATION) {
                    $slug = $this->offeringSlug($target);
                    $registrations[$slug] ??= ['offering' => $slug, 'lines' => [], 'list' => 0, 'discount' => 0];
                    $registrations[$slug]['lines'][] = $i;
                    $registrations[$slug]['list'] += $gross;
                    $registrations[$slug]['discount'] += $discount;
                } elseif ($kind === HistoricalOrder::RECORDED_AS_ORDER_ONLY && $paid) {
                    $summary['order_only']['lines']++;
                    $summary['order_only']['minor'] += $net;
                }
            }

            // A Wix Events order is one event, so the fee Wix added at checkout
            // (paid by the buyer, inside the total) belongs to its registration.
            $registrations = array_values($registrations);
            foreach ($registrations as $r => $registration) {
                $adjusted = $registration['list'] - $registration['discount'];
                $registrations[$r]['adjusted'] = $adjusted;
                $registrations[$r]['paid'] = $paid ? $adjusted + ($r === 0 ? $order['fee_minor'] : 0) : 0;

                $name = $offerings[$registration['offering']]['name'];
                $summary['registrations'][$paid ? 'confirmed' : 'cancelled']++;
                $summary['registrations']['paid_minor'] += $registrations[$r]['paid'];
                $summary['registrations']['by_offering'][$name]['count'] = ($summary['registrations']['by_offering'][$name]['count'] ?? 0) + 1;
                $summary['registrations']['by_offering'][$name]['paid_minor'] = ($summary['registrations']['by_offering'][$name]['paid_minor'] ?? 0) + $registrations[$r]['paid'];
            }

            if ($paid) {
                $summary['registrations']['wix_fee_minor'] += $order['fee_minor'];
                $importPaidMinor += $order['total_minor'];
                $summary['providers'][$order['provider']]['orders'] = ($summary['providers'][$order['provider']]['orders'] ?? 0) + 1;
                $summary['providers'][$order['provider']]['minor'] = ($summary['providers'][$order['provider']]['minor'] ?? 0) + $order['total_minor'];
            }

            $planned[] = [
                'order' => $order,
                'contact' => $contact,
                'lines' => $lines,
                'donations' => $donations,
                'registrations' => $registrations,
            ];
        }

        // Funds: an existing fund of the same name is reused; the rest are created.
        foreach ($funds as $key => $fund) {
            $funds[$key]['existing_id'] = $fundsByName->get($key)?->id;
            if ($funds[$key]['existing_id'] === null) {
                $summary['funds_to_create'][] = $fund['name'];
            }
        }

        // Offerings: reused only when an earlier batch of THIS import made them.
        foreach ($offerings as $slug => $offering) {
            $found = Offering::withTrashed()->where('slug', $slug)->first();
            $offerings[$slug]['existing_id'] = null;

            if ($found === null) {
                $summary['offerings_to_create'][] = $offering['name'];
            } elseif ($found->trashed() || ! in_array($found->id, $importedOfferingIds, true)) {
                $blocking[] = "The offering slug {$slug} is already used by an offering this import did not create.";
            } else {
                $offerings[$slug]['existing_id'] = $found->id;
            }
        }

        $form = null;
        if ($offerings !== []) {
            $found = Form::withTrashed()->where('masjid_id', $masjid->id)->where('slug', self::FORM_SLUG)->first();
            $formIsOurs = $found !== null && HistoricalImportRecord::query()
                ->where('record_type', HistoricalImportRecord::TYPE_FORM)->where('record_id', $found->id)->exists();

            if ($found !== null && ($found->trashed() || ! $formIsOurs)) {
                $blocking[] = 'The form slug ' . self::FORM_SLUG . ' is already used by a form this import did not create.';
            }

            $form = ['existing_id' => $found?->id];
        }

        foreach ($summary['unrecognised_products'] as $product => $count) {
            $blocking[] = "Unrecognised product \"{$product}\" on {$count} line(s): add it to the catalogue in WixOrderHistoryImporter first.";
        }

        $recorded = $summary['donations']['minor'] + $summary['registrations']['paid_minor'] + $summary['order_only']['minor'];
        if ($summary['unrecognised_products'] === [] && $recorded !== $importPaidMinor) {
            $summary['reconciles'] = false;
            $blocking[] = 'Donations, registrations and order-only lines do not add up to the paid orders.';
        }

        $summary['import_paid_minor'] = $importPaidMinor;
        $summary['contacts']['linked'] = count($linkedContacts);
        $summary['contacts']['created'] = count($newContacts);
        $summary['contacts']['ambiguous'] = count($ambiguous);
        $summary['blocking'] = $blocking;
        ksort($summary['donations']['by_fund']);
        ksort($summary['registrations']['by_offering']);

        return [
            'summary' => $summary,
            'orders' => $planned,
            'funds' => $funds,
            'offerings' => $offerings,
            'form' => $form,
            'new_contacts' => $newContacts,
            'timezone' => $timezone,
        ];
    }

    /**
     * One offering per name, remembering the lowest ticket price seen: that is
     * the amount its single (inactive) fee plan carries, so the plan never
     * claims a price no buyer paid.
     */
    private function noteOffering(array &$offerings, string $name, string $kind, ?CarbonImmutable $startsAt, int $unitMinor): void
    {
        $slug = $this->offeringSlug($name);

        $offerings[$slug] ??= [
            'name' => $name,
            'kind' => $kind,
            'event_starts_at' => $startsAt,
            'min_unit_minor' => $unitMinor,
        ];
        $offerings[$slug]['min_unit_minor'] = min($offerings[$slug]['min_unit_minor'], $unitMinor);
    }

    /**
     * Live contacts of this organisation by lower-cased address, oldest first —
     * so a person entered twice is linked to the record that has been theirs
     * longest, every run, rather than to whichever row a query returned first.
     *
     * @return array<string, list<int>>
     */
    private function contactsByEmail(): array
    {
        $map = [];

        Contact::query()->whereNotNull('email')->orderBy('id')->get(['id', 'email'])
            ->each(function (Contact $c) use (&$map) {
                $email = strtolower(trim((string) $c->email));
                if ($email !== '') {
                    $map[$email][] = (int) $c->id;
                }
            });

        return $map;
    }

    // ------------------------------------------------------------------ writing

    private function write(Masjid $masjid, array $plan, string $batch): void
    {
        $record = function (string $type, Model $model) use ($masjid, $batch): void {
            HistoricalImportRecord::create([
                'masjid_id' => $masjid->id,
                'import_batch' => $batch,
                'record_type' => $type,
                'record_id' => $model->getKey(),
                'record_updated_at' => $model->fresh()?->updated_at,
            ]);
        };

        $fundsByKey = [];
        foreach ($plan['funds'] as $key => $fund) {
            if ($fund['existing_id'] !== null) {
                $fundsByKey[$key] = Fund::query()->findOrFail($fund['existing_id']);

                continue;
            }

            // Inactive: never offered on a donation page. Not receiptable: the
            // fund exists to file history under, and nothing here issues receipts.
            $created = Fund::create([
                'masjid_id' => $masjid->id,
                'name' => $fund['name'],
                'type' => $fund['type'],
                'receiptable' => false,
                'is_active' => false,
            ]);
            $record(HistoricalImportRecord::TYPE_FUND, $created);
            $fundsByKey[$key] = $created;
        }

        $formId = $plan['form']['existing_id'] ?? null;
        if ($plan['form'] !== null && $formId === null) {
            $form = Form::create([
                'masjid_id' => $masjid->id,
                'slug' => self::FORM_SLUG,
                'name' => 'Wix order history (imported)',
                'description' => 'The intake form behind the offerings imported from the old Wix site. '
                    . 'Nobody fills it in: those registrations are records of tickets bought on Wix.',
                'schema' => ['sections' => [[
                    'id' => 'main',
                    'title' => 'Imported order',
                    'fields' => [['name' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => false]],
                ]]],
                'is_active' => false,
            ]);
            $record(HistoricalImportRecord::TYPE_FORM, $form);
            $formId = $form->id;
        }

        $offeringIds = [];
        $planIds = [];
        foreach ($plan['offerings'] as $slug => $offering) {
            if ($offering['existing_id'] !== null) {
                $offeringIds[$slug] = $offering['existing_id'];
                $planIds[$slug] = FeePlan::query()->where('offering_id', $offering['existing_id'])
                    ->where('label', self::PLAN_LABEL)->value('id');
            }

            if ($offering['existing_id'] === null || $planIds[$slug] === null) {
                $created = $offering['existing_id'] === null ? Offering::create([
                    'masjid_id' => $masjid->id,
                    'kind' => $offering['kind'],
                    'name' => $offering['name'],
                    'slug' => $slug,
                    'intake_form_id' => $formId,
                    'is_active' => false,
                    'description' => $this->offeringDescription($offering, $plan['timezone']),
                ]) : null;

                if ($created !== null) {
                    $record(HistoricalImportRecord::TYPE_OFFERING, $created);
                    $offeringIds[$slug] = $created->id;
                }

                $feePlan = FeePlan::create([
                    'masjid_id' => $masjid->id,
                    'offering_id' => $offeringIds[$slug],
                    'kind' => FeePlan::KIND_ONE_TIME,
                    'amount_minor' => $offering['min_unit_minor'],
                    'currency' => 'usd',
                    'label' => self::PLAN_LABEL,
                    'is_active' => false,
                ]);
                $record(HistoricalImportRecord::TYPE_FEE_PLAN, $feePlan);
                $planIds[$slug] = $feePlan->id;
            }
        }

        $contactIds = [];
        $createdContacts = [];

        foreach ($plan['orders'] as $entry) {
            $order = $entry['order'];

            $contactId = null;
            if (isset($entry['contact']['id'])) {
                $contactId = $entry['contact']['id'];
            } elseif (isset($entry['contact']['new'])) {
                $email = $entry['contact']['new'];
                if (! isset($contactIds[$email])) {
                    $contact = $this->createHeldContact($masjid, $plan['new_contacts'][$email], $batch, $record);
                    $contactIds[$email] = $contact->id;
                    $createdContacts[] = $contact;
                }
                $contactId = $contactIds[$email];
            }

            $historical = HistoricalOrder::create([
                'masjid_id' => $masjid->id,
                'source' => $order['source'],
                'order_number' => $order['order_number'],
                'provider' => $order['provider'],
                'payment_method' => $order['payment_method'],
                'status' => $order['status'],
                'ordered_at' => $order['ordered_at'],
                'contact_id' => $contactId,
                'total_minor' => $order['total_minor'],
                'discount_minor' => $order['discount_minor'],
                'fee_minor' => $order['fee_minor'],
                'currency' => 'usd',
                'lines' => $entry['lines'],
                'import_batch' => $batch,
            ]);

            foreach ($entry['donations'] as $d => $gift) {
                $line = $entry['lines'][$gift['line']];
                $fund = $fundsByKey[$gift['fund']];
                $zakat = ZakatDesignation::resolve(null, $fund);

                $donation = new Donation([
                    'masjid_id' => $masjid->id,
                    'contact_id' => $contactId,
                    'fund_id' => $fund->id,
                    'type' => 'one_time',
                    'is_zakat' => $zakat['is_zakat'],
                    'zakat_source' => $zakat['zakat_source'],
                    'source' => Donation::SOURCE_HISTORICAL,
                    'payment_method' => $order['provider'],
                    'donated_at' => $order['ordered_at']->setTimezone($plan['timezone'])->toDateString(),
                    'note' => $this->note($order, [$line]),
                    'import_batch' => $batch,
                    'intended_amount' => $gift['amount'],
                    'charged_amount' => $gift['amount'],
                    'currency' => 'usd',
                    'donor_covers_fees' => false,
                    'status' => 'succeeded',
                    'idempotency_key' => "hist_{$masjid->id}_{$order['source']}_{$order['order_number']}_{$d}",
                ]);
                // Dated to the order, so "gifts in the last 12 months" and every
                // created_at window see it where it happened, not on import day.
                $donation->forceFill([
                    'historical_order_id' => $historical->id,
                    'created_at' => $order['ordered_at'],
                ])->save();
            }

            $paid = $order['status'] === HistoricalOrder::STATUS_PAID;

            foreach ($entry['registrations'] as $r => $planned) {
                $lines = array_map(fn ($i) => $entry['lines'][$i], $planned['lines']);

                $registration = new Registration([
                    'masjid_id' => $masjid->id,
                    'offering_id' => $offeringIds[$planned['offering']],
                    'fee_plan_id' => $planIds[$planned['offering']],
                    'contact_id' => $contactId,
                    'status' => $paid ? Registration::STATUS_CONFIRMED : Registration::STATUS_CANCELLED,
                    'payment_status' => $paid ? Registration::PAYMENT_PAID : Registration::PAYMENT_CANCELED,
                    'list_total_minor' => $planned['list'],
                    'adjusted_total_minor' => $planned['adjusted'],
                ]);
                $registration->recordedFromHistory($historical, $this->note($order, $lines, $r === 0 ? $order['fee_minor'] : 0));
                $registration->forceFill(['created_at' => $order['ordered_at']])->save();

                if ($planned['discount'] > 0) {
                    RegistrationAdjustment::create([
                        'masjid_id' => $masjid->id,
                        'registration_id' => $registration->id,
                        'kind' => RegistrationAdjustment::KIND_CODE,
                        'amount_minor' => $planned['discount'],
                        'reason' => mb_substr('Wix coupon: ' . implode(', ', array_keys($order['discount_codes'])), 0, 255),
                    ]);
                }

                if ($paid) {
                    RegistrationPayment::create([
                        'masjid_id' => $masjid->id,
                        'registration_id' => $registration->id,
                        'amount_minor' => $planned['paid'],
                        'status' => RegistrationPayment::STATUS_SUCCEEDED,
                        'paid_at' => $order['ordered_at'],
                        'idempotency_key' => "hist_{$masjid->id}_{$order['source']}_{$order['order_number']}_{$r}",
                    ]);
                }
            }
        }

        // Snapshot each created contact only now, after the hold's mirror and
        // every write above: a later change to the row is somebody else's work.
        foreach ($createdContacts as $contact) {
            HistoricalImportRecord::query()
                ->where('record_type', HistoricalImportRecord::TYPE_CONTACT)
                ->where('record_id', $contact->id)
                ->update(['record_updated_at' => $contact->fresh()?->updated_at]);
        }
    }

    /**
     * A contact for a buyer the organisation has no record of, with the address
     * held against broadcast email (see the class docblock for why an existing
     * suppression row, active or released, is left exactly as it is).
     */
    private function createHeldContact(Masjid $masjid, array $buyer, string $batch, callable $record): Contact
    {
        $hasRow = EmailSuppression::query()->forAddress($buyer['email'])->exists();

        if (! $hasRow) {
            $hold = $this->suppressions->suppress(
                (int) $masjid->id,
                $buyer['email'],
                EmailSuppression::REASON_ORDER_HISTORY_HOLD,
            );

            if ($hold !== null) {
                $record(HistoricalImportRecord::TYPE_EMAIL_HOLD, $hold);
            }
        }

        $first = $buyer['first_name'];
        $last = $buyer['last_name'];
        if ($first === '' && $last === '') {
            [$first, $last] = ['Wix', 'buyer'];
        }

        $contact = new Contact([
            'first_name' => Str::limit($first, 250, ''),
            'last_name' => Str::limit($last, 250, ''),
            'email' => $buyer['email'],
            'import_batch' => $batch,
        ]);
        $contact->masjid_id = $masjid->id;
        $contact->save();

        $record(HistoricalImportRecord::TYPE_CONTACT, $contact);

        return $contact;
    }

    // ------------------------------------------------------------------ undo

    /**
     * Remove what one batch created. Dry unless `$execute`; the answer has the
     * same shape either way, so the preview is the plan.
     *
     * Orders, their donations and their registrations always go (a registration
     * takes its ledger row and coupon adjustment with it by foreign key). Each
     * piece of scaffolding goes only if nothing else uses it and it has not been
     * changed since the import finished with it; otherwise it is KEPT and
     * counted, with its tracking row, so a later undo can try again. That is the
     * difference from `schools:import-roster --rollback`, which refuses the whole
     * batch: here the order history is the thing being undone, and a contact the
     * Wix contact import has since filled in must survive it.
     *
     * @return array<string, mixed>
     */
    public function undo(Masjid $masjid, string $batch, bool $execute): array
    {
        $this->assertBound($masjid);

        $orderIds = HistoricalOrder::query()->where('import_batch', $batch)->pluck('id')->all();
        $registrationIds = Registration::query()->whereIn('historical_order_id', $orderIds)->pluck('id')->all();

        $summary = [
            'orders' => count($orderIds),
            'donations' => Donation::query()->whereIn('historical_order_id', $orderIds)->count(),
            'registrations' => count($registrationIds),
            'registration_payments' => RegistrationPayment::query()->whereIn('registration_id', $registrationIds)->count(),
            'registration_adjustments' => RegistrationAdjustment::query()->whereIn('registration_id', $registrationIds)->count(),
            'removed' => [],
            'kept' => [],
            'written' => false,
        ];

        $work = function () use ($masjid, $batch, $orderIds, $registrationIds, &$summary, $execute) {
            if ($execute) {
                RegistrationPayment::query()->whereIn('registration_id', $registrationIds)->delete();
                RegistrationAdjustment::query()->whereIn('registration_id', $registrationIds)->delete();
                Registration::query()->whereIn('id', $registrationIds)->delete();
                Donation::query()->whereIn('historical_order_id', $orderIds)->delete();
                HistoricalOrder::query()->whereIn('id', $orderIds)->delete();
            }

            // Children before parents: plans, then offerings, then the form.
            $order = [
                HistoricalImportRecord::TYPE_FEE_PLAN,
                HistoricalImportRecord::TYPE_OFFERING,
                HistoricalImportRecord::TYPE_FORM,
                HistoricalImportRecord::TYPE_FUND,
                HistoricalImportRecord::TYPE_CONTACT,
                HistoricalImportRecord::TYPE_EMAIL_HOLD,
            ];

            $records = HistoricalImportRecord::query()->where('import_batch', $batch)->get()
                ->sortBy(fn ($r) => array_search($r->record_type, $order, true));

            // What this undo takes, by type, as it decides: a later type's check
            // (is the form still some offering's intake? does a live contact
            // still hold the address?) must not count a row that is going too,
            // and on a dry run nothing has actually gone yet.
            $going = [];

            foreach ($records as $record) {
                $verdict = $this->undoVerdict($record, $orderIds, $registrationIds, $going, $execute);
                if ($verdict === null) {
                    $going[$record->record_type][] = $record->record_id;
                }
                $bucket = $verdict === null ? 'removed' : 'kept';
                $summary[$bucket][$record->record_type] = ($summary[$bucket][$record->record_type] ?? 0) + 1;

                if ($verdict !== null) {
                    $summary['kept_reasons'][$verdict] = ($summary['kept_reasons'][$verdict] ?? 0) + 1;
                } elseif ($execute) {
                    $record->delete();
                }
            }
        };

        if ($execute) {
            DB::transaction($work);
            $summary['written'] = true;
        } else {
            $work();
        }

        return $summary;
    }

    /**
     * Null when the recorded row can go (and, executing, it is gone); otherwise
     * why it stays. A dry run answers as if the batch's own orders, donations
     * and registrations were already removed, because a real undo removes them
     * first.
     */
    private function undoVerdict(HistoricalImportRecord $record, array $orderIds, array $registrationIds, array $going, bool $execute): ?string
    {
        $model = match ($record->record_type) {
            HistoricalImportRecord::TYPE_CONTACT => Contact::withTrashed()->find($record->record_id),
            HistoricalImportRecord::TYPE_FUND => Fund::query()->find($record->record_id),
            HistoricalImportRecord::TYPE_FORM => Form::withTrashed()->find($record->record_id),
            HistoricalImportRecord::TYPE_OFFERING => Offering::withTrashed()->find($record->record_id),
            HistoricalImportRecord::TYPE_FEE_PLAN => FeePlan::query()->find($record->record_id),
            HistoricalImportRecord::TYPE_EMAIL_HOLD => EmailSuppression::query()->find($record->record_id),
            default => null,
        };

        if ($model === null) {
            return null;   // already gone; the tracking row is all that is left
        }

        if ($record->record_type !== HistoricalImportRecord::TYPE_EMAIL_HOLD
            && $record->record_updated_at !== null
            && $model->updated_at !== null
            && $model->updated_at->gt($record->record_updated_at)) {
            return 'changed since the import';
        }

        $inUse = match ($record->record_type) {
            HistoricalImportRecord::TYPE_FEE_PLAN => Registration::query()->where('fee_plan_id', $model->id)
                ->whereNotIn('id', $registrationIds)->exists(),
            HistoricalImportRecord::TYPE_OFFERING => Registration::query()->where('offering_id', $model->id)
                ->whereNotIn('id', $registrationIds)->exists()
                || FeePlan::query()->where('offering_id', $model->id)->where('label', '!=', self::PLAN_LABEL)->exists(),
            HistoricalImportRecord::TYPE_FORM => Offering::withTrashed()->where('intake_form_id', $model->id)
                ->whereNotIn('id', $going[HistoricalImportRecord::TYPE_OFFERING] ?? [])->exists()
                || DB::table('form_responses')->where('form_id', $model->id)->exists(),
            HistoricalImportRecord::TYPE_FUND => Donation::query()->where('fund_id', $model->id)
                ->where(fn ($q) => $q->whereNull('historical_order_id')->orWhereNotIn('historical_order_id', $orderIds))->exists(),
            HistoricalImportRecord::TYPE_CONTACT => $this->contactIsReferenced((int) $model->id, $orderIds, $registrationIds),
            HistoricalImportRecord::TYPE_EMAIL_HOLD => $model->reason !== EmailSuppression::REASON_ORDER_HISTORY_HOLD
                || $model->released_at !== null
                || Contact::query()->whereRaw('LOWER(TRIM(email)) = ?', [$model->email_normalized])
                    ->whereNotIn('id', $going[HistoricalImportRecord::TYPE_CONTACT] ?? [])->exists(),
            default => true,
        };

        if ($inUse) {
            return $record->record_type === HistoricalImportRecord::TYPE_EMAIL_HOLD
                ? 'address still held for a live contact, or no longer this import\'s hold'
                : 'in use by records outside this batch';
        }

        if ($execute) {
            match ($record->record_type) {
                HistoricalImportRecord::TYPE_CONTACT, HistoricalImportRecord::TYPE_FORM,
                HistoricalImportRecord::TYPE_OFFERING => $model->forceDelete(),
                default => $model->delete(),
            };
        }

        return null;
    }

    /**
     * Anything outside this batch pointing at the contact, found by asking every
     * table that has a `…contact_id` column rather than a list somebody has to
     * keep in step with the schema.
     */
    private function contactIsReferenced(int $contactId, array $orderIds, array $registrationIds): bool
    {
        foreach (self::contactReferenceColumns() as [$table, $column]) {
            $query = DB::table($table)->where($column, $contactId);

            if ($table === 'donations') {
                $query->where(fn ($q) => $q->whereNull('historical_order_id')->orWhereNotIn('historical_order_id', $orderIds));
            } elseif ($table === 'registrations') {
                $query->whereNotIn('id', $registrationIds);
            } elseif ($table === 'historical_orders') {
                $query->whereNotIn('id', $orderIds);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function contactReferenceColumns(): array
    {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        $columns = [];
        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if ($table === 'contacts') {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if ($column === 'contact_id' || str_ends_with($column, '_contact_id')) {
                    $columns[] = [$table, $column];
                }
            }
        }

        return $columns;
    }

    // ------------------------------------------------------------------ words

    /**
     * What the row says about where it came from. Product names and amounts
     * only — never the buyer — so the note is safe on any admin screen and CSV.
     */
    private function note(array $order, array $lines, int $feeMinor = 0): string
    {
        $what = implode('; ', array_map(
            fn ($l) => "{$l['quantity']} × {$l['name']} at " . $this->money($l['unit_minor'])
                . ($l['discount_minor'] > 0 ? ' less a ' . $this->money($l['discount_minor']) . ' coupon' : ''),
            $lines,
        ));

        $label = $order['source'] === HistoricalOrder::SOURCE_WIX_EVENTS
            ? "Wix Events order {$order['order_number']}"
            : "Wix order #{$order['order_number']}";

        if ($order['status'] !== HistoricalOrder::STATUS_PAID) {
            return "{$label} ({$what}) was {$order['status']} at the Wix checkout and never paid.";
        }

        $how = match ($order['provider']) {
            HistoricalOrder::PROVIDER_SQUARE => 'Paid by card through Square on the old Wix site',
            HistoricalOrder::PROVIDER_PAYPAL => 'Paid through PayPal on the old Wix site',
            default => $order['payment_method'] === 'card'
                ? 'Paid by card at the old Wix site\'s checkout'
                : 'Paid at the old Wix site\'s checkout (Square or PayPal; the export does not say which)',
        };
        $fee = $feeMinor > 0 ? ', including a ' . $this->money($feeMinor) . ' Wix fee' : '';

        return "{$label}: {$what}. {$how}{$fee}, not through Manara.";
    }

    private function offeringDescription(array $offering, string $timezone): string
    {
        $when = $offering['event_starts_at'] instanceof CarbonImmutable
            ? ' The event was held on ' . $offering['event_starts_at']->setTimezone($timezone)->format('l, F j, Y') . '.'
            : '';

        return 'Imported from the old Wix site\'s order history.' . $when
            . ' These registrations are records of tickets paid through Wix (Square or PayPal), not through Manara.';
    }

    private function eventOfferingName(string $title, string $year): string
    {
        return str_contains($title, $year) ? $title : "{$title} {$year}";
    }

    private function offeringSlug(string $name): string
    {
        return Str::limit(self::OFFERING_SLUG_PREFIX . Str::slug($name), 190, '');
    }

    private function key(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    private function money(int $minor): string
    {
        return '$' . number_format(intdiv($minor, 100)) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function assertBound(Masjid $masjid): void
    {
        if (app(TenantContext::class)->get() !== (int) $masjid->id) {
            throw new LogicException('Bind the tenant to the organisation before importing its order history.');
        }
    }
}
