<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Stripe\FormCharge;
use App\Services\Stripe\FormChargeAccount;
use App\Services\Stripe\FormResponseCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * FormChargeAccount, the ONE answer to "whose Connect account takes this organisation's
 * form card payments?" (DECISIONS.md 2026-09-15, D2), and the seams' refusal of anything
 * that is not a connected account's id (D5).
 *
 *  - an organisation that is not linked charges on its own acct_ account, only while
 *    Stripe says it can take charges;
 *  - a linked organisation charges on its parent's account, read live, and every broken
 *    rule of the link answers null (card unavailable), never a different payee;
 *  - linkProblem() names each broken rule, and refuses a child that is itself a holder;
 *  - the link never lends donations: canAcceptDonations() stays false for the child.
 */
class FormChargeAccountTest extends TestCase
{
    use RefreshDatabase;

    private const HOLDER_ACCOUNT = 'acct_1BurlingtonTest';

    private Masjid $holder;

    private Masjid $child;

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

        $this->holder = $this->makeOrg('Burlington Masjid', ['stripe_account_id' => self::HOLDER_ACCOUNT, 'stripe_charges_enabled' => true]);
        $this->child = $this->makeOrg('Burlington Islamic Sunday School');
        $this->setParent($this->child, $this->holder);
    }

    // ------------------------------------------------------------ not linked

    #[Test]
    public function an_organisation_that_is_not_linked_charges_on_its_own_live_account_only(): void
    {
        $charge = FormChargeAccount::for($this->holder);

        $this->assertInstanceOf(FormCharge::class, $charge);
        $this->assertSame(self::HOLDER_ACCOUNT, $charge->accountId);
        $this->assertFalse($charge->linked);
        $this->assertTrue($charge->holder->is($this->holder));
        $this->assertTrue($charge->organisation->is($this->holder));

        $this->assertNull(FormChargeAccount::for(null));
        $this->assertNull(FormChargeAccount::for($this->makeOrg('No account')));
        $this->assertNull(FormChargeAccount::for($this->makeOrg('Blank account', ['stripe_account_id' => '', 'stripe_charges_enabled' => true])));
        $this->assertNull(FormChargeAccount::for($this->makeOrg('Not an account', ['stripe_account_id' => 'cus_123456', 'stripe_charges_enabled' => true])));
        $this->assertNull(FormChargeAccount::for($this->makeOrg('Charges off', ['stripe_account_id' => 'acct_1ChargesOff', 'stripe_charges_enabled' => false])));

        $gone = $this->makeOrg('Offboarded', ['stripe_account_id' => 'acct_1Offboarded', 'stripe_charges_enabled' => true]);
        $gone->delete();
        $this->assertNull(FormChargeAccount::for($gone));

        // The child is not linked yet: nothing of its parent's is lent by parent_id alone.
        $this->assertNull(FormChargeAccount::for($this->child));
    }

    // ---------------------------------------------------------------- linked

    #[Test]
    public function a_linked_child_charges_on_its_parents_account_read_live_and_never_takes_donations(): void
    {
        $this->link($this->child, $this->holder);

        $charge = FormChargeAccount::for($this->child->fresh());

        $this->assertNotNull($charge);
        $this->assertTrue($charge->linked);
        $this->assertSame(self::HOLDER_ACCOUNT, $charge->accountId);
        $this->assertTrue($charge->holder->is($this->holder));
        $this->assertTrue($charge->organisation->is($this->child));
        $this->assertNull(FormChargeAccount::linkProblem($this->child->fresh(), $this->holder->fresh()));

        // Read live: the holder re-onboarding moves every new page with it.
        $this->holder->forceFill(['stripe_account_id' => 'acct_1BurlingtonNew'])->save();
        $this->assertSame('acct_1BurlingtonNew', FormChargeAccount::for($this->child->fresh())->accountId);

        // No account or flag is ever copied onto the child, and donations stay refused.
        $child = $this->child->fresh();
        $this->assertNull($child->stripe_account_id);
        $this->assertFalse((bool) $child->stripe_charges_enabled);
        $this->assertFalse($child->canAcceptDonations());

        // The gates Forms asks all read the resolver.
        $this->assertNull(FormResponseCheckoutService::refusal($child, 3000));
        $this->assertTrue($this->cardForm($child)->canTakeCardNow());
    }

    #[Test]
    public function every_broken_rule_of_a_link_fails_closed_and_names_itself(): void
    {
        $this->link($this->child, $this->holder);

        $cases = [
            FormChargeAccount::PROBLEM_HOLDER_CHARGES_DISABLED => fn () => $this->holder->forceFill(['stripe_charges_enabled' => false])->save(),
            FormChargeAccount::PROBLEM_HOLDER_NOT_ONBOARDED => fn () => $this->holder->forceFill(['stripe_account_id' => null])->save(),
            FormChargeAccount::PROBLEM_HOLDER_MISSING => fn () => $this->holder->delete(),
            FormChargeAccount::PROBLEM_NOT_PARENT => fn () => DB::table('masjids')->where('id', $this->child->id)->update(['parent_id' => null]),
            FormChargeAccount::PROBLEM_HAS_OWN_ACCOUNT => fn () => DB::table('masjids')->where('id', $this->child->id)->update(['stripe_account_id' => 'acct_1ChildOwn', 'stripe_charges_enabled' => true]),
            FormChargeAccount::PROBLEM_HOLDER_LINKED => function () {
                $grand = $this->makeOrg('Grandparent', ['stripe_account_id' => 'acct_1Grandparent', 'stripe_charges_enabled' => true]);
                $this->setParent($this->holder, $grand);
                DB::table('masjids')->where('id', $this->holder->id)->update(['forms_card_via_masjid_id' => $grand->id]);
            },
        ];

        foreach ($cases as $problem => $break) {
            $snapshot = DB::table('masjids')->whereIn('id', [$this->holder->id, $this->child->id])->get()->keyBy('id');

            $break();

            $child = Masjid::withTrashed()->find($this->child->id);
            $holder = Masjid::withTrashed()->find($this->holder->id);

            $this->assertNull(FormChargeAccount::for($child), "{$problem}: card must be unavailable");
            $this->assertSame($problem, FormChargeAccount::linkProblem($child, $holder->trashed() ? null : $holder), $problem);
            $this->assertSame(FormResponseCheckoutService::UNAVAILABLE, FormResponseCheckoutService::refusal($child, 3000), $problem);
            $this->assertFalse($this->cardForm($child)->canTakeCardNow(), $problem);

            // Put both rows back exactly as they were before the next case.
            foreach ($snapshot as $id => $values) {
                DB::table('masjids')->where('id', $id)->update((array) $values);
            }

            $this->assertNotNull(FormChargeAccount::for(Masjid::find($this->child->id)), "{$problem}: restored");
        }

        // A link naming an organisation that does not exist at all.
        DB::table('masjids')->where('id', $this->child->id)->update(['forms_card_via_masjid_id' => 999999]);
        $this->assertNull(FormChargeAccount::for(Masjid::find($this->child->id)));

        // A trashed child.
        $this->link($this->child, $this->holder);
        $this->child->delete();
        $this->assertNull(FormChargeAccount::for(Masjid::withTrashed()->find($this->child->id)));
    }

    #[Test]
    public function a_child_that_is_itself_a_holder_cannot_be_linked(): void
    {
        $grandchild = $this->makeOrg('Grandchild');
        $this->setParent($grandchild, $this->child);
        DB::table('masjids')->where('id', $grandchild->id)->update(['forms_card_via_masjid_id' => $this->child->id]);

        $this->assertSame(FormChargeAccount::PROBLEM_IS_HOLDER, FormChargeAccount::linkProblem($this->child->fresh(), $this->holder->fresh()));
        $this->assertSame(FormChargeAccount::PROBLEM_SAME_ORGANISATION, FormChargeAccount::linkProblem($this->child->fresh(), $this->child->fresh()));
        $this->assertSame(FormChargeAccount::PROBLEM_HAS_OWN_ACCOUNT, FormChargeAccount::linkProblem($this->holder->fresh(), $this->holder->fresh()));

        // The grandchild's own link fails at "holder not onboarded": the child has no account.
        $this->assertNull(FormChargeAccount::for($grandchild->fresh()));
    }

    #[Test]
    public function charged_through_and_the_refund_instruction_name_the_holder_only_for_a_row_charged_through_it(): void
    {
        $form = $this->cardForm($this->child);
        $row = $this->row($form);

        $this->assertNull(FormChargeAccount::chargedThrough($row));
        $this->assertNull(FormChargeAccount::refundInstruction($row));

        $row->forceFill(['charge_masjid_id' => $this->holder->id, 'charge_account_id' => self::HOLDER_ACCOUNT, 'stripe_payment_intent_id' => 'pi_1Linked'])->save();

        $this->assertTrue(FormChargeAccount::chargedThrough($row)->is($this->holder));
        $this->assertSame(
            "The card payment was taken through Burlington Masjid's Stripe account, so only Burlington Masjid can refund it (payment pi_1Linked).",
            FormChargeAccount::refundInstruction($row)
        );

        // Still named once the holder is offboarded.
        $this->holder->delete();
        $this->assertSame('Burlington Masjid', FormChargeAccount::chargedThrough($row->fresh())->name);

        // A row pinned to its own organisation's account was not charged through anyone.
        $own = $this->row($form);
        $own->forceFill(['charge_masjid_id' => $this->child->id, 'charge_account_id' => 'acct_1ChildOwn'])->save();
        $this->assertNull(FormChargeAccount::chargedThrough($own));
    }

    #[Test]
    public function the_statement_suffix_is_the_organisations_initials(): void
    {
        $this->assertSame('BISS', FormResponseCheckoutService::statementSuffix('Burlington Islamic Sunday School'));
        $this->assertSame('AQS', FormResponseCheckoutService::statementSuffix('al-Qalam  school!'));
        $this->assertSame('EAS', FormResponseCheckoutService::statementSuffix('École Al Salam'));
        $this->assertSame('ABCDEFGHIJ', FormResponseCheckoutService::statementSuffix('a b c d e f g h i j k l'));
        $this->assertSame('M2', FormResponseCheckoutService::statementSuffix('Masjid 2026'));
        $this->assertNull(FormResponseCheckoutService::statementSuffix('2026'));
        $this->assertNull(FormResponseCheckoutService::statementSuffix(''));
        $this->assertNull(FormResponseCheckoutService::statementSuffix(null));
    }

    #[Test]
    public function the_three_seams_refuse_any_account_that_is_not_a_connected_accounts_id_before_stripe_is_called(): void
    {
        $service = new class(new StripeClient('sk_test_never_called')) extends FormResponseCheckoutService
        {
            public function create(string $account): array
            {
                return $this->createCheckoutSession([], $account, 'form_response_key');
            }

            public function retrieve(string $account): array
            {
                return $this->retrieveCheckoutSession('cs_test_1', $account);
            }

            public function expire(string $account): void
            {
                $this->expireCheckoutSession('cs_test_1', $account);
            }
        };

        foreach (['create', 'retrieve', 'expire'] as $seam) {
            foreach (['', 'acct_', 'cus_123456', ' acct_1Padded'] as $account) {
                try {
                    $service->{$seam}($account);
                    $this->fail("{$seam} must refuse '{$account}' before calling Stripe");
                } catch (LogicException $e) {
                    $this->assertStringContainsString('connected account', $e->getMessage());
                }
            }
        }
    }

    // ------------------------------------------------------------------- helpers

    private function link(Masjid $child, Masjid $holder): void
    {
        DB::table('masjids')->where('id', $child->id)->update(['forms_card_via_masjid_id' => $holder->id]);
    }

    private function setParent(Masjid $child, Masjid $parent): void
    {
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $parent->id]);
    }

    private function cardForm(Masjid $masjid): Form
    {
        $form = Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'enrol-' . uniqid(),
            'name' => 'Registration 2026',
            'schema' => ['sections' => [['id' => 'main', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ]]]],
            'settings' => [
                'identity' => ['name' => 'fullName'],
                'fee' => ['amount' => 100, 'currency' => 'USD'],
                'payment' => ['online' => true],
            ],
            'is_active' => true,
        ]);

        return $form->setRelation('masjid', $masjid);
    }

    private function row(Form $form): FormResponse
    {
        $row = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['fullName' => 'Amal Yusuf'],
            'respondent_name' => 'Amal Yusuf',
            'entry_count' => 1,
            'amount_due' => 100,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $row->forceFill([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_UNPAID,
            'currency' => 'usd',
            'amount_due_minor' => 10000,
            'fee_covered_minor' => 0,
            'total_minor' => 10000,
        ])->save();

        return $row;
    }

    private function makeOrg(string $name, array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => $name,
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }
}
