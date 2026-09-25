<?php

namespace App\Services\Imports;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\EmailSuppression;
use App\Models\ImportLink;
use App\Models\SmsSuppression;
use App\Services\Broadcast\EmailSuppressionService;
use App\Services\Member\MemberAccountDeletion;
use App\Services\Sms\PhoneNumber;
use App\Services\Sms\SmsConsentService;
use Illuminate\Support\Facades\DB;

/**
 * Import a Wix contacts export into ONE organisation — staged, idempotent and
 * reversible. The console face is App\Console\Commands\ImportWixContacts
 * (`wix:import-contacts`); every rule lives here.
 *
 * Built for MEC's move off Wix (organisation 13), to be APPLIED only in the week
 * before the domain move, from a fresh read-only pull (owner: "Stage, apply
 * before move"). Nothing here is MEC-specific.
 *
 * ## The file
 *
 * The Wix Contacts v4 objects as `GET /contacts/v4/contacts?fieldsets=FULL`
 * returns them, either wrapped (`{"contacts": [...]}`, the shape the migration's
 * exporter writes, or an API page) or as a bare JSON array. Read per contact:
 * `id`, `info.name`, `primaryEmail{email, subscriptionStatus,
 * deliverabilityStatus}` (falling back to `info.extendedFields.items
 * ["emailSubscriptions.*"]`), `info.emails`, `primaryPhone`, `info.phones`,
 * `info.addresses`, `info.labelKeys`, `memberInfo`, `createdDate`,
 * `updatedDate`. Label display names come from a separate file (the `labels`
 * array of `GET /contacts/v4/labels`); without it a tag is named from its key.
 *
 * ## The consent rule: "Everyone, most blocked" (owner, DECISIONS.md 2026-09-25)
 *
 * Every contact is imported. Manara emails every contact holding an address
 * that is not on `email_suppressions` (BroadcastAudienceResolver::emailAudience)
 * — there is no "never opted in" state — so an address stays mailable ONLY when
 * Wix says it is SUBSCRIBED and its deliverability is VALID. Every other address
 * gets a suppression written in advance, with the reason that says why:
 *
 *   spam complaint on Wix           -> complaint          (an opt-out)
 *   UNSUBSCRIBED on Wix             -> imported_opt_out   (an opt-out)
 *   BOUNCED on Wix                  -> bounce             (precaution)
 *   NOT_SET, PENDING, INACTIVE, ... -> not_opted_in       (precaution)
 *
 * The two OPT-OUTS are written whatever happens to the contact — matched to an
 * existing Manara contact, skipped, or created — because they record a request
 * the person made. The two PRECAUTIONS are written only for a contact this
 * import created: a person already in the organisation's Manara list was put
 * there by Manara's own sign-up or by staff, and a Wix "never subscribed" is not
 * a reason to silence them here.
 *
 * Several Wix records sharing one address are one Manara contact, and the
 * address is mailable only if EVERY one of them is SUBSCRIBED and VALID: the
 * stricter record wins, because the dangerous direction is the one that mails
 * somebody who said no.
 *
 * A suppression is never released by this class, and never deleted by its
 * undo: rows are released only by the subscriber (EmailSuppression). So a
 * re-run where Wix now says SUBSCRIBED for an address an earlier run
 * suppressed as a precaution is COUNTED, not changed.
 *
 * No SMS consent is ever written (nobody opted in to SMS on Wix, and a phone
 * number is not consent). A Wix SMS UNSUBSCRIBED becomes an SMS suppression.
 *
 * ## Matching the organisation's existing contacts
 *
 * Existing contacts are never edited — not a name, not a blank. The import adds
 * only what it owns: tags and suppressions.
 *
 *  - By EMAIL first (the normalised address, placeholders excluded). Several
 *    live contacts with the address: the oldest is used.
 *  - By PHONE only for a Wix contact with NO email, and only when exactly one
 *    live contact has that number. A phone is shared across a household far
 *    more often than an email, so a phone match is trusted only when there is
 *    no address to contradict it; two candidates is a guess, and a separate
 *    contact is created instead.
 *  - A contact the office DELETED in Manara (soft-deleted, or gone since an
 *    earlier run of this import) is not recreated. Its opt-outs still apply.
 *
 * ## Idempotent, and a re-run updates
 *
 * Every Wix id is recorded in `import_links` against the contact it became.
 * A re-run looks there first, so it finds the contact even if its address was
 * corrected since. A contact the import CREATED is updated from the fresh pull
 * (name, email, phone) unless its values no longer match the fingerprint the
 * import last wrote — then somebody edited it in Manara, and the edit is kept.
 *
 * ## Undo removes exactly what one run created
 *
 * See `undo()`. Suppressions are kept, on purpose.
 *
 * ## It sends nothing
 *
 * No mail, no SMS, no push, no notification, no invitation — including to the
 * 64 Wix site members, who come across as ordinary contacts (owner: members
 * deferred). Nothing here dispatches a job or calls a notifier; the only model
 * hooks that fire are Contact's opt-out mirror.
 *
 * The organisation must be BOUND (TenantContext) before any method is called;
 * the command does that, as every console command must.
 */
final class WixContactImport
{
    /** Wix email subscription statuses and deliverability values this class reads. */
    private const SUBSCRIBED = 'SUBSCRIBED';
    private const UNSUBSCRIBED = 'UNSUBSCRIBED';
    private const VALID = 'VALID';
    private const BOUNCED = 'BOUNCED';
    private const SPAM_COMPLAINT = 'SPAM_COMPLAINT';

    /**
     * Reasons that record a request the PERSON made; written even for a contact
     * the import only matched. The others are the import's own precaution.
     */
    private const OPT_OUT_REASONS = [
        EmailSuppression::REASON_COMPLAINT,
        EmailSuppression::REASON_IMPORTED_OPT_OUT,
    ];

    public function __construct(
        private readonly EmailSuppressionService $emailSuppression,
        private readonly SmsConsentService $sms,
    ) {
    }

    // ------------------------------------------------------------ reading

    /**
     * Read the export. Refuses (records null) a file that is not JSON or holds
     * no contact objects; the problem sentence names no person.
     *
     * @return array{records: list<array<string,mixed>>|null, problem: string|null}
     */
    public function read(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return ['records' => null, 'problem' => 'The file is not JSON.'];
        }

        $items = array_is_list($decoded) ? $decoded : ($decoded['contacts'] ?? null);

        if (! is_array($items) || $items === []) {
            return ['records' => null, 'problem' => 'The file holds no contacts (expected a "contacts" array or a JSON array of Wix contacts).'];
        }

        $records = [];
        $withoutId = 0;

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                $withoutId++;

                continue;
            }

            // An export paged while Wix was still updating can hold one id
            // twice; the later revision is the contact as it stands.
            $previous = $records[$item['id']] ?? null;
            if ($previous === null || (int) ($item['revision'] ?? 0) >= $previous['revision']) {
                $records[$item['id']] = $this->normalise($item) + ['revision' => (int) ($item['revision'] ?? 0)];
            }
        }

        if ($withoutId > 0) {
            return ['records' => null, 'problem' => "{$withoutId} entr" . ($withoutId === 1 ? 'y has' : 'ies have') . ' no Wix contact id; this is not a Wix contacts export.'];
        }

        return ['records' => array_values($records), 'problem' => null];
    }

    /**
     * Label key => display name, from the `labels` array of a Wix labels
     * export (`{"labels": [{key, displayName}]}` or a bare array).
     *
     * @return array<string, string>
     */
    public function readLabels(?string $path): array
    {
        if ($path === null || $path === '') {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $items = is_array($decoded) ? (array_is_list($decoded) ? $decoded : ($decoded['labels'] ?? [])) : [];

        $names = [];

        foreach ((array) $items as $label) {
            if (is_array($label) && is_string($label['key'] ?? null) && is_string($label['displayName'] ?? null)) {
                $names[$label['key']] = $label['displayName'];
            }
        }

        return $names;
    }

    /**
     * One Wix contact reduced to what the import uses.
     *
     * @param  array<string,mixed>  $c
     * @return array<string,mixed>
     */
    private function normalise(array $c): array
    {
        $info = (array) ($c['info'] ?? []);
        $extended = (array) ($info['extendedFields']['items'] ?? []);
        $primaryEmail = (array) ($c['primaryEmail'] ?? []);
        $primaryPhone = (array) ($c['primaryPhone'] ?? []);

        $emails = [];
        foreach ((array) ($info['emails']['items'] ?? []) as $e) {
            if (is_array($e) && filled($e['email'] ?? null)) {
                $emails[] = ['email' => trim((string) $e['email']), 'primary' => (bool) ($e['primary'] ?? false)];
            }
        }

        $email = $primaryEmail['email'] ?? ($c['primaryInfo']['email'] ?? null);
        if (! filled($email)) {
            $email = collect($emails)->firstWhere('primary', true)['email'] ?? ($emails[0]['email'] ?? null);
        }
        $email = filled($email) ? trim((string) $email) : null;

        $phones = [];
        foreach ((array) ($info['phones']['items'] ?? []) as $p) {
            if (is_array($p) && filled($p['phone'] ?? null)) {
                $phones[] = [
                    'display' => trim((string) ($p['formattedPhone'] ?? $p['phone'])),
                    'e164' => $p['e164Phone'] ?? PhoneNumber::e164((string) ($p['formattedPhone'] ?? $p['phone'])),
                    'primary' => (bool) ($p['primary'] ?? false),
                ];
            }
        }

        $phoneDisplay = $primaryPhone['formattedPhone'] ?? $primaryPhone['phone'] ?? ($c['primaryInfo']['phone'] ?? null);
        $phoneE164 = $primaryPhone['e164Phone'] ?? null;
        if (! filled($phoneDisplay)) {
            $primary = collect($phones)->firstWhere('primary', true) ?? ($phones[0] ?? null);
            $phoneDisplay = $primary['display'] ?? null;
            $phoneE164 = $primary['e164'] ?? null;
        }
        $phoneDisplay = filled($phoneDisplay) ? trim((string) $phoneDisplay) : null;
        $phoneE164 = filled($phoneE164) ? (string) $phoneE164 : PhoneNumber::e164($phoneDisplay);

        $addresses = [];
        foreach ((array) ($info['addresses']['items'] ?? []) as $a) {
            $formatted = is_array($a) ? trim((string) ($a['address']['formattedAddress'] ?? '')) : '';
            if ($formatted !== '') {
                $addresses[] = preg_replace('/\s*\n\s*/', ', ', $formatted);
            }
        }

        return [
            'id' => (string) $c['id'],
            'first' => trim((string) ($info['name']['first'] ?? '')),
            'last' => trim((string) ($info['name']['last'] ?? '')),
            'email' => $email,
            'address' => EmailSuppressionService::normalize($email),
            'subscription' => strtoupper((string) ($primaryEmail['subscriptionStatus'] ?? $extended['emailSubscriptions.subscriptionStatus'] ?? '')),
            'deliverability' => strtoupper((string) ($primaryEmail['deliverabilityStatus'] ?? $extended['emailSubscriptions.deliverabilityStatus'] ?? '')),
            'other_emails' => array_values(array_filter(
                array_column($emails, 'email'),
                fn ($e) => EmailSuppressionService::normalize($e) !== EmailSuppressionService::normalize($email),
            )),
            'phone' => $phoneDisplay,
            'phone_e164' => $phoneE164,
            'other_phones' => array_values(array_filter(
                array_column($phones, 'display'),
                fn ($p) => $p !== $phoneDisplay,
            )),
            'sms_unsubscribed' => strtoupper((string) ($primaryPhone['subscriptionStatus'] ?? '')) === self::UNSUBSCRIBED,
            'addresses' => $addresses,
            'labels' => array_values(array_filter((array) ($info['labelKeys']['items'] ?? []), 'is_string')),
            'is_member' => isset($c['memberInfo']),
            'created' => (string) ($c['createdDate'] ?? ''),
            'updated' => (string) ($c['updatedDate'] ?? $c['createdDate'] ?? ''),
        ];
    }

    // ------------------------------------------------------------ planning

    /**
     * Decide everything, write nothing. The dry run prints this plan's counts
     * and `apply()` carries out exactly these decisions.
     *
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,string>  $labelNames
     * @return array<string,mixed>
     */
    public function plan(int $masjidId, array $records, array $labelNames): array
    {
        $groups = $this->group($records);

        $links = ImportLink::query()
            ->where('source', ImportLink::SOURCE_WIX)
            ->whereIn('kind', [ImportLink::KIND_CONTACT, ImportLink::KIND_TAG])
            ->get()
            ->groupBy('kind');
        $contactLinks = ($links[ImportLink::KIND_CONTACT] ?? collect())->keyBy('external_id');
        $tagLinks = ($links[ImportLink::KIND_TAG] ?? collect())->keyBy('external_id');

        $index = $this->existingContacts();
        $suppressed = array_flip(EmailSuppression::query()->whereNull('released_at')->pluck('email_normalized')->all());

        $tags = $this->planTags($groups, $labelNames, $tagLinks);

        foreach ($groups as &$group) {
            $linked = collect($group['wix_ids'])->map(fn ($id) => $contactLinks[$id] ?? null)->filter()->values();
            $group['unlinked_ids'] = array_values(array_diff($group['wix_ids'], $linked->pluck('external_id')->all()));

            if ($linked->isNotEmpty()) {
                $link = $linked->sortByDesc('created_local')->first();
                $contact = $index['by_id'][$link->local_id] ?? null;

                if ($contact === null || $contact['trashed']) {
                    $group['action'] = 'skip_deleted';
                } elseif ($link->created_local) {
                    $group['action'] = 'update_linked';
                    $group['contact_id'] = $link->local_id;
                    $group['fingerprint_ok'] = hash_equals((string) $link->fingerprint, $this->fingerprint($contact['values']));
                    $group['changes'] = $group['fingerprint_ok'] && $contact['values'] !== $this->values($group);
                } else {
                    $group['action'] = 'linked';
                    $group['contact_id'] = $link->local_id;
                }
            } elseif ($group['address'] !== null) {
                $live = $index['email'][$group['address']] ?? [];

                if ($live !== []) {
                    $group['action'] = 'match_email';
                    $group['contact_id'] = min($live);
                } elseif (isset($index['email_trashed'][$group['address']])) {
                    $group['action'] = 'skip_deleted';
                } else {
                    $group['action'] = 'create';
                }
            } elseif ($group['phone_e164'] !== null && isset($index['phone'][$group['phone_e164']])) {
                $live = $index['phone'][$group['phone_e164']];

                if (count($live) === 1) {
                    $group['action'] = 'match_phone';
                    $group['contact_id'] = $live[0];
                } else {
                    $group['action'] = 'create';
                    $group['ambiguous_phone'] = true;
                }
            } elseif ($group['phone_e164'] !== null && isset($index['phone_trashed'][$group['phone_e164']])) {
                $group['action'] = 'skip_deleted';
            } else {
                $group['action'] = 'create';
            }

            // Which suppression, if any, this address gets written.
            $createdByImport = $group['action'] === 'create' || $group['action'] === 'update_linked';
            $group['suppress'] = null;

            if ($group['address'] !== null && $group['reason'] !== null) {
                $isOptOut = in_array($group['reason'], self::OPT_OUT_REASONS, true);

                if ($isOptOut || $createdByImport) {
                    $group['suppress'] = $group['reason'];
                }
            }

            $group['already_suppressed'] = $group['address'] !== null && isset($suppressed[$group['address']]);
        }
        unset($group);

        return [
            'masjid_id' => $masjidId,
            'records' => count($records),
            'groups' => $groups,
            'tags' => $tags,
        ];
    }

    /**
     * Wix records collapsed into people: one group per normalised address, and
     * one per E.164 number among records with no address.
     *
     * @param  list<array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function group(array $records): array
    {
        $byKey = [];

        foreach ($records as $r) {
            $key = $r['address'] !== null ? 'e:' . $r['address']
                : ($r['phone_e164'] !== null ? 'p:' . $r['phone_e164'] : 'id:' . $r['id']);
            $byKey[$key][] = $r;
        }

        $groups = [];

        foreach ($byKey as $members) {
            // The most recently updated record names the person; one with a
            // name beats a newer one without.
            usort($members, fn ($a, $b) => strcmp($b['updated'], $a['updated']));
            $named = array_values(array_filter($members, fn ($r) => $r['first'] !== '' || $r['last'] !== ''));
            $rep = $named[0] ?? $members[0];

            $phone = $rep['phone'] ?? collect($members)->pluck('phone')->filter()->first();
            $phoneE164 = $rep['phone_e164'] ?? collect($members)->pluck('phone_e164')->filter()->first();

            $groups[] = [
                'wix_ids' => array_column($members, 'id'),
                'first' => $rep['first'],
                'last' => $rep['last'],
                'email' => $rep['email'] ?? collect($members)->pluck('email')->filter()->first(),
                'address' => $rep['address'],
                'phone' => $phoneE164 ?? $phone,
                'phone_e164' => $phoneE164,
                'notes' => $this->notes($members, $phone),
                'labels' => array_values(array_unique(array_merge(...array_column($members, 'labels')))),
                'reason' => $this->reason($members),
                'sms_opt_out' => collect($members)->contains('sms_unsubscribed', true) ? $phoneE164 : null,
                'is_member' => collect($members)->contains('is_member', true),
                'merged' => count($members) - 1,
                'action' => null,
                'contact_id' => null,
                'fingerprint_ok' => null,
                'changes' => false,
                'ambiguous_phone' => false,
            ];
        }

        return $groups;
    }

    /**
     * The suppression reason for an address, or null when it stays mailable:
     * mailable only when EVERY record carrying it is SUBSCRIBED and VALID.
     *
     * @param  list<array<string,mixed>>  $members
     */
    private function reason(array $members): ?string
    {
        if ($members[0]['address'] === null) {
            return null;
        }

        $all = collect($members);

        if ($all->every(fn ($r) => $r['subscription'] === self::SUBSCRIBED && $r['deliverability'] === self::VALID)) {
            return null;
        }

        return match (true) {
            $all->contains('deliverability', self::SPAM_COMPLAINT) => EmailSuppression::REASON_COMPLAINT,
            $all->contains('subscription', self::UNSUBSCRIBED) => EmailSuppression::REASON_IMPORTED_OPT_OUT,
            $all->contains('deliverability', self::BOUNCED) => EmailSuppression::REASON_BOUNCE,
            default => EmailSuppression::REASON_NOT_OPTED_IN,
        };
    }

    /**
     * What the contact row cannot hold, kept in its notes so closing the Wix
     * account loses nothing the office had: the other addresses and numbers,
     * postal addresses, and whether the person had a site login.
     *
     * @param  list<array<string,mixed>>  $members
     */
    private function notes(array $members, ?string $keptPhone): string
    {
        $created = collect($members)->pluck('created')->filter()->sort()->first();
        $lines = ['Imported from the old Wix website' . ($created ? ' (Wix contact since ' . substr($created, 0, 10) . ').' : '.')];

        $keptAddress = $members[0]['address'];
        $emails = collect($members)->flatMap(fn ($r) => array_merge([$r['email']], $r['other_emails']))
            ->filter()->unique(fn ($e) => mb_strtolower($e))
            ->reject(fn ($e) => $keptAddress !== null && EmailSuppressionService::normalize($e) === $keptAddress)
            ->values();
        $phones = collect($members)->flatMap(fn ($r) => array_merge([$r['phone']], $r['other_phones']))
            ->filter()->unique()->reject(fn ($p) => $p === $keptPhone)->values();
        $addresses = collect($members)->flatMap(fn ($r) => $r['addresses'])->unique()->values();

        if ($emails->isNotEmpty()) {
            $lines[] = 'Other emails: ' . $emails->implode(', ');
        }
        if ($phones->isNotEmpty()) {
            $lines[] = 'Other phones: ' . $phones->implode(', ');
        }
        foreach ($addresses as $address) {
            $lines[] = 'Address: ' . $address;
        }
        if (collect($members)->contains('is_member', true)) {
            $lines[] = 'Had a login on the old website (not carried over).';
        }

        return implode("\n", $lines);
    }

    /**
     * Every label in the file resolved to a tag decision.
     *
     * @return array<string, array{name: string, action: string, tag_id: int|null}>
     */
    private function planTags(array $groups, array $labelNames, $tagLinks): array
    {
        $existing = ContactTag::query()->get(['id', 'name', 'name_key'])->keyBy('name_key');
        $existingIds = ContactTag::query()->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $tags = [];
        $plannedKeys = [];

        foreach (array_unique(array_merge(...array_column($groups, 'labels') ?: [[]])) as $labelKey) {
            $name = ContactTag::cleanName(mb_substr($labelNames[$labelKey] ?? $this->nameFromKey($labelKey), 0, ContactTag::NAME_MAX));
            $key = ContactTag::keyFor($name);
            $link = $tagLinks[$labelKey] ?? null;

            if ($link !== null) {
                $tags[$labelKey] = isset($existingIds[$link->local_id])
                    ? ['name' => $name, 'action' => 'linked', 'tag_id' => $link->local_id]
                    : ['name' => $name, 'action' => 'deleted', 'tag_id' => null];
            } elseif (isset($existing[$key])) {
                $tags[$labelKey] = ['name' => $name, 'action' => 'match', 'tag_id' => (int) $existing[$key]->id];
            } elseif (isset($plannedKeys[$key])) {
                // Two Wix labels that differ only in case or spacing are one tag.
                $tags[$labelKey] = ['name' => $name, 'action' => 'same_as', 'tag_id' => null, 'same_as' => $plannedKeys[$key]];
            } else {
                $tags[$labelKey] = ['name' => $name, 'action' => 'create', 'tag_id' => null];
                $plannedKeys[$key] = $labelKey;
            }
        }

        return $tags;
    }

    /** "custom.mec-emaillist-1" -> "mec emaillist 1", for a file with no label names. */
    private function nameFromKey(string $key): string
    {
        $bare = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return str_replace(['-', '_'], ' ', $bare) ?: $key;
    }

    /**
     * This organisation's contacts, indexed for matching. Placeholders (card
     * stubs from the donation importer) are never matched: they are not
     * people who gave an address.
     *
     * @return array<string, mixed>
     */
    private function existingContacts(): array
    {
        $index = ['by_id' => [], 'email' => [], 'email_trashed' => [], 'phone' => [], 'phone_trashed' => []];

        Contact::withTrashed()
            ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'is_placeholder', 'deleted_at'])
            ->orderBy('id')
            ->chunk(1000, function ($contacts) use (&$index) {
                foreach ($contacts as $contact) {
                    $trashed = $contact->deleted_at !== null;
                    $index['by_id'][$contact->id] = [
                        'trashed' => $trashed,
                        'values' => [
                            'first_name' => (string) $contact->first_name,
                            'last_name' => (string) $contact->last_name,
                            'email' => $contact->email,
                            'phone' => $contact->phone,
                        ],
                    ];

                    if ($contact->is_placeholder) {
                        continue;
                    }

                    $address = EmailSuppressionService::normalize($contact->email);
                    $e164 = PhoneNumber::e164($contact->phone);

                    if ($address !== null && $trashed) {
                        $index['email_trashed'][$address] = true;
                    } elseif ($address !== null) {
                        $index['email'][$address][] = (int) $contact->id;
                    }

                    if ($e164 !== null && $trashed) {
                        $index['phone_trashed'][$e164] = true;
                    } elseif ($e164 !== null) {
                        $index['phone'][$e164][] = (int) $contact->id;
                    }
                }
            });

        return $index;
    }

    /** @return array{first_name: string, last_name: string, email: ?string, phone: ?string} */
    private function values(array $group): array
    {
        return [
            'first_name' => (string) $group['first'],
            'last_name' => (string) $group['last'],
            'email' => $group['address'] !== null ? $group['email'] : null,
            'phone' => $group['phone'],
        ];
    }

    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode([
            (string) $values['first_name'],
            (string) $values['last_name'],
            (string) ($values['email'] ?? ''),
            (string) ($values['phone'] ?? ''),
        ]));
    }

    // ------------------------------------------------------------ counting

    /**
     * The plan as numbers — the ONLY thing the command prints, so a dry run
     * over real data shows no person.
     *
     * @return array<string, int>
     */
    public function counts(array $plan): array
    {
        $groups = collect($plan['groups']);
        $action = fn (string $a) => $groups->where('action', $a)->count();
        $writes = $groups->filter(fn ($g) => $g['suppress'] !== null && ! $g['already_suppressed']);

        return [
            'records' => $plan['records'],
            'site_members' => $groups->where('is_member', true)->count(),
            'people' => $groups->count(),
            'duplicates_merged' => $groups->sum('merged'),
            'create' => $action('create'),
            'phone_matched_several' => $groups->where('ambiguous_phone', true)->count(),
            'matched_email' => $action('match_email'),
            'matched_phone' => $action('match_phone'),
            'already_imported_unchanged' => $groups->filter(fn ($g) => $g['action'] === 'linked'
                || ($g['action'] === 'update_linked' && $g['fingerprint_ok'] && ! $g['changes']))->count(),
            'already_imported_updated' => $groups->filter(fn ($g) => $g['action'] === 'update_linked' && $g['fingerprint_ok'] && $g['changes'])->count(),
            'already_imported_edited_kept' => $groups->filter(fn ($g) => $g['action'] === 'update_linked' && ! $g['fingerprint_ok'])->count(),
            'deleted_in_manara_skipped' => $action('skip_deleted'),
            'no_email' => $groups->whereNull('address')->count(),
            'mailable' => $groups->filter(fn ($g) => $g['address'] !== null && $g['reason'] === null && $g['action'] !== 'skip_deleted')->count(),
            'suppress_complaint' => $writes->where('suppress', EmailSuppression::REASON_COMPLAINT)->count(),
            'suppress_opt_out' => $writes->where('suppress', EmailSuppression::REASON_IMPORTED_OPT_OUT)->count(),
            'suppress_bounce' => $writes->where('suppress', EmailSuppression::REASON_BOUNCE)->count(),
            'suppress_not_opted_in' => $writes->where('suppress', EmailSuppression::REASON_NOT_OPTED_IN)->count(),
            'opt_outs_on_existing_contacts' => $writes->filter(fn ($g) => in_array($g['action'], ['match_email', 'match_phone', 'linked', 'skip_deleted'], true))->count(),
            'already_suppressed' => $groups->filter(fn ($g) => $g['suppress'] !== null && $g['already_suppressed'])->count(),
            'suppressed_but_now_subscribed' => $groups->filter(fn ($g) => $g['address'] !== null && $g['reason'] === null
                && $g['already_suppressed'] && in_array($g['action'], ['update_linked', 'linked'], true))->count(),
            'existing_left_mailable_by_rule' => $groups->filter(fn ($g) => $g['reason'] !== null && $g['suppress'] === null)->count(),
            'sms_opt_outs' => $groups->whereNotNull('sms_opt_out')->count(),
            'sms_consents' => 0,
            'labels' => count($plan['tags']),
            'tags_create' => collect($plan['tags'])->where('action', 'create')->count(),
            'tags_reused' => collect($plan['tags'])->whereIn('action', ['match', 'linked', 'same_as'])->count(),
            'tags_deleted_in_manara' => collect($plan['tags'])->where('action', 'deleted')->count(),
            'tag_assignments' => $groups->reject(fn ($g) => $g['action'] === 'skip_deleted')->sum(fn ($g) => count($g['labels'])),
        ];
    }

    // ------------------------------------------------------------ applying

    /**
     * Carry out the plan, all or nothing, in one transaction. Returns what was
     * actually written.
     *
     * @return array<string, int>
     */
    public function apply(array $plan, string $batch): array
    {
        $masjidId = (int) $plan['masjid_id'];
        $written = ['contacts_created' => 0, 'contacts_updated' => 0, 'links' => 0, 'tags_created' => 0,
            'tag_assignments' => 0, 'email_suppressions' => 0, 'sms_suppressions' => 0];

        DB::transaction(function () use ($plan, $batch, $masjidId, &$written) {
            $tagIds = $this->applyTags($plan['tags'], $batch, $written);

            foreach ($plan['groups'] as $group) {
                $contactId = $this->applyContact($group, $batch, $written);

                if ($contactId !== null) {
                    $this->applyTagAssignments($contactId, $group['labels'], $tagIds, $batch, $written);
                }

                if ($group['suppress'] !== null && ! $this->emailSuppression->isSuppressed($masjidId, $group['address'])) {
                    $this->emailSuppression->suppress($masjidId, $group['address'], $group['suppress']);
                    $written['email_suppressions']++;
                }

                if ($group['sms_opt_out'] !== null && ! $this->sms->isSuppressed($masjidId, $group['sms_opt_out'])) {
                    $this->sms->suppress($masjidId, $group['sms_opt_out'], SmsSuppression::REASON_MANUAL);
                    $written['sms_suppressions']++;
                }
            }
        });

        return $written;
    }

    /** @return array<string, int> label key => tag id */
    private function applyTags(array $tags, string $batch, array &$written): array
    {
        $ids = [];

        foreach ($tags as $labelKey => $tag) {
            if ($tag['action'] === 'create') {
                $model = ContactTag::create(['name' => $tag['name']]);
                $ids[$labelKey] = (int) $model->id;
                $this->link(ImportLink::KIND_TAG, $labelKey, $model->id, true, null, $batch);
                $written['tags_created']++;
                $written['links']++;
            } elseif ($tag['action'] === 'match') {
                $ids[$labelKey] = (int) $tag['tag_id'];
                $this->link(ImportLink::KIND_TAG, $labelKey, $tag['tag_id'], false, null, $batch);
                $written['links']++;
            } elseif ($tag['action'] === 'linked') {
                $ids[$labelKey] = (int) $tag['tag_id'];
            }
        }

        foreach ($tags as $labelKey => $tag) {
            if ($tag['action'] === 'same_as' && isset($ids[$tag['same_as']])) {
                $ids[$labelKey] = $ids[$tag['same_as']];
                $this->link(ImportLink::KIND_TAG, $labelKey, $ids[$labelKey], false, null, $batch);
                $written['links']++;
            }
        }

        return $ids;
    }

    private function applyContact(array $group, string $batch, array &$written): ?int
    {
        $values = $this->values($group);

        switch ($group['action']) {
            case 'create':
                $contact = Contact::create($values + [
                    'notes' => $group['notes'],
                    'import_batch' => $batch,
                ]);
                $written['contacts_created']++;

                foreach ($group['wix_ids'] as $id) {
                    $this->link(ImportLink::KIND_CONTACT, $id, $contact->id, true, $this->fingerprint($values), $batch);
                    $written['links']++;
                }

                return (int) $contact->id;

            case 'update_linked':
                if ($group['fingerprint_ok'] && $group['changes']) {
                    Contact::query()->findOrFail($group['contact_id'])->fill($values)->save();
                    ImportLink::query()
                        ->where('source', ImportLink::SOURCE_WIX)
                        ->where('kind', ImportLink::KIND_CONTACT)
                        ->where('local_id', $group['contact_id'])
                        ->where('created_local', true)
                        ->update(['fingerprint' => $this->fingerprint($values)]);
                    $written['contacts_updated']++;
                }

                $this->linkNewIds($group, $batch, $written);

                return (int) $group['contact_id'];

            case 'linked':
                $this->linkNewIds($group, $batch, $written);

                return (int) $group['contact_id'];

            case 'match_email':
            case 'match_phone':
                $this->linkNewIds($group, $batch, $written);

                return (int) $group['contact_id'];

            default: // skip_deleted
                return null;
        }
    }

    /**
     * Wix ids in this group that no earlier run recorded — a duplicate that
     * appeared in the fresh pull, or a match made now. Always
     * `created_local = false`: they point at a contact this batch did not
     * create, so this batch's undo must never delete it.
     */
    private function linkNewIds(array $group, string $batch, array &$written): void
    {
        foreach ($group['unlinked_ids'] as $id) {
            $this->link(ImportLink::KIND_CONTACT, $id, $group['contact_id'], false, null, $batch);
            $written['links']++;
        }
    }

    /** @param  array<string, int>  $tagIds */
    private function applyTagAssignments(int $contactId, array $labels, array $tagIds, string $batch, array &$written): void
    {
        foreach (array_unique(array_filter(array_map(fn ($l) => $tagIds[$l] ?? null, $labels))) as $tagId) {
            $exists = DB::table('contact_tag_links')->where('contact_tag_id', $tagId)->where('contact_id', $contactId)->exists();

            if (! $exists) {
                DB::table('contact_tag_links')->insert([
                    'contact_tag_id' => $tagId,
                    'contact_id' => $contactId,
                    'import_batch' => $batch,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $written['tag_assignments']++;
            }
        }
    }

    private function link(string $kind, string $externalId, int $localId, bool $created, ?string $fingerprint, string $batch): void
    {
        ImportLink::create([
            'source' => ImportLink::SOURCE_WIX,
            'kind' => $kind,
            'external_id' => $externalId,
            'local_id' => $localId,
            'created_local' => $created,
            'fingerprint' => $fingerprint,
            'import_batch' => $batch,
        ]);
    }

    // ------------------------------------------------------------ undoing

    /**
     * Remove exactly what one run (`$batch`) created, or refuse in full.
     *
     * Removed: the contacts it CREATED (hard-deleted through the model, so no
     * copy of an imported person lingers behind a soft delete and a corrected
     * re-run does not collide with one), every tag assignment it added —
     * including those on contacts it only matched — the tags it created that
     * nothing else now uses, and its `import_links`, so a later run starts
     * clean.
     *
     * Kept: every contact it matched, untouched; every tag an admin made; and
     * EVERY email and SMS suppression. An opt-out is released only by the
     * subscriber and never deleted (EmailSuppression), and a suppression
     * written as a precaution is the safe direction to leave in place.
     *
     * Refused, removing nothing, when a contact it created has since become
     * the office's record in any way App\Services\Member\MemberAccountDeletion
     * would keep it for (a gift, a card, a class, a login, a tag an admin
     * added...) or a broadcast named it. The refusal names contact ids only.
     *
     * @return array{refused: list<string>, contacts_removed: int, tag_assignments_removed: int, tags_removed: int, tags_kept_in_use: int, suppressions_kept: bool}
     */
    public function undo(string $batch): array
    {
        $links = ImportLink::query()
            ->where('source', ImportLink::SOURCE_WIX)
            ->where('import_batch', $batch)
            ->whereIn('kind', [ImportLink::KIND_CONTACT, ImportLink::KIND_TAG])
            ->get();

        $contactIds = $links->where('kind', ImportLink::KIND_CONTACT)->where('created_local', true)
            ->pluck('local_id')->unique()->values()->all();
        $contacts = Contact::withTrashed()->whereIn('id', $contactIds)->where('import_batch', $batch)->get();

        $refused = [];
        foreach ($contacts as $contact) {
            $held = $this->heldBy($contact, $batch);
            if ($held !== []) {
                $refused[] = "contact {$contact->id}: " . implode(', ', $held);
            }
        }

        $result = ['refused' => $refused, 'contacts_removed' => 0, 'tag_assignments_removed' => 0,
            'tags_removed' => 0, 'tags_kept_in_use' => 0, 'suppressions_kept' => true];

        if ($refused !== []) {
            return $result;
        }

        DB::transaction(function () use ($batch, $links, $contacts, &$result) {
            $tagIds = ContactTag::query()->pluck('id')->all();

            $result['tag_assignments_removed'] = DB::table('contact_tag_links')
                ->whereIn('contact_tag_id', $tagIds)
                ->where('import_batch', $batch)
                ->delete();

            foreach ($contacts as $contact) {
                // Through the model: Contact's force-delete hook removes the
                // credentials, and the foreign keys cascade the rest.
                $contact->forceDelete();
                $result['contacts_removed']++;
            }

            foreach ($links->where('kind', ImportLink::KIND_TAG)->where('created_local', true) as $link) {
                $tag = ContactTag::query()->find($link->local_id);

                if ($tag === null) {
                    continue;
                }

                if ($tag->contacts()->withTrashed()->exists()) {
                    $result['tags_kept_in_use']++;
                } else {
                    $tag->delete();
                    $result['tags_removed']++;
                }
            }

            ImportLink::query()->whereKey($links->pluck('id'))->delete();
        });

        return $result;
    }

    /**
     * Why this imported contact can no longer be removed: every table
     * MemberAccountDeletion counts as the office's record of a person, the
     * login a person may since have claimed, and a broadcast that named them.
     * A tag assignment made by THIS batch is the import's own and does not count.
     *
     * @return list<string>
     */
    private function heldBy(Contact $contact, string $batch): array
    {
        $held = [];

        foreach (MemberAccountDeletion::OFFICE_RECORDS + MemberAccountDeletion::LOGIN_RECORDS as $table => $columns) {
            $query = DB::table($table)->where(function ($q) use ($columns, $contact) {
                foreach ($columns as $column) {
                    $q->orWhere($column, $contact->id);
                }
            });

            if ($table === 'contact_tag_links') {
                $query->where(fn ($q) => $q->whereNull('import_batch')->orWhere('import_batch', '!=', $batch));
            }

            if ($query->exists()) {
                $held[] = $table;
            }
        }

        foreach (['login_email', 'login_enabled_at', 'signup_source', 'verified_at', 'password'] as $column) {
            if (filled($contact->getRawOriginal($column))) {
                $held[] = 'contacts.' . $column;
            }
        }

        $named = Broadcast::query()->whereNotNull('audience_contact_ids')->pluck('audience_contact_ids')
            ->contains(function ($ids) use ($contact) {
                $ids = is_string($ids) ? json_decode($ids, true) : $ids;

                return is_array($ids) && in_array((int) $contact->id, array_map('intval', $ids), true);
            });

        if ($named) {
            $held[] = 'broadcasts';
        }

        return $held;
    }
}
