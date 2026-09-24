<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;

/**
 * Writes what a NEW organisation has, from Studio's Step 1 map
 * (docs/manara-studio-w1.md S8, R9, R21, R26).
 *
 * SPARSE, LIKE A WIZARD-MADE ORG. Every served key is compared with
 * CapabilityCatalogue::defaultAtCreation(), which is the state the organisation
 * was just born with. A key that matches is skipped entirely: no override, no
 * column write, no ledger row. Only a departure is stored (the column for the
 * column-backed grants, crm and assistant; otherwise `capability_overrides`)
 * and ledgered once, with the SuperAdmin as actor. So a Studio org whose
 * operator left every switch at its default is indistinguishable from one the
 * wizard made, and a later change to a catalogue default moves it exactly as it
 * moves every other org that never decided.
 *
 * That is a different ledger policy from the single switch, which records a
 * no-op flip too (CapabilityChangeLedgerTest): there a SuperAdmin pressed a
 * button on a live org, here nothing was decided about a key left at its
 * default. DECISIONS.md records both.
 *
 * NO GIVING GUARD. MasjidsController::setCapability refuses Giving off while a
 * monthly gift can bill, and that check may call Stripe. An organisation that
 * is seconds old and inside an uncommitted transaction cannot have a gift, a
 * subscription or a checkout, so the guard would only add a network call to a
 * transaction.
 *
 * It never touches the Mobile App Features pivot (AppFeaturePivot::
 * seedFromSwitches does, after this), and it runs only inside the caller's
 * transaction: the ledger rows and the switches commit or vanish together. The
 * guarded bulk apply() for existing organisations is W2 (R21).
 */
final class CapabilityWriter
{
    /**
     * @param  array<string, mixed>  $desired  key => bool; keys not served to this org type are ignored (the request refuses them)
     * @return array{changed: list<array{key: string, enabled: bool}>, unchanged: list<string>}
     */
    public static function applyAtCreation(Masjid $new, array $desired, ?int $actor): array
    {
        $orgType = $new->orgType();
        $overrides = is_array($new->capability_overrides) ? $new->capability_overrides : [];
        $departures = [];
        $unchanged = [];

        foreach (CapabilityCatalogue::resolve($orgType, $desired) as $key => $value) {
            if ($value === CapabilityCatalogue::defaultAtCreation($key, $orgType)) {
                $unchanged[] = $key;

                continue;
            }

            $departures[$key] = [
                'value' => $value,
                'before' => $new->hasCapability($key),
            ];

            $column = config("capabilities.{$key}.column");

            if (! empty($column)) {
                $new->setAttribute($column, $value);
            } else {
                $overrides[$key] = $value;
            }
        }

        if ($departures !== []) {
            // Not fillable on purpose (Masjid::hasCapability): its writers are
            // the switch endpoint, the demo seeder and this.
            $new->forceFill(['capability_overrides' => $overrides === [] ? null : $overrides]);
            $new->save();
        }

        $changed = [];

        foreach ($departures as $key => ['value' => $value, 'before' => $before]) {
            CapabilityLedger::record($new, $key, $before, $new->hasCapability($key), null, $actor);
            $changed[] = ['key' => $key, 'enabled' => $value];
        }

        // /menu and the family's switchers are derived from the switches. The
        // flush waits for the commit: before it, another request could rebuild
        // the entry from rows that are about to vanish.
        DB::afterCommit(fn () => MobileCache::flushFamily($new));

        return ['changed' => $changed, 'unchanged' => $unchanged];
    }
}
