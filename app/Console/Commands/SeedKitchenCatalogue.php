<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed a standing kitchen catalogue (MealMenu kind `catalogue`) from a JSON file —
 * MEC's 32 Halal Kitchen trays, carried from its Wix store, in the first instance:
 *
 *   php artisan kitchen:seed-catalogue 13
 *   php artisan kitchen:seed-catalogue 13 --apply --expect-name="Muslim Education Center"
 *
 * A command and not a migration on purpose: it writes one organisation's content,
 * which is an operator's decision made once, on the right database, after looking —
 * a migration would run it everywhere the schema goes, staging and every test
 * database included.
 *
 * GUARDED, in the order they are checked:
 *
 *  - DRY RUN BY DEFAULT. Without --apply it validates the file, prints what it
 *    would create, and writes nothing.
 *  - --apply requires --expect-name, which must equal the organisation's name.
 *    An id is one keystroke from another organisation's; a name is not, so MEC's
 *    menu cannot land on Burlington's board by a typo.
 *  - It never modifies anything. A catalogue with the same title already on the
 *    organisation is a refusal that names it, so a second run is harmless and an
 *    office's edits to prices are never overwritten.
 *  - The file is validated whole before anything is written (a name, a positive
 *    whole-cent price, no dish twice), and the write is one transaction.
 *
 * The catalogue is created as a DRAFT: the office reviews it, sets how it can be
 * paid (the organisation's accepted payment methods) and opens it itself. It
 * prints menu content only — there is no personal data in this file to print.
 *
 * UNDO, under the same guards (dry run by default, --apply only with the exact
 * name):
 *
 *   php artisan kitchen:seed-catalogue 13 --undo --menu=412
 *   php artisan kitchen:seed-catalogue 13 --undo --menu=412 --apply --expect-name="Muslim Education Center"
 *
 * It deletes, by the id --apply printed, that one menu and its dishes — and only
 * a catalogue of this organisation carrying the file's title, so it cannot reach
 * a Friday menu or another catalogue. It refuses a menu that has ANY order: an
 * order is a customer's record, and its lines point at those dishes. A menu the
 * office already deleted on the board (soft-deleted) is found too, and removed for
 * good, which is what lets the seed be run again.
 */
class SeedKitchenCatalogue extends Command
{
    protected $signature = 'kitchen:seed-catalogue
                            {masjid : The organisation id to seed}
                            {--file=database/data/mec-halal-kitchen.json : The catalogue JSON, absolute or relative to the app root}
                            {--expect-name= : The organisation\'s exact name; required with --apply}
                            {--apply : Write the catalogue (or, with --undo, delete it). Without it nothing is written}
                            {--undo : Delete the catalogue an earlier --apply created, named by --menu}
                            {--menu= : With --undo: the menu id the earlier --apply printed}';

    protected $description = 'Seed a standing kitchen catalogue from a JSON file (dry run unless --apply)';

    public function handle(): int
    {
        $masjid = Masjid::find((int) $this->argument('masjid'));

        if (! $masjid) {
            $this->error('No organisation with id ' . (int) $this->argument('masjid') . '.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && ! self::sameName((string) $this->option('expect-name'), (string) $masjid->name)) {
            $this->error("--apply needs --expect-name to equal the organisation's name (id {$masjid->id} is \"{$masjid->name}\"). Nothing was written.");

            return self::FAILURE;
        }

        $path = (string) $this->option('file');
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        try {
            $catalogue = self::read($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage() . ' Nothing was written.');

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            return $this->undo($masjid, $catalogue['title'], $apply);
        }

        // Deleted copies count: a menu the office removed on the board is only
        // soft-deleted, and seeding past it would leave two copies of every dish.
        $existing = MealMenu::withoutMasjidScope()
            ->withTrashed()
            ->where('masjid_id', $masjid->id)
            ->where('title', $catalogue['title'])
            ->first();

        if ($existing) {
            $this->error($existing->trashed()
                ? "\"{$masjid->name}\" has a DELETED menu titled \"{$catalogue['title']}\" (menu {$existing->id}). Remove it for good with --undo --menu={$existing->id} first. Nothing was written."
                : "\"{$masjid->name}\" already has a menu titled \"{$catalogue['title']}\" (menu {$existing->id}). Nothing was written.");

            return self::FAILURE;
        }

        $this->info(($apply ? 'Creating' : 'Would create') . " a DRAFT catalogue \"{$catalogue['title']}\" for \"{$masjid->name}\" (id {$masjid->id}), "
            . "pickup lead {$catalogue['pickup_lead_hours']} hours, " . count($catalogue['items']) . ' dishes:');

        foreach ($catalogue['items'] as $item) {
            $this->line(sprintf('  %-48s %10s', $item['name'], '$' . number_format($item['price_minor'] / 100, 2)));
        }

        if (! $apply) {
            $this->warn('Dry run: nothing was written. Re-run with --apply --expect-name="' . $masjid->name . '" to create it.');

            return self::SUCCESS;
        }

        $menu = DB::transaction(function () use ($masjid, $catalogue) {
            $menu = new MealMenu([
                'title' => $catalogue['title'],
                'kind' => MealMenu::KIND_CATALOGUE,
                'status' => MealMenu::STATUS_DRAFT,
                'pickup_lead_hours' => $catalogue['pickup_lead_hours'],
                'pickup_instructions' => $catalogue['pickup_instructions'],
                'allow_online_payment' => true,
                'allow_pay_at_pickup' => true,
                'collect_customer_email' => true,
                'currency' => $catalogue['currency'],
            ]);
            // Explicit, and the tenant scope is not consulted: a console run is
            // unbound, and the organisation is the one the operator named.
            $menu->masjid_id = $masjid->id;
            $menu->save();

            foreach ($catalogue['items'] as $position => $item) {
                $row = new MealMenuItem([
                    'meal_menu_id' => $menu->id,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'price_minor' => $item['price_minor'],
                    'is_available' => true,
                    'sort_order' => $position,
                ]);
                $row->masjid_id = $masjid->id;
                $row->save();
            }

            return $menu;
        });

        $this->info("Created draft catalogue {$menu->id} with " . count($catalogue['items']) . ' dishes. The office opens it once its payment methods are set.');
        $this->line("To undo: php artisan kitchen:seed-catalogue {$masjid->id} --undo --menu={$menu->id} --apply --expect-name=\"{$masjid->name}\"");

        return self::SUCCESS;
    }

    /**
     * Delete, by id, the catalogue an earlier --apply created, with its dishes.
     * Dry run unless --apply (whose --expect-name was already checked in handle()).
     */
    private function undo(Masjid $masjid, string $title, bool $apply): int
    {
        $menuId = (int) $this->option('menu');

        if ($menuId <= 0) {
            $this->error('--undo needs --menu=<id>, the menu id the earlier --apply printed. Nothing was deleted.');

            return self::FAILURE;
        }

        $menu = MealMenu::withoutMasjidScope()
            ->withTrashed()
            ->where('masjid_id', $masjid->id)
            ->whereKey($menuId)
            ->first();

        if (! $menu || ! $menu->isCatalogue() || $menu->title !== $title) {
            $this->error("\"{$masjid->name}\" has no catalogue {$menuId} titled \"{$title}\". Nothing was deleted.");

            return self::FAILURE;
        }

        $orders = MealOrder::withoutMasjidScope()->where('meal_menu_id', $menu->id)->count();

        if ($orders > 0) {
            $this->error("Catalogue {$menu->id} has {$orders} order(s). Orders are customers' records, so it is not deleted. Nothing was deleted.");

            return self::FAILURE;
        }

        $dishes = MealMenuItem::withoutMasjidScope()->where('meal_menu_id', $menu->id)->count();

        $this->info(($apply ? 'Deleting' : 'Would delete') . " catalogue {$menu->id} \"{$menu->title}\" of \"{$masjid->name}\" and its {$dishes} dishes.");

        if (! $apply) {
            $this->warn('Dry run: nothing was deleted. Re-run with --apply --expect-name="' . $masjid->name . '" to delete it.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($menu): void {
            MealMenuItem::withoutMasjidScope()->where('meal_menu_id', $menu->id)->delete();
            $menu->forceDelete();
        });

        $this->info("Deleted catalogue {$menu->id} and its {$dishes} dishes.");

        return self::SUCCESS;
    }

    private static function sameName(string $expected, string $actual): bool
    {
        return $expected !== '' && mb_strtolower(trim($expected)) === mb_strtolower(trim($actual));
    }

    /**
     * The file, validated whole.
     *
     * @return array{title: string, pickup_lead_hours: int, pickup_instructions: ?string, currency: string, items: list<array{name: string, description: ?string, price_minor: int}>}
     */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException("No file at {$path}.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('That file is not valid JSON: ' . $e->getMessage() . '.');
        }

        $title = is_array($data) ? trim((string) ($data['title'] ?? '')) : '';

        if ($title === '' || mb_strlen($title) > 120) {
            throw new \InvalidArgumentException('The catalogue needs a title of at most 120 characters.');
        }

        $lead = $data['pickup_lead_hours'] ?? MealMenu::DEFAULT_PICKUP_LEAD_HOURS;

        if (! is_int($lead) || $lead < 0 || $lead > 720) {
            throw new \InvalidArgumentException('pickup_lead_hours must be a whole number of hours from 0 to 720.');
        }

        $instructions = $data['pickup_instructions'] ?? null;

        if ($instructions !== null && (! is_string($instructions) || mb_strlen($instructions) > 255)) {
            throw new \InvalidArgumentException('pickup_instructions must be text of at most 255 characters, or null.');
        }

        $currency = strtolower((string) ($data['currency'] ?? 'usd'));

        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('currency must be a three-letter code.');
        }

        $items = $data['items'] ?? null;

        if (! is_array($items) || $items === [] || ! array_is_list($items)) {
            throw new \InvalidArgumentException('The catalogue needs a list of items.');
        }

        $seen = [];
        $clean = [];

        foreach ($items as $i => $item) {
            $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
            $price = is_array($item) ? ($item['price_minor'] ?? null) : null;
            $description = is_array($item) ? ($item['description'] ?? null) : null;

            if ($name === '' || mb_strlen($name) > 255) {
                throw new \InvalidArgumentException("Item {$i} needs a name of at most 255 characters.");
            }

            if (! is_int($price) || $price <= 0) {
                throw new \InvalidArgumentException("\"{$name}\" needs a price in whole cents above zero.");
            }

            if ($description !== null && ! is_string($description)) {
                throw new \InvalidArgumentException("\"{$name}\" has a description that is not text.");
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("\"{$name}\" is listed twice.");
            }

            $seen[$key] = true;
            $clean[] = [
                'name' => $name,
                'description' => $description === null || trim($description) === '' ? null : $description,
                'price_minor' => $price,
            ];
        }

        return [
            'title' => $title,
            'pickup_lead_hours' => $lead,
            'pickup_instructions' => $instructions,
            'currency' => $currency,
            'items' => $clean,
        ];
    }
}
