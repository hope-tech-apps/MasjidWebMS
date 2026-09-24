<?php

namespace App\Console\Commands;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use App\Support\AlRaziWebsite\ExportClient;
use App\Support\AlRaziWebsite\ExportFailed;
use App\Support\AlRaziWebsite\SubmissionMapper;
use App\Support\FormAttachments;
use App\Support\FormSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copy the Al-Razi school website's registration and careers submissions into two
 * Manara forms, so the school's staff work from one list.
 *
 *   php artisan alrazi:sync-website --dry-run   # read and compare, write nothing
 *   php artisan alrazi:sync-website
 *
 * Scheduled every five minutes (routes/console.php). The first run is the backfill;
 * every later run picks up what the site changed since — in practice a payment
 * status — because each website row is matched to its copy by
 * (form_id, external_ref) and updated in place. A run that changes nothing writes
 * nothing: stored and new answers are compared with object keys sorted, because
 * MySQL returns a JSON column's keys in its own order, not the order written.
 *
 * ## Removals follow the website
 *
 *  - **A document** the website no longer lists for a row (or lists under a new
 *    storage path: the family replaced it) is deleted here THROUGH THE MODEL, so
 *    its bytes leave the private disk, and the new one is fetched. Each slot keeps
 *    a SHA-256 of the source path in `<field>Ref` to notice the change.
 *  - **A row** the export stops returning is only MARKED, in
 *    `data.websiteRemoved` ("Removed from the website on YYYY-MM-DD"), and only
 *    after a complete, error-free export of that table. A complete export of ZERO
 *    rows marks nothing: that is far likelier a broken export than an empty
 *    school. A marked row that comes back is unmarked. Deleting marked rows is a
 *    deliberate act: `alrazi:purge-website-removed`.
 *
 * ## What it will not do
 *
 *  - **Run unconfigured.** No URL or token, or a URL that is not https: exit 0,
 *    no request. That is every box but production; staging must never pull
 *    children's records.
 *  - **Write into the wrong form.** Each form is looked up by (configured
 *    organisation, slug) and must be switched OFF with receipts OFF. A live form
 *    would take anonymous submissions under the school's name, and a form with
 *    receipts on is one call away from emailing every family again. Anything else
 *    is refused with a warning and a non-zero exit, before any request is made.
 *    `masjid_id` on every row is taken from the form, never from the export.
 *  - **Email anyone.** FormNotifier is never called. The website already emailed
 *    the family and the school when they applied.
 *  - **Touch what staff own.** `status` is set to 'new' on create and never again;
 *    `admin_notes` and Manara's own payment columns are never written. The site's
 *    payment state lives in `data.website*` fields, for reference.
 *  - **Import an SSN or the SSN card**, whatever the export sends
 *    (SubmissionMapper), or a file of a type or size the upload path would refuse
 *    (FormAttachments::storeFromPath). A refused document is described for staff
 *    in `<field>Status` and is not fetched again until its source path changes.
 *
 * ## Logging
 *
 * Production logs at `warning` and cron discards this command's output
 * (.claude/rules/shipping.md, backups.md), so every failure, skipped file and
 * unknown field is logged at warning or above — ONCE: a refused document when its
 * status changes, an unknown field path at most once per 30 days per form, not
 * 288 times a day. No log line carries a record's contents: fields are named by
 * path, rows by their Manara id, and only ExportFailed's message (written by
 * ExportClient, HTTP status at most) is logged verbatim — any other exception by
 * class alone, because a database error's message carries the child's details it
 * failed to write.
 */
class SyncAlRaziWebsiteSubmissions extends Command
{
    public const REGISTRATION_SLUG = 'alrazi-website-registration';

    public const CAREERS_SLUG = 'alrazi-website-careers';

    /** Export table => the slug of the form it is copied into. */
    public const TABLES = [
        'registrations' => self::REGISTRATION_SLUG,
        'careers' => self::CAREERS_SLUG,
    ];

    /** The start of the `data.websiteRemoved` marker; the date follows. */
    public const REMOVED_PREFIX = 'Removed from the website on ';

    /** An unknown field path is logged at most once per this many days per form. */
    public const UNKNOWN_KEY_QUIET_DAYS = 30;

    /** A cursor that keeps advancing past this many pages (100,000 rows) is not trusted. */
    private const MAX_PAGES = 500;

    /** Types the website accepts and Manara does not, named for staff. */
    private const TYPE_LABELS = [
        'image/heic' => 'HEIC image',
        'image/heif' => 'HEIF image',
        'image/webp' => 'WEBP image',
        'image/gif' => 'GIF image',
        'image/tiff' => 'TIFF image',
    ];

    /** Counts shown only on a dry run. */
    private const DRY_RUN_COUNTS = ['files_to_fetch', 'files_to_remove', 'rows_to_mark_removed'];

    protected $signature = 'alrazi:sync-website
                            {--dry-run : Read the export and report what would change, without writing anything or fetching documents}';

    protected $description = 'Import the Al-Razi school website\'s registration and careers submissions into their Manara forms';

    /** @var array<string,int> */
    private array $counts = [];

    private bool $failed = false;

    public function handle(): int
    {
        $client = ExportClient::fromConfig();

        if (! $client->isConfigured()) {
            // Once a day, not every five minutes: it is a mistake to fix, not an alarm.
            if ($client->hasInsecureUrl() && Cache::add('alrazi-sync:insecure-url', true, now()->addDay())) {
                Log::warning('alrazi:sync-website: ALRAZI_EXPORT_URL is not https, so the export is treated as not configured and nothing is requested.');
            }

            Log::info('alrazi:sync-website: not configured (ALRAZI_EXPORT_URL / ALRAZI_EXPORT_TOKEN); nothing to do.');
            $this->line('The school website export is not configured. Nothing to do.');

            return self::SUCCESS;
        }

        $masjidId = (int) config('services.alrazi_export.masjid_id', 14);
        $forms = [];

        // Every refusal is decided before the first request, so a misconfigured
        // target never even reads a record.
        foreach (self::TABLES as $table => $slug) {
            $form = Form::query()->where('masjid_id', $masjidId)->where('slug', $slug)->first();

            if ($problem = self::refusal($form, $masjidId, $slug)) {
                Log::warning('alrazi:sync-website refused: ' . $problem, ['slug' => $slug, 'masjid_id' => $masjidId]);
                $this->warn($problem);

                return self::FAILURE;
            }

            $forms[$table] = $form;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeInsurance = (bool) config('services.alrazi_export.include_insurance', false);

        $this->counts = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'rows_failed' => 0,
            'rows_marked_removed' => 0,
            'rows_to_mark_removed' => 0,
            'files_stored' => 0,
            'files_refused' => 0,
            'files_skipped' => 0,
            'files_removed' => 0,
            'files_to_fetch' => 0,
            'files_to_remove' => 0,
            'unknown_keys' => 0,
        ];
        $this->failed = false;

        foreach ($forms as $table => $form) {
            try {
                $this->syncTable($client, $table, $form, $dryRun, $includeInsurance);
            } catch (ExportFailed $e) {
                $this->failed = true;
                Log::warning('alrazi:sync-website: ' . $e->getMessage(), ['table' => $table]);
                $this->warn($e->getMessage());
            }
        }

        $summary = collect($this->counts)
            ->reject(fn (int $n, string $key) => $dryRun
                ? in_array($key, ['rows_marked_removed', 'files_stored', 'files_refused', 'files_skipped', 'files_removed'], true)
                : in_array($key, self::DRY_RUN_COUNTS, true))
            ->map(fn (int $n, string $key) => "{$key}={$n}")
            ->implode(' ');

        Log::info('alrazi:sync-website finished' . ($dryRun ? ' (dry run)' : ''), $this->counts);
        $this->info(($dryRun ? 'Dry run — nothing written. ' : '') . $summary);

        return $this->failed || $this->counts['rows_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Why this form must not receive the import, or null when it may.
     */
    public static function refusal(?Form $form, int $masjidId, string $slug): ?string
    {
        if ($form === null) {
            return "The form \"{$slug}\" does not exist in organisation {$masjidId}. "
                . "Import it first: php artisan form:import {$masjidId} database/forms/{$slug}.json";
        }

        if ((int) $form->masjid_id !== $masjidId) {
            return "The form \"{$slug}\" (id {$form->id}) belongs to another organisation.";
        }

        if ($form->is_active) {
            return "The form \"{$slug}\" (id {$form->id}) is switched on. It must stay off: "
                . 'the website is where families apply, and a live copy would take submissions too.';
        }

        // Absent means ON (FormNotifier::receiptsEnabled), so only an explicit false passes.
        if ((($form->settings ?? [])['confirmationEmail'] ?? true) !== false) {
            return "The form \"{$slug}\" (id {$form->id}) would email receipts. "
                . 'Set settings.confirmationEmail to false: the website already emailed each family.';
        }

        return null;
    }

    /**
     * Whether a response's `data` carries the removed-from-the-website marker.
     * alrazi:purge-website-removed deletes only rows for which this is true.
     */
    public static function isMarkedRemoved(mixed $data): bool
    {
        $marker = is_array($data) ? ($data[SubmissionMapper::REMOVED_FIELD] ?? null) : null;

        return is_string($marker) && str_starts_with($marker, self::REMOVED_PREFIX);
    }

    /**
     * An array with every OBJECT's keys sorted, at any depth, and every list left
     * in its order. Two answers that differ only in key order — which is all MySQL's
     * JSON column changes on the way back — normalise to the same value.
     */
    public static function normalised(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = array_map(fn (mixed $inner) => self::normalised($inner), $value);

        if (! array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    private function syncTable(ExportClient $client, string $table, Form $form, bool $dryRun, bool $includeInsurance): void
    {
        $after = null;
        $pages = 0;
        $unknown = [];
        $fileFields = FormSchema::for($form)->fileFields();

        // external_ref => true for every row this export returned. Only a
        // complete answer may say a row is gone, so a row with no usable id makes
        // this run's answer incomplete, and an ExportFailed (a failed page, a
        // cursor that stalls) leaves this method before the removals are decided.
        $seen = [];
        $complete = true;

        do {
            $page = $client->page($table, $after, $table === 'registrations' && $includeInsurance);
            $pending = [];

            foreach ($page['rows'] as $position => $row) {
                $mapped = $table === 'registrations'
                    ? SubmissionMapper::registration($row, $includeInsurance)
                    : SubmissionMapper::careers($row);

                foreach ($mapped['unknown'] as $path) {
                    $unknown[$path] = true;
                }

                if ($mapped['external_ref'] === null || $mapped['submitted_at'] === null) {
                    $complete = false;
                    $this->counts['rows_failed']++;
                    Log::warning('alrazi:sync-website: a row had no usable id or created_at and was skipped.', [
                        'table' => $table,
                        'page' => $pages + 1,
                        'position' => $position,
                    ]);

                    continue;
                }

                $seen[$mapped['external_ref']] = true;

                try {
                    [$outcome, $response, $toFetch, $removed] = $this->upsert($form, $mapped, $fileFields, $dryRun);
                } catch (\Throwable $e) {
                    $this->counts['rows_failed']++;
                    Log::error('alrazi:sync-website: a row could not be saved.', [
                        'table' => $table,
                        'page' => $pages + 1,
                        'position' => $position,
                        'exception' => get_class($e),
                    ]);

                    continue;
                }

                $this->counts[$outcome]++;

                foreach ($removed as $field => $why) {
                    if ($dryRun) {
                        $this->counts['files_to_remove']++;

                        continue;
                    }

                    $this->counts['files_removed']++;

                    // The question, never the filename or the path.
                    Log::warning($why === 'replaced'
                        ? 'alrazi:sync-website: a document was replaced on the website; the old copy was deleted here and the new one is fetched.'
                        : 'alrazi:sync-website: a document is no longer on the website; its copy was deleted here.', [
                            'form_id' => $form->id,
                            'response_id' => $response->id,
                            'field' => $field,
                        ]);
                }

                foreach ($toFetch as $file) {
                    if ($dryRun) {
                        $this->counts['files_to_fetch']++;

                        continue;
                    }

                    $pending[] = ['response_id' => (int) $response->id, 'file' => $file];
                }
            }

            if ($pending !== []) {
                $this->fetchFiles($client, $form, $pending);
            }

            $next = $page['next'];
            $pages++;

            if ($next !== null && $next === $after) {
                throw new ExportFailed("The export's cursor for {$table} did not advance; stopped.");
            }

            if ($next !== null && $pages >= self::MAX_PAGES) {
                throw new ExportFailed("The export returned more than " . self::MAX_PAGES . " pages of {$table}; stopped.");
            }

            $after = $next;
        } while ($after !== null);

        $this->reportUnknown($table, $form, array_keys($unknown), $dryRun);

        if ($complete) {
            $this->markRemoved($table, $form, $seen, $dryRun);
        }
    }

    /**
     * Create or update the one response for this website row, and settle its
     * documents against what the website lists now.
     *
     * @param  array<string,mixed>  $mapped  SubmissionMapper's result
     * @param  array<string,array<string,mixed>>  $fileFields
     * @return array{0: string, 1: FormResponse, 2: list<array<string,mixed>>, 3: array<string,string>}
     *         ['created'|'updated'|'unchanged', the row, the files to fetch, field => 'removed'|'replaced']
     */
    private function upsert(Form $form, array $mapped, array $fileFields, bool $dryRun): array
    {
        return DB::transaction(function () use ($form, $mapped, $fileFields, $dryRun): array {
            $response = FormResponse::query()
                ->where('form_id', $form->id)
                ->where('external_ref', $mapped['external_ref'])
                ->lockForUpdate()
                ->first();

            $isNew = $response === null;

            if ($isNew) {
                $response = new FormResponse();
                // Neither is fillable; set one by one, on create only. `uuid` is
                // still minted by the model's creating hook.
                $response->form_id = $form->id;
                $response->external_ref = $mapped['external_ref'];
                $response->status = 'new';
                $response->entry_count = 1;
            }

            // From the FORM, never the export, and re-asserted on every save.
            $response->masjid_id = $form->masjid_id;

            $schema = FormSchema::for($form);
            // The website's answers, and a <field>Ref per listed document. Nothing
            // else survives from the last run unless it is put back below — which
            // is how a websiteRemoved marker clears when its row comes back.
            $data = $schema->only($mapped['data']);
            $stored = $isNew ? [] : self::storedData($response);

            $attachments = $isNew
                ? collect()
                : $response->attachments()->orderBy('id')->get()->keyBy('field');
            $listed = collect($mapped['files'])->keyBy('field');

            /** @var array<string,array{0: FormResponseAttachment, 1: string}> $toRemove */
            $toRemove = [];
            $toFetch = [];

            foreach (array_keys($fileFields) as $field) {
                $file = $listed->get($field);
                $attachment = $attachments->get($field);
                $refField = SubmissionMapper::refField($field);
                $sameSource = $file !== null
                    && ! $isNew
                    && ($stored[$refField] ?? null) === ($data[$refField] ?? null);

                if ($attachment !== null) {
                    if ($file === null) {
                        $toRemove[$field] = [$attachment, 'removed'];
                    } elseif (! $sameSource) {
                        $toRemove[$field] = [$attachment, 'replaced'];
                        $toFetch[] = $file;
                    } else {
                        // A file question's cell holds the stored file's name,
                        // which only() just dropped; put it back.
                        $data[$field] = $attachment->original_name;
                    }

                    continue;
                }

                if ($file === null) {
                    continue;
                }

                // Refused before for its type or size, and unchanged on the website
                // since: the row already says so, and it is not fetched again.
                $statusField = SubmissionMapper::statusField($field);
                $status = $stored[$statusField] ?? null;

                if ($sameSource && is_string($status) && $status !== '') {
                    $data[$statusField] = $status;

                    continue;
                }

                $toFetch[] = $file;
            }

            $data = self::canonical($form, $data);

            // Assigned only when it really differs: the array cast compares with
            // ===, which is key-order sensitive, and MySQL hands JSON keys back in
            // its own order — so a plain assignment would mark every row dirty on
            // every run.
            if ($isNew || self::normalised($stored) !== self::normalised($data)) {
                $response->data = $data;
            }

            $response->fill($schema->identity($data));
            $response->submitted_at = $mapped['submitted_at']->setTimezone((string) config('app.timezone', 'UTC'));

            $removed = array_map(fn (array $pair) => $pair[1], $toRemove);

            if (! $isNew && ! $response->isDirty() && $toRemove === []) {
                return ['unchanged', $response, $toFetch, []];
            }

            if (! $dryRun) {
                $response->external_synced_at = now();
                $response->save();

                // Through the model, so its `deleting` hook takes the bytes off the
                // private disk; a query delete would leave the file there, unowned.
                foreach ($toRemove as $pair) {
                    $pair[0]->delete();
                }
            }

            return [$isNew ? 'created' : 'updated', $response, $toFetch, $removed];
        });
    }

    /**
     * Sign, download and store the documents one page of rows is missing.
     *
     * @param  list<array{response_id: int, file: array<string,mixed>}>  $pending
     */
    private function fetchFiles(ExportClient $client, Form $form, array $pending): void
    {
        $maxBytes = max(0, (int) config('forms.attachments.max_size_kb', 8192)) * 1024;
        $allowed = (array) config('forms.attachments.mime_types', []);

        // What the site recorded about a document can rule it out before it is
        // downloaded. It can never rule one IN: storeFromPath() sniffs the bytes.
        $toSign = [];

        foreach ($pending as $i => $item) {
            $file = $item['file'];
            $refusal = null;

            if ($file['size_bytes'] !== null && $file['size_bytes'] > $maxBytes) {
                $refusal = self::tooLargeStatus($maxBytes);
            } elseif ($file['content_type'] !== null && ! in_array($file['content_type'], $allowed, true)) {
                $refusal = self::wrongTypeStatus($file['content_type']);
            }

            if ($refusal !== null) {
                $this->refuseFile($form, $item, $refusal);
                unset($pending[$i]);

                continue;
            }

            $toSign[ExportClient::objectKey($file['bucket'], $file['path'])] = [
                'bucket' => $file['bucket'],
                'path' => $file['path'],
            ];
        }

        if ($toSign === []) {
            return;
        }

        $urls = $client->sign(array_values($toSign));

        foreach ($pending as $item) {
            $file = $item['file'];
            $url = $urls[ExportClient::objectKey($file['bucket'], $file['path'])] ?? null;

            if ($url === null) {
                $this->skipFile($form, $item, 'the export did not sign it');

                continue;
            }

            try {
                [$state, $outcome] = $client->download(
                    $url,
                    $maxBytes,
                    fn (string $path) => $this->storeFile($form, $item, $path)
                );
            } catch (ExportFailed $e) {
                $this->skipFile($form, $item, $e->getMessage());

                continue;
            }

            if ($state === ExportClient::DOWNLOAD_TOO_LARGE) {
                $this->refuseFile($form, $item, self::tooLargeStatus($maxBytes));

                continue;
            }

            if ($state !== ExportClient::DOWNLOAD_OK) {
                $this->skipFile($form, $item, 'it could not be downloaded');

                continue;
            }

            [$result, $detail] = $outcome;

            if ($result === 'stored') {
                $this->counts['files_stored']++;
            } elseif ($result === 'refused') {
                $this->refuseFile($form, $item, self::wrongTypeStatus($detail));
            } elseif ($result === 'failed') {
                $this->skipFile($form, $item, "storing it failed ({$detail})");
            }
        }
    }

    /**
     * Store one downloaded document. The file at $path belongs to
     * ExportClient::download(), which deletes it however this returns.
     *
     * @param  array{response_id: int, file: array<string,mixed>}  $item
     * @return array{0: 'stored'|'held'|'refused'|'failed', 1: ?string}  with the sniffed type when refused, the exception class when failed
     */
    private function storeFile(Form $form, array $item, string $path): array
    {
        $file = $item['file'];

        try {
            return DB::transaction(function () use ($form, $item, $file, $path): array {
                $response = FormResponse::query()->whereKey($item['response_id'])->lockForUpdate()->first();

                if ($response === null || $response->attachments()->where('field', $file['field'])->exists()) {
                    return ['held', null];
                }

                $name = FormAttachments::storeFromPath($response, $file['field'], $path, (string) $file['original_name']);

                if ($name === null) {
                    // The size was checked before this was called, so it is the type.
                    $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

                    return ['refused', is_string($sniffed) ? $sniffed : null];
                }

                try {
                    $data = $response->data ?? [];
                    $data[$file['field']] = $name;
                    unset($data[SubmissionMapper::statusField($file['field'])]);
                    $response->data = self::canonical($form, $data);
                    $response->external_synced_at = now();
                    $response->save();
                } catch (\Throwable $e) {
                    // The attachment row rolls back with this transaction; its bytes
                    // would not, so they go now rather than sit on the disk unowned.
                    $attachment = FormResponseAttachment::query()
                        ->where('form_response_id', $response->id)
                        ->where('field', $file['field'])
                        ->first();

                    if ($attachment) {
                        Storage::disk($attachment->disk)->delete($attachment->path);
                    }

                    throw $e;
                }

                return ['stored', null];
            });
        } catch (\Throwable $e) {
            return ['failed', get_class($e)];
        }
    }

    /**
     * A document Manara will not accept (type or size). Said for staff on the row,
     * in `<field>Status`, and in the log only when that sentence changes; the next
     * run sees the status and does not fetch it again until its path changes.
     *
     * @param  array{response_id: int, file: array<string,mixed>}  $item
     */
    private function refuseFile(Form $form, array $item, string $status): void
    {
        $this->counts['files_refused']++;
        $field = $item['file']['field'];

        try {
            $changed = DB::transaction(function () use ($form, $item, $field, $status): bool {
                $response = FormResponse::query()->whereKey($item['response_id'])->lockForUpdate()->first();

                if ($response === null) {
                    return false;
                }

                $data = $response->data ?? [];
                $statusField = SubmissionMapper::statusField($field);

                if (($data[$statusField] ?? null) === $status) {
                    return false;
                }

                $data[$statusField] = $status;
                $response->data = self::canonical($form, $data);
                $response->external_synced_at = now();
                $response->save();

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('alrazi:sync-website: a refused document\'s status could not be saved.', [
                'form_id' => $form->id,
                'response_id' => $item['response_id'],
                'field' => $field,
                'exception' => get_class($e),
            ]);

            return;
        }

        if ($changed) {
            // `status` is one of this class's own fixed sentences, never the
            // family's words.
            Log::warning('alrazi:sync-website: a document was not stored because Manara does not accept its type or size. The row says so, and it is not fetched again until it changes on the website.', [
                'form_id' => $form->id,
                'response_id' => $item['response_id'],
                'field' => $field,
                'status' => $status,
            ]);
        }
    }

    /**
     * A document that could not be fetched this time (not signed, not downloaded,
     * not saved). Retried on the next run, so warned each time: it should not last.
     *
     * @param  array{response_id: int, file: array<string,mixed>}  $item
     */
    private function skipFile(Form $form, array $item, string $why): void
    {
        $this->counts['files_skipped']++;

        // The Manara row and the question, never the filename or the storage path:
        // both are the family's words (a child's name is a common filename).
        Log::warning("alrazi:sync-website: a document was not stored because {$why}. It will be tried again on the next run.", [
            'form_id' => $form->id,
            'response_id' => $item['response_id'],
            'field' => $item['file']['field'],
        ]);
    }

    /**
     * Unknown field paths, at most once per UNKNOWN_KEY_QUIET_DAYS per (form, path).
     * Paths only: the site has grown (or renamed) a field this import does not
     * know, and its values are NOT imported until the mapper and the form learn it.
     *
     * @param  list<string>  $paths
     */
    private function reportUnknown(string $table, Form $form, array $paths, bool $dryRun): void
    {
        if ($paths === []) {
            return;
        }

        sort($paths);
        $this->counts['unknown_keys'] += count($paths);

        // A dry run is someone at a terminal: tell them everything, and do not
        // spend the scheduled run's one warning.
        $fresh = $dryRun ? $paths : array_values(array_filter(
            $paths,
            fn (string $path) => Cache::add(
                'alrazi-sync:unknown-key:' . $form->id . ':' . sha1($path),
                true,
                now()->addDays(self::UNKNOWN_KEY_QUIET_DAYS)
            )
        ));

        if ($fresh === []) {
            return;
        }

        Log::warning('alrazi:sync-website: the website sent fields this import does not map; they were not imported.', [
            'table' => $table,
            'form_id' => $form->id,
            'paths' => $fresh,
        ]);
    }

    /**
     * Mark (never delete) each imported row of this form that a complete,
     * error-free export did not return.
     *
     * @param  array<string,true>  $seen
     */
    private function markRemoved(string $table, Form $form, array $seen, bool $dryRun): void
    {
        $imported = FormResponse::query()
            ->where('form_id', $form->id)
            ->whereNotNull('external_ref')
            ->orderBy('id')
            ->get(['id', 'external_ref', 'data']);

        if ($seen === []) {
            // Once a day per table, not every five minutes: an export that stays empty
            // would otherwise write this line 288 times a day at warning level.
            if ($imported->isNotEmpty() && ($dryRun || Cache::add("alrazi-sync:empty-export:{$table}", true, now()->addDay()))) {
                Log::warning('alrazi:sync-website: the export returned no rows at all while Manara holds imported ones. That is far likelier a broken export than an empty school, so nothing was marked removed.', [
                    'table' => $table,
                    'form_id' => $form->id,
                    'held' => $imported->count(),
                ]);
                $this->warn("The export returned no {$table} at all; nothing was marked removed.");
            }

            return;
        }

        $marked = [];

        foreach ($imported as $row) {
            if (isset($seen[$row->external_ref]) || self::isMarkedRemoved($row->data)) {
                continue;
            }

            if ($dryRun) {
                $this->counts['rows_to_mark_removed']++;

                continue;
            }

            try {
                $done = DB::transaction(function () use ($form, $row): bool {
                    $locked = FormResponse::query()->whereKey($row->id)->lockForUpdate()->first();

                    if ($locked === null || self::isMarkedRemoved($locked->data)) {
                        return false;
                    }

                    $data = $locked->data ?? [];
                    $data[SubmissionMapper::REMOVED_FIELD] = self::REMOVED_PREFIX . now()->toDateString();
                    $locked->data = self::canonical($form, $data);
                    $locked->external_synced_at = now();
                    $locked->save();

                    return true;
                });
            } catch (\Throwable $e) {
                $this->counts['rows_failed']++;
                Log::error('alrazi:sync-website: a row the website no longer returns could not be marked.', [
                    'form_id' => $form->id,
                    'response_id' => $row->id,
                    'exception' => get_class($e),
                ]);

                continue;
            }

            if ($done) {
                $marked[] = (int) $row->id;
            }
        }

        if ($marked !== []) {
            $this->counts['rows_marked_removed'] += count($marked);

            Log::warning('alrazi:sync-website: rows the website no longer returns were marked removed, not deleted. `php artisan alrazi:purge-website-removed` deletes them.', [
                'table' => $table,
                'form_id' => $form->id,
                'response_ids' => $marked,
            ]);
        }
    }

    /**
     * The row's `data` exactly as the database holds it, decoded — not through the
     * cast, so nothing here depends on how the cast compares.
     *
     * @return array<string,mixed>
     */
    private static function storedData(FormResponse $response): array
    {
        $raw = $response->getRawOriginal('data');

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    private static function tooLargeStatus(int $maxBytes): string
    {
        $mb = 1024 * 1024;
        $limit = $maxBytes >= $mb && $maxBytes % $mb === 0
            ? intdiv($maxBytes, $mb) . ' MB'
            : max(1, intdiv($maxBytes, 1024)) . ' KB';

        return "On the website, not importable here (larger than {$limit})";
    }

    private static function wrongTypeStatus(?string $mime): string
    {
        // Only this class's own labels reach the row: the declared type is the
        // browser's word.
        $label = self::TYPE_LABELS[strtolower(trim((string) $mime))] ?? 'unsupported file type';

        return "On the website, not importable here ({$label})";
    }

    /**
     * The answers in the form's own question order, so the same answers always
     * serialise the same way and a re-run compares equal.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function canonical(Form $form, array $data): array
    {
        $ordered = [];

        foreach ($form->sections() as $section) {
            $sectionId = $section['id'] ?? null;

            if (! empty($section['repeatable']) && $sectionId !== null) {
                if (array_key_exists($sectionId, $data)) {
                    $ordered[$sectionId] = $data[$sectionId];
                }

                continue;
            }

            foreach ($section['fields'] ?? [] as $field) {
                $name = is_array($field) ? ($field['name'] ?? null) : null;

                if (is_string($name) && array_key_exists($name, $data)) {
                    $ordered[$name] = $data[$name];
                }
            }
        }

        return $ordered;
    }
}
