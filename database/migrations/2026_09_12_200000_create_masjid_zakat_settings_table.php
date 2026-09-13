<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organization's own nisab price (T-043c).
 *
 * The zakat calculator shipped with no price anywhere, so every tenant's
 * endpoint answered "threshold unknown" for ever. This table is where a price
 * arrives from — typed by the office, dated by the office, attributed by the
 * office — and it is deliberately the ONLY new place a price can come from.
 *
 * ## Why a table, and not a column on `masjids`
 *
 * `masjids` is the tenant root: it is not `BelongsToMasjid`, and its row is
 * published to anonymous callers, which is how a Maps key, a Stripe account id
 * and a tax id once leaked out of the device endpoint (Masjid::
 * PUBLIC_DIRECTORY_DENYLIST exists because of it). A nisab price is not secret,
 * but a column added to that row inherits that publication path by default and
 * has to be argued about every time the denylist is reviewed. Its own scoped
 * table costs one join and closes the question — the IqamaTimeSetting /
 * ThemeSetting shape.
 *
 * ## Why the price is an integer, and why the DATE is not decoration
 *
 * `*_price_per_gram_minor` is an integer count of MINOR UNITS (cents per gram).
 * A decimal column here would re-import float money into a codebase that is
 * integer end to end, and this figure is multiplied by a gram weight and then
 * divided by 40 — the two operations a float error would hide inside.
 *
 * `<metal>_price_quoted_on` is the day the office read THAT metal's price off a
 * market source. It is what makes the number falsifiable: without it nobody —
 * not the office, not the donor, not this code — can tell a quote taken this
 * morning from one taken last Ramadan. .claude/rules/zakat.md: "a stale
 * threshold silently tells a payer they owe nothing when they do." The
 * application requires the date whenever that metal's price is present
 * (SaveZakatSettingRequest); the columns are nullable only because a row may
 * legitimately hold a BASIS preference, or one metal's price and not the
 * other's.
 *
 * ## Why the date is PER METAL and not one column for the row
 *
 * The two prices are edited independently — an office that publishes the silver
 * threshold has no reason to revisit gold for months — so a single row-level
 * `price_quoted_on` is re-dated by whichever metal was touched last. Re-quoting
 * gold on 20 September would silently stamp "20 September" onto a silver price
 * read on 1 June, and the calculator, which resolves the price PER METAL
 * (ZakatCalculator::nisab), would then call that silver figure current, hand a
 * donor a hard meets_nisab verdict from it, and print a date it was never read
 * on. A price and the day it was true have to travel together or the date
 * stops being evidence, so each metal carries its own — the column layout IS
 * the guarantee, not a convention the write path has to remember.
 *
 * `<metal>_price_quoted_from` is the office's own citation of where THAT quote
 * came from ("kitco.com spot, 2026-09-12"), shown to the reader beside the
 * figure. Split per metal for the same reason as the date: one citation for two
 * independently-read prices would misattribute whichever one it did not describe.
 * Free text on purpose — this is a provenance note a human wrote, not an
 * enumeration this code can interpret.
 *
 * `nisab_basis` is a plain string, never a DB enum — the two values live as PHP
 * constants on App\Support\ZakatCalculator (.claude/rules/zakat.md, and the
 * house rule that enumerations are constants). NULL means "no organizational
 * preference; use the deployment default", which is a third state a DB enum
 * would not have expressed any better.
 *
 * One row per masjid, enforced by a unique index named by hand and well inside
 * MySQL's invisible 64-character identifier cap. A NEW table, so
 * `->constrained()` is safe here — the SQLite rebuild trap that drops partial
 * indexes is about adding a foreign key to an EXISTING table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_zakat_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();

            // 'gold' | 'silver' | null. Validated against ZakatCalculator::BASES.
            $table->string('nisab_basis', 10)->nullable();

            // Cents per GRAM. Unsigned: a negative metal price is not a figure.
            $table->unsignedBigInteger('gold_price_per_gram_minor')->nullable();
            $table->unsignedBigInteger('silver_price_per_gram_minor')->nullable();

            // One date and one citation PER METAL. See the note above: a single
            // shared date is re-stamped by whichever price was edited last, and
            // the stale price then reads as current.
            $table->date('gold_price_quoted_on')->nullable();
            $table->string('gold_price_quoted_from', 255)->nullable();

            $table->date('silver_price_quoted_on')->nullable();
            $table->string('silver_price_quoted_from', 255)->nullable();

            // Who last touched the figure, so a wrong threshold has a person to
            // ask rather than only a timestamp. Not a foreign key: a departed
            // admin's deleted user row must not cascade a masjid's price away.
            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->timestamps();

            $table->unique('masjid_id', 'masjid_zakat_settings_masjid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_zakat_settings');
    }
};
