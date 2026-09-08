<?php

namespace App\Services\Member;

use App\Models\Contact;
use App\Models\ContactServiceInterest;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reading and writing which services a member asked to hear about.
 *
 * ---------------------------------------------------------------------------
 * SERVICE IS NOT A TENANT-SCOPED MODEL — SCOPE IT BY HAND
 * ---------------------------------------------------------------------------
 * `Service` predates the CRM guardrail and carries no `BelongsToMasjid` trait
 * (.claude/rules/tenant-scoping.md names it among the pre-CRM models that still
 * hand-scope). So the bound TenantContext does NOT filter it, and a bare
 * `Service::find($id)` would happily return another organisation's row.
 *
 * Every id that arrives from a member is therefore checked against
 * `masjid_id` explicitly before it is written. Without that, a member of one
 * masjid could subscribe to — and be counted in the audience of — a service
 * belonging to a different one, which is both a cross-tenant write and a way to
 * receive another organisation's notifications.
 *
 * `ContactServiceInterest` DOES use the trait, so its own reads and writes are
 * scoped normally. The mixture is the trap; hence this class rather than a
 * `sync()` call at a controller.
 */
class MemberInterestService
{
    /**
     * The tenant's services, each flagged with whether this member wants them.
     *
     * The whole catalogue every time, not just the chosen ones: this backs a
     * screen of toggles, and a client that only knew the "on" set could not
     * render the "off" ones without a second call.
     *
     * @return Collection<int, array{id:int,title:?string,summary:?string,interested:bool}>
     */
    public function list(Contact $contact): Collection
    {
        $chosen = ContactServiceInterest::query()
            ->where('contact_id', $contact->id)
            ->pluck('service_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return Service::query()
            ->where('masjid_id', $contact->masjid_id)
            ->orderBy('id')
            ->get()
            ->map(fn (Service $service) => [
                'id' => (int) $service->id,
                'title' => $service->title,
                'summary' => $service->summary,
                'interested' => in_array((int) $service->id, $chosen, true),
            ]);
    }

    /**
     * Replace this member's interests with `$serviceIds`.
     *
     * A REPLACE, because the screen is a set of toggles and the client knows the
     * whole state it wants — a per-toggle add/remove API would drift the moment
     * one call in a burst failed.
     *
     * Applied as a DIFF rather than delete-then-insert so that a service the
     * member kept selected keeps its original `created_at`. That timestamp is
     * the only record of when somebody opted in, and rewriting it on every
     * unrelated toggle would destroy the answer to "when did they ask for
     * this?" — the question a complaint about unwanted notifications asks.
     *
     * @param  array<int, int>  $serviceIds
     * @return array{added:int, removed:int, kept:int}
     */
    public function set(Contact $contact, array $serviceIds): array
    {
        // Only ids that really are this tenant's services survive. Anything
        // else — another masjid's service, a deleted one, a fabricated id — is
        // dropped silently rather than 422'd: the response would otherwise
        // confirm which service ids exist in which organisation.
        $valid = Service::query()
            ->where('masjid_id', $contact->masjid_id)
            ->whereIn('id', array_unique(array_map('intval', $serviceIds)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return DB::transaction(function () use ($contact, $valid): array {
            $current = ContactServiceInterest::query()
                ->where('contact_id', $contact->id)
                ->pluck('service_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $toAdd = array_values(array_diff($valid, $current));
            $toRemove = array_values(array_diff($current, $valid));

            if ($toRemove !== []) {
                ContactServiceInterest::query()
                    ->where('contact_id', $contact->id)
                    ->whereIn('service_id', $toRemove)
                    ->delete();
            }

            foreach ($toAdd as $serviceId) {
                // masjid_id is stamped by the BelongsToMasjid creating hook from
                // the bound tenant, never from the request.
                ContactServiceInterest::create([
                    'contact_id' => $contact->id,
                    'service_id' => $serviceId,
                ]);
            }

            return [
                'added' => count($toAdd),
                'removed' => count($toRemove),
                'kept' => count(array_intersect($valid, $current)),
            ];
        });
    }
}
