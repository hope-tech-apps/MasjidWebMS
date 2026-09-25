<?php

namespace App\Services\Imports;

use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\ImportLink;
use App\Models\MobileAppUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import the submissions of a Wix form, from its CSV export, into one
 * organisation's contact messages — marked ANSWERED (owner: "Import, marked
 * answered"), so the inbox gains the history without gaining a to-do list.
 * The console face is App\Console\Commands\ImportWixFormMessages
 * (`wix:import-form-messages`).
 *
 * ## The file, and why the headers are matched loosely
 *
 * MEC's two legacy Wix forms ("Contact": Name, Email, Subject, Message; "Get
 * Subscribers 2": Name, Phone Number, Email Address) have no API that returns
 * their answers, so the owner exports each from the Wix dashboard (Forms &
 * Submissions > Export). This is designed for the export Wix documents for
 * that screen: a CSV with a header row, one row per submission, one column per
 * form field headed by the field's label, plus the submission's date. The exact
 * column names are UNKNOWN until MEC exports, so:
 *
 *  - each header is normalised (case, punctuation, a byte-order mark) and
 *    matched against a list of aliases per field (FIELD_ALIASES), then, for a
 *    field still unmatched, against a keyword (a header containing "email" is
 *    the email);
 *  - `--map="Header=field"` overrides any of that by hand;
 *  - every column that matched nothing is KEPT, appended to the message body
 *    as "Header: value", so an unexpected column loses nothing;
 *  - the dry run prints the mapping (header names only), so the operator sees
 *    how the file was read before anything is written.
 *
 * ## Idempotent
 *
 * Each submission is recorded in `import_links`, keyed on the export's
 * submission id when it has one and otherwise on a keyed hash of the form, the
 * address, the date and the text, so re-running the same file (or a later
 * export that repeats the earlier rows) imports each submission once.
 *
 * ## Keys that cannot be recomputed from an address
 *
 * Senders are keyed on an HMAC of their address (or phone, or name) under the
 * application key, never a plain hash: a plain SHA-256 of an email is a lookup
 * anybody holding a list of addresses can reverse, and these keys sit in
 * `import_links`. The same applies to the fallback submission key. Rotating
 * APP_KEY between two runs therefore makes the second run see new senders and
 * new submissions; the migration applies once, so that is recorded, not
 * engineered around. `import_links` is emptied on staging
 * (config/staging_scrub.php).
 *
 * ## Where a message is filed, and what it does NOT do
 *
 * A contact message needs a sender account, which needs a device row
 * (`contact_us_accounts.mobile_app_user_id` is required and unique): the
 * website form creates both for every sender. This does the same, once per
 * sender address, with a RANDOM device id under an `import-wix-` prefix and no
 * push subscription, so no push audience, prayer alert or device count ever
 * includes it (every such reader filters on `onesignal_subscription_id`). The
 * id is random because the public contact-us and device endpoints find a
 * device by `device_id`: one derived from the sender would let anybody who
 * knows the rule and the address write to the imported sender's account.
 *
 * `created_at` is the submission's own date, so the history sorts where it
 * happened; `answered_at` is the moment of import with no staff name, which is
 * the truth: it was handled on Wix, and marked answered here by the import.
 *
 * It sends nothing: ContactUsNotifier is never called, no reply is written,
 * no contact is created (the contact import owns contacts and consent).
 *
 * `contact_us_messages` is hand-scoped (no BelongsToMasjid; see
 * ContactUsMessage), so every read and write below names `masjid_id` itself.
 */
final class WixFormMessageImport
{
    /** The fields a column can be mapped to. */
    public const FIELDS = ['submission_id', 'submitted_at', 'name', 'first_name', 'last_name', 'email', 'phone', 'subject', 'message'];

    /** Normalised header => field. */
    private const FIELD_ALIASES = [
        'submission_id' => ['submission id', 'submissionid', 'id', 'entry id', 'response id'],
        'submitted_at' => ['submission time', 'submission date', 'submitted at', 'submitted on', 'submitted', 'created date', 'date created', 'created at', 'created', 'date submitted', 'date', 'time', 'timestamp'],
        'name' => ['name', 'full name', 'your name'],
        'first_name' => ['first name', 'first', 'firstname', 'given name'],
        'last_name' => ['last name', 'last', 'lastname', 'surname', 'family name'],
        'email' => ['email', 'email address', 'e mail', 'your email', 'mail'],
        'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'tel', 'telephone', 'cell'],
        'subject' => ['subject', 'topic', 'regarding'],
        'message' => ['message', 'your message', 'comments', 'comment', 'question', 'questions', 'inquiry', 'details', 'how can we help'],
    ];

    /** For a field still unmatched after the aliases: a header containing this word. */
    private const FIELD_KEYWORDS = [
        'email' => 'email',
        'phone' => 'phone',
        'message' => 'message',
        'subject' => 'subject',
    ];

    public const USER_AGENT = 'Imported from the old Wix website';

    // ------------------------------------------------------------ reading

    /**
     * Read the CSV and decide what each column means.
     *
     * @param  array<string, string>  $overrides  header (as written) => field
     * @return array{rows: list<array{line: int, cells: array<string,string>}>|null, headers: list<string>, mapping: array<string,string>, problem: string|null}
     */
    public function read(string $path, array $overrides = []): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['rows' => null, 'headers' => [], 'mapping' => [], 'problem' => 'The file cannot be opened.'];
        }

        $headers = fgetcsv($handle, 0, ',', '"', '');

        if (! is_array($headers) || $headers === [null]) {
            fclose($handle);

            return ['rows' => null, 'headers' => [], 'mapping' => [], 'problem' => 'The file has no header row.'];
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        foreach ($overrides as $header => $field) {
            if (! in_array($field, self::FIELDS, true)) {
                fclose($handle);

                return ['rows' => null, 'headers' => $headers, 'mapping' => [], 'problem' => "--map names an unknown field \"{$field}\" (one of: " . implode(', ', self::FIELDS) . ').'];
            }
            if (! in_array($header, $headers, true)) {
                fclose($handle);

                return ['rows' => null, 'headers' => $headers, 'mapping' => [], 'problem' => "--map names a column \"{$header}\" the file does not have."];
            }
        }

        $mapping = $this->map($headers, $overrides);

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = ['line' => $line, 'cells' => $row];
        }

        fclose($handle);

        if (! in_array('submitted_at', $mapping, true)) {
            return ['rows' => null, 'headers' => $headers, 'mapping' => $mapping,
                'problem' => 'No column holds the submission date. Name it with --map="<column>=submitted_at".'];
        }

        return ['rows' => $rows, 'headers' => $headers, 'mapping' => $mapping, 'problem' => null];
    }

    /**
     * Header => field for every header that means something. Overrides first,
     * then exact aliases, then keywords; each field is claimed at most once.
     *
     * @param  list<string>  $headers
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    public function map(array $headers, array $overrides = []): array
    {
        $mapping = [];

        foreach ($overrides as $header => $field) {
            $mapping[$header] = $field;
        }

        foreach ($headers as $header) {
            if (isset($mapping[$header])) {
                continue;
            }
            $key = self::normaliseHeader($header);

            foreach (self::FIELD_ALIASES as $field => $aliases) {
                if (! in_array($field, $mapping, true) && in_array($key, $aliases, true)) {
                    $mapping[$header] = $field;
                    break;
                }
            }
        }

        foreach (self::FIELD_KEYWORDS as $field => $word) {
            if (in_array($field, $mapping, true)) {
                continue;
            }
            foreach ($headers as $header) {
                if (! isset($mapping[$header]) && preg_match('/\b' . $word . '\b/', self::normaliseHeader($header))) {
                    $mapping[$header] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    public static function normaliseHeader(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($header)));
    }

    // ------------------------------------------------------------ planning

    /**
     * Decide every row, write nothing.
     *
     * @param  list<array{line: int, cells: array<string,string>}>  $rows
     * @param  array<string,string>  $mapping
     * @return array<string,mixed>
     */
    public function plan(int $masjidId, string $form, array $rows, array $mapping, string $timezone, ?string $dateFormat): array
    {
        $formSlug = Str::slug($form) ?: 'form';
        $known = ImportLink::query()->where('source', ImportLink::SOURCE_WIX)
            ->whereIn('kind', [ImportLink::KIND_CONTACT_US_MESSAGE, ImportLink::KIND_CONTACT_US_ACCOUNT])
            ->get(['kind', 'external_id', 'local_id'])
            ->groupBy('kind')
            ->map(fn ($links) => $links->pluck('local_id', 'external_id'));
        $knownMessages = $known[ImportLink::KIND_CONTACT_US_MESSAGE] ?? collect();
        $knownSenders = $known[ImportLink::KIND_CONTACT_US_ACCOUNT] ?? collect();

        $byField = array_flip($mapping);
        $planned = [];
        $refused = [];
        $seen = [];
        $duplicates = 0;
        $already = 0;
        $newSenders = [];

        foreach ($rows as $row) {
            $get = fn (string $field) => isset($byField[$field]) ? ($row['cells'][$byField[$field]] ?? '') : '';

            $submittedAt = $this->date($get('submitted_at'), $timezone, $dateFormat);
            $name = $get('name') !== '' ? $get('name') : trim($get('first_name') . ' ' . $get('last_name'));
            $email = $get('email');
            $phone = $get('phone');

            if ($submittedAt === null) {
                $refused[] = [$row['line'], 'the submission date cannot be read'];

                continue;
            }
            if ($name === '' && $email === '' && $phone === '') {
                $refused[] = [$row['line'], 'no name, email or phone'];

                continue;
            }

            $body = $this->body($form, $get('subject'), $get('message'), $row['cells'], $mapping);

            $submissionId = $get('submission_id');
            $externalId = $submissionId !== ''
                ? $formSlug . ':' . $submissionId
                : $formSlug . ':' . $this->key(implode("\x1F", [
                    (string) $this->addressKey($email), $submittedAt->toIso8601String(), $name, $phone, $body,
                ]));
            if (strlen($externalId) > 191) {
                $externalId = $formSlug . ':' . hash('sha256', $externalId);
            }

            if (isset($seen[$externalId])) {
                $duplicates++;

                continue;
            }
            $seen[$externalId] = true;

            if ($knownMessages->has($externalId)) {
                $already++;

                continue;
            }

            $sender = 'sender:' . $this->key($this->addressKey($email)
                ?? ($phone !== '' ? 'phone:' . preg_replace('/\D+/', '', $phone) : 'name:' . mb_strtolower($name)));

            if (! $knownSenders->has($sender)) {
                $newSenders[$sender] = true;
            }

            $planned[] = [
                'external_id' => $externalId,
                'sender' => $sender,
                'sender_account_id' => $knownSenders[$sender] ?? null,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'body' => $body,
                'submitted_at' => $submittedAt->copy()->setTimezone((string) config('app.timezone', 'UTC')),
            ];
        }

        return [
            'masjid_id' => $masjidId,
            'form' => $form,
            'rows' => count($rows),
            'messages' => $planned,
            'refused' => $refused,
            'duplicates' => $duplicates,
            'already' => $already,
            'new_senders' => count($newSenders),
        ];
    }

    /** @return array<string,int> */
    public function counts(array $plan): array
    {
        return [
            'rows' => $plan['rows'],
            'import' => count($plan['messages']),
            'already' => $plan['already'],
            'duplicates' => $plan['duplicates'],
            'refused' => count($plan['refused']),
            'new_senders' => $plan['new_senders'],
            'notifications' => 0,
        ];
    }

    /**
     * An address as a matching key: trimmed, lower-cased, null when it is not
     * an address. The broadcast opt-out service has the same rule; it is
     * repeated here rather than called because this importer has nothing to do
     * with the opt-out list, and EmailUnsubscribeTest pins the few files that
     * may name it.
     */
    private function addressKey(string $email): ?string
    {
        $key = strtolower(trim($email));

        return $key !== '' && str_contains($key, '@') ? $key : null;
    }

    /** HMAC-SHA256 under the application key; see "Keys that cannot be recomputed from an address". */
    private function key(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function date(string $value, string $timezone, ?string $format): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = $format !== null && $format !== ''
                ? Carbon::createFromFormat($format, $value, $timezone)
                : Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }

        // A date in the future, or before Wix existed, is a misread column.
        if ($date === false || $date->isFuture() || $date->year < 2006) {
            return null;
        }

        return $date;
    }

    /**
     * The message as staff will read it: the subject, the text, and every
     * column nothing else claimed. A sign-up form has no text, so its row
     * still reads as a sentence rather than an empty message.
     *
     * @param  array<string,string>  $cells
     * @param  array<string,string>  $mapping
     */
    private function body(string $form, string $subject, string $message, array $cells, array $mapping): string
    {
        $parts = [];

        if ($subject !== '') {
            $parts[] = 'Subject: ' . $subject;
        }

        $parts[] = $message !== ''
            ? $message
            : 'Submitted the "' . $form . '" form on the old website.';

        $other = [];
        foreach ($cells as $header => $value) {
            if (! isset($mapping[$header]) && $value !== '') {
                $other[] = $header . ': ' . $value;
            }
        }

        if ($other !== []) {
            $parts[] = "Other fields:\n" . implode("\n", $other);
        }

        return implode("\n\n", $parts);
    }

    // ------------------------------------------------------------ applying

    /**
     * Write the plan, all or nothing.
     *
     * @return array<string,int>
     */
    public function apply(array $plan, string $batch): array
    {
        $masjidId = (int) $plan['masjid_id'];
        $written = ['messages' => 0, 'senders' => 0];

        DB::transaction(function () use ($plan, $batch, $masjidId, &$written) {
            $reason = ContactUsReason::firstOrCreate(
                ['text' => $plan['form'] . ' (old website)'],
                ['show_to_users' => false],
            );
            $accounts = [];

            foreach ($plan['messages'] as $m) {
                $accountId = $accounts[$m['sender']] ?? $m['sender_account_id'];

                if ($accountId === null) {
                    $device = MobileAppUser::create([
                        'masjid_id' => $masjidId,
                        'device_id' => 'import-wix-' . $masjidId . '-' . Str::random(40),
                        'user_agent' => self::USER_AGENT,
                    ]);
                    $account = ContactUsAccount::create([
                        'mobile_app_user_id' => $device->id,
                        'email' => $m['email'],
                        'name' => $m['name'],
                        'phone' => $m['phone'] !== '' ? $m['phone'] : null,
                    ]);
                    $accountId = (int) $account->id;
                    $this->link(ImportLink::KIND_CONTACT_US_ACCOUNT, $m['sender'], $accountId, $batch);
                    $written['senders']++;
                }
                $accounts[$m['sender']] = $accountId;

                $message = new ContactUsMessage();
                $message->forceFill([
                    'masjid_id' => $masjidId,
                    'contact_us_account_id' => $accountId,
                    'contact_us_reason_id' => $reason->id,
                    'message' => $m['body'],
                    'answered_at' => now(),
                    'answered_by_user_id' => null,
                    'answered_by_name' => null,
                    'created_at' => $m['submitted_at'],
                    'updated_at' => $m['submitted_at'],
                ])->save();

                $this->link(ImportLink::KIND_CONTACT_US_MESSAGE, $m['external_id'], (int) $message->id, $batch);
                $written['messages']++;
            }
        });

        return $written;
    }

    private function link(string $kind, string $externalId, int $localId, string $batch): void
    {
        ImportLink::create([
            'source' => ImportLink::SOURCE_WIX,
            'kind' => $kind,
            'external_id' => $externalId,
            'local_id' => $localId,
            'created_local' => true,
            'import_batch' => $batch,
        ]);
    }

    // ------------------------------------------------------------ undoing

    /**
     * Remove the messages one run imported, and the sender accounts (with
     * their device rows) it created that no longer hold any message — or refuse
     * in full when staff have since REPLIED to one of them, because the reply
     * is the office's record and it cascades with its message.
     *
     * @return array{refused: list<int>, messages_removed: int, senders_removed: int}
     */
    public function undo(int $masjidId, string $batch): array
    {
        $links = ImportLink::query()->where('source', ImportLink::SOURCE_WIX)->where('import_batch', $batch)
            ->whereIn('kind', [ImportLink::KIND_CONTACT_US_MESSAGE, ImportLink::KIND_CONTACT_US_ACCOUNT])
            ->get();

        $messageIds = $links->where('kind', ImportLink::KIND_CONTACT_US_MESSAGE)->pluck('local_id')->all();

        $replied = DB::table('contact_us_replies')
            ->join('contact_us_messages', 'contact_us_messages.id', '=', 'contact_us_replies.contact_us_message_id')
            ->where('contact_us_messages.masjid_id', $masjidId)
            ->whereIn('contact_us_messages.id', $messageIds)
            ->distinct()
            ->pluck('contact_us_messages.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($replied !== []) {
            return ['refused' => $replied, 'messages_removed' => 0, 'senders_removed' => 0];
        }

        $result = ['refused' => [], 'messages_removed' => 0, 'senders_removed' => 0];

        DB::transaction(function () use ($masjidId, $links, $messageIds, &$result) {
            $result['messages_removed'] = ContactUsMessage::query()
                ->where('masjid_id', $masjidId)
                ->whereIn('id', $messageIds)
                ->delete();

            $keep = [];

            foreach ($links->where('kind', ImportLink::KIND_CONTACT_US_ACCOUNT) as $link) {
                $account = ContactUsAccount::query()->with('mobileAppUser')->find($link->local_id);
                $device = $account?->mobileAppUser;

                // Only a sender row this import made, in this organisation.
                if ($device === null || (int) $device->masjid_id !== $masjidId || $device->user_agent !== self::USER_AGENT) {
                    continue;
                }

                // A later run filed messages under the same sender: the account
                // and its link stay, so that run's messages keep their sender
                // and a further run reuses it.
                if ($account->messages()->exists()) {
                    $keep[] = $link->id;

                    continue;
                }

                // The device row cascades the account (contact_us_accounts FK).
                $device->delete();
                $result['senders_removed']++;
            }

            ImportLink::query()->whereKey($links->pluck('id')->diff($keep)->values())->delete();
        });

        return $result;
    }
}
