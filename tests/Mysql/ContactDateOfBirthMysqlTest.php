<?php

/*
|--------------------------------------------------------------------------
| A student's date of birth, on the engine production runs
|--------------------------------------------------------------------------
|
| tests/Feature/StudentBirthDateTest.php runs on SQLite, which gives a column no
| real type: it would store the ciphertext of a date in a DATE or a VARCHAR(10)
| column without a word. MySQL in strict mode refuses it, as a 500 on the
| office's first save. So the two things only MySQL can show are pinned here:
|
|  - `contacts.date_of_birth` is TEXT (the value is written through the model's
|    `encrypted` cast, and what is stored is many times longer than a date);
|  - a date written through the model's one writer goes in and comes back, and
|    what sits in the column is not the date.
|
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Contact;
use App\Models\Masjid;
use Illuminate\Support\Facades\DB;

it('keeps contacts.date_of_birth as a nullable TEXT column, because it holds ciphertext', function () {
    $column = DB::selectOne(
        "SELECT DATA_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'date_of_birth'"
    );

    expect($column)->not->toBeNull('contacts.date_of_birth does not exist')
        ->and($column->type)->toBe('text')
        ->and($column->nullable)->toBe('YES');
});

it('stores a date of birth written through the model and reads it back, in strict mode', function () {
    $org = Masjid::create([
        'name' => 'Birth Date MySQL Org ' . uniqid(),
        'org_type' => 'school',
        'email' => 'dob-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);
    $child = Contact::factory()->create(['masjid_id' => $org->id, 'email' => null]);

    expect($child->recordDateOfBirth('2018-02-28', null, 'roster'))->toBe('set');

    $raw = (string) DB::table('contacts')->where('id', $child->id)->value('date_of_birth');

    expect($raw)->not->toContain('2018-02-28')
        ->and(strlen($raw))->toBeGreaterThan(64)
        ->and(Contact::withoutMasjidScope()->findOrFail($child->id)->dateOfBirthOrNull())->toBe('2018-02-28');
});

it('keeps contacts.age_given as a nullable TEXT column too, and reads an answer back in strict mode', function () {
    $column = DB::selectOne(
        "SELECT DATA_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'age_given'"
    );

    expect($column)->not->toBeNull('contacts.age_given does not exist')
        ->and($column->type)->toBe('text')
        ->and($column->nullable)->toBe('YES');

    $org = Masjid::create([
        'name' => 'Age Given MySQL Org ' . uniqid(),
        'org_type' => 'school',
        'email' => 'age-' . uniqid() . '@example.test',
        'phone' => '+1555' . random_int(1000000, 9999999),
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
        'latitude' => 0.0, 'longitude' => 0.0,
    ]);
    $child = Contact::factory()->create(['masjid_id' => $org->id, 'email' => null]);

    expect($child->recordAgeGiven(6, '2026-09-14', null, 'registration'))->toBe('set');

    $raw = (string) DB::table('contacts')->where('id', $child->id)->value('age_given');

    expect($raw)->not->toContain('2026-09-14')
        ->and(strlen($raw))->toBeGreaterThan(64)
        ->and(Contact::withoutMasjidScope()->findOrFail($child->id)->ageGivenOrNull())->toBe(['age' => 6, 'on' => '2026-09-14']);
});
