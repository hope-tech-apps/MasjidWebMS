<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use App\Support\FormOptionSources;
use App\Support\FormPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MEC's two Ramadan 2027 giving forms (database/forms/mec-zakat-ul-fitr.json and
 * mec-iftar-sponsorship.json) import through the same rules as the builder, switched
 * off, and charge exactly MEC's own Wix prices: Zakat-ul-Fitr 17 USD per person;
 * Individual Iftar 18 USD each, Quarter 450, Half 950, Full 1900, the last three
 * reserving a day. What MEC has not said (the days, the 2027 amounts, the window) is
 * left empty and listed in each file's `$comment.mecToFill`, never invented.
 */
class MecRamadanFormsImportTest extends TestCase
{
    use RefreshDatabase;

    private const ZAKAT = 'database/forms/mec-zakat-ul-fitr.json';

    private const IFTAR = 'database/forms/mec-iftar-sponsorship.json';

    private Masjid $mec;

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

        $this->mec = Masjid::create([
            'name' => 'Test Islamic Center',
            'email' => 'office-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    #[Test]
    public function the_zakat_ul_fitr_form_imports_switched_off_at_seventeen_dollars_a_person(): void
    {
        $this->artisan('form:import', ['masjid' => $this->mec->id, 'path' => self::ZAKAT])->assertExitCode(0);

        $form = Form::where('masjid_id', $this->mec->id)->where('slug', 'mec-zakat-ul-fitr')->sole();

        $this->assertFalse($form->is_active, 'imported for Ramadan 2027, never live on import');
        $this->assertSame('Zakat-ul-Fitr', $form->name);
        $this->assertTrue($form->takesOnlinePayment());
        $this->assertFalse($form->takesOfficePayment());
        $this->assertSame('people', $form->quantityField());

        $quote = FormPayment::quote($form, ['fullName' => 'Jane Giver', 'email' => 'jane@example.test', 'people' => 5]);
        $this->assertSame(1700, $quote['unit_minor']);
        $this->assertSame(8500, $quote['total_minor']);
    }

    #[Test]
    public function the_iftar_form_imports_switched_off_with_mecs_four_levels_and_no_invented_days(): void
    {
        $this->artisan('form:import', ['masjid' => $this->mec->id, 'path' => self::IFTAR])->assertExitCode(0);

        $form = Form::where('masjid_id', $this->mec->id)->where('slug', 'mec-iftar-sponsorship')->sole();

        $this->assertFalse($form->is_active);
        $this->assertSame('Iftar Sponsorship', $form->name);
        $this->assertSame(['field' => 'iftar_day', 'dates' => []], $form->reservation(), 'MEC names its days; none is invented');

        $levels = [];

        foreach (['individual', 'quarter', 'half', 'full'] as $value) {
            $price = $form->priceFor(['sponsorship' => $value, 'people' => 1]);
            $chosen = $form->chosenPriceIn(['sponsorship' => $value]);
            $levels[$price['label']] = [FormPayment::toMinor($price['unit']), $chosen['perQuantity'], $chosen['reservesDate']];
        }

        $this->assertSame([
            'Individual Iftar' => [1800, true, false],
            'Quarter Iftar' => [45000, false, true],
            'Half Iftar' => [95000, false, true],
            'Full Iftar' => [190000, false, true],
        ], $levels);

        $day = collect($form->sections()[0]['fields'])->firstWhere('name', 'iftar_day');
        $this->assertSame(FormOptionSources::RESERVABLE_DATES, $day['optionsSource']);
    }

    #[Test]
    public function both_files_say_what_mec_must_fill_in_before_switching_them_on(): void
    {
        foreach ([self::ZAKAT, self::IFTAR] as $path) {
            $document = json_decode(file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);

            $this->assertNotEmpty($document['$comment']['mecToFill'] ?? null, "{$path} lists what MEC has not stated");
            $this->assertFalse($document['is_active']);
            $this->assertNull($document['opens_at']);
            $this->assertNull($document['closes_at']);
        }
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->artisan('form:import', ['masjid' => $this->mec->id, 'path' => self::IFTAR, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Form::count());
    }
}
