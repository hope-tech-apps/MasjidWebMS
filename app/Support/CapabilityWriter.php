<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
 * transaction: the ledger rows and the switches commit or vanish together.
 *
 * apply() is the other writer: the guarded one for an organisation that
 * already exists (Studio W2 S7, R6, R7). The single switch on the live panel
 * and the bulk PATCH both go through it.
 */
final class CapabilityWriter
{
    /**
     * @param  array<string, mixed>  $desired  key => bool; keys not served to this org type are ignored (the request refuses them)
     * @return array{changed: list<array{key: string, enabled: bool}>, unchanged: list<string>}
     */
    public static function applyAtCreation(Masjid $new, array $desired, ?int $actor): array
    {
        if (($desired['class_subjects'] ?? false) === true) ClassSubjectInitializer::assertReady($new);

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

    /**
     * Sets exactly the keys sent on a live organisation, the way the single
     * switch always has (Studio W2 S7).
     *
     * ONLY THE KEYS SENT. Every sent key stores an explicit override, even one
     * equal to the org type's default, and writes one ledger row, no-ops
     * included: a SuperAdmin pressed a button about that key. An unsent key is
     * never read through CapabilityCatalogue::resolve() and never reset, so a
     * decision such as Burlington's `web_pages` off survives a save that did not
     * mention it (R6). The column-backed grants (crm, assistant) are refused:
     * each has its own endpoint with its own cache flushes (R7).
     *
     * ALL OR NOTHING. Every key is checked, and Giving's refusal (which can call
     * Stripe) runs, before the transaction opens; any refusal is a
     * ValidationException in the house {status:'failed', data:{capability:[…]}}
     * envelope and writes nothing for any key. Inside, the organisation row is
     * locked and its overrides re-read, so two writers that loaded the same
     * stale model cannot overwrite each other's JSON. Keys are written in
     * catalogue order. The pivot is never touched (AppFeaturePivot; S2b owns
     * it). The family cache is flushed only once the write has committed.
     *
     * The caller's model is not refreshed: re-read it.
     *
     * @param  array<string, bool>  $changes  key => real PHP boolean; the request coerces strings
     * @return array{changed: list<string>, unchanged: list<string>} changed = the effective value moved; unchanged = it already had that value (stored and ledgered all the same)
     */
    public static function apply(Masjid $org, array $changes, ?int $actor): array
    {
        if (array_key_exists(SchoolSettings::SCHOOL_CALENDAR_TERMS, $changes)) {
            return SchoolCalendarCapabilityWriter::apply($org, $changes, $actor);
        }

        if ($changes === []) {
            throw new InvalidArgumentException('apply() needs at least one capability.');
        }

        foreach ($changes as $key => $value) {
            self::assertWritable((string) $key);

            if (! is_bool($value)) {
                // resolve() and this take real booleans only; the request
                // coerces "1"/"0"/"true"/"false" (DECISIONS.md, Studio S1).
                throw new InvalidArgumentException("Capability '{$key}' must be a boolean.");
            }
        }

        if (($changes['giving'] ?? null) === false) {
            $refusal = GivingSwitch::refusalToSwitchOff($org);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['capability' => [$refusal]]);
            }
        }

        $keys = array_values(array_filter(
            array_keys((array) config('capabilities')),
            fn ($key) => array_key_exists($key, $changes)
        ));

        return DB::transaction(function () use ($org, $changes, $actor, $keys) {
            $locked = Masjid::query()->whereKey($org->getKey())->lockForUpdate()->firstOrFail();
            if (($changes['class_subjects'] ?? false) === true) ClassSubjectInitializer::assertReady($locked);
            if (($changes['class_subjects'] ?? null) === false) ClassSubjectDisabler::assertAllowed($locked);

            $overrides = is_array($locked->capability_overrides) ? $locked->capability_overrides : [];
            $flips = [];

            $unchanged = [];
            foreach ($keys as $key) {
                // Hidden no-op: do not materialize an override or an audit row.
                if ($key === 'class_subjects' && ! $locked->hasCapability($key) && $changes[$key] === false) {
                    $unchanged[] = $key;
                    continue;
                }
                $flips[$key] = [
                    'before' => $locked->hasCapability($key),
                    'override_before' => array_key_exists($key, $overrides) ? (bool) $overrides[$key] : null,
                ];
                $overrides[$key] = $changes[$key];
            }

            if ($flips !== []) {
                $locked->capability_overrides = $overrides;
                $locked->updated_by = $actor;
                $locked->save();
            }

            $changed = [];

            foreach ($flips as $key => ['before' => $before, 'override_before' => $overrideBefore]) {
                $after = $locked->hasCapability($key);
                CapabilityLedger::record($locked, $key, $before, $after, $overrideBefore, $actor);

                if ($after === $before) {
                    $unchanged[] = $key;
                } else {
                    $changed[] = $key;
                }
            }

            // /menu and the family's switchers are derived from the switches. A
            // child's switches build a profile inside its PARENT's /menu, hence
            // the family form. After commit: before it, another request could
            // rebuild the entry from rows that are about to vanish.
            DB::afterCommit(fn () => MobileCache::flushFamily($locked));

            return ['changed' => $changed, 'unchanged' => $unchanged];
        });
    }

    /**
     * Refuses a key the capability writers may not store an override for: one
     * that is not a TOP-LEVEL catalogue key ("giving.defaults" names a nested
     * config array and used to store a junk override), and a column-backed
     * grant, which has its own switch. The sentences are the single switch's.
     *
     * @throws ValidationException in the house {status:'failed', data:{capability:[…]}} envelope
     */
    public static function assertWritable(string $key): void
    {
        $catalogue = (array) config('capabilities');
        $definition = array_key_exists($key, $catalogue) ? $catalogue[$key] : null;

        if (! is_array($definition)) {
            throw ValidationException::withMessages(['capability' => ['There is no such capability.']]);
        }

        if (! empty($definition['column'])) {
            throw ValidationException::withMessages(['capability' => ['This capability has its own switch on this screen.']]);
        }
    }
}
