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
 * nothing.
 *
 * ## What it will not do
 *
 *  - **Run unconfigured.** No URL or token: an info line, exit 0, no request. That
 *    is every box but production; staging must never pull children's records.
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
 *    (FormAttachments::storeFromPath).
 *
 * ## Logging
 *
 * Production logs at `warning` and cron discards this command's output
 * (.claude/rules/shipping.md, backups.md), so every failure, skipped file and
 * unknown field is logged at warning or above. No log line carries a record's
 * contents: fields are named by path, rows by their Manara id, and only
 * ExportFailed's message (written by ExportClient, HTTP status at most) is logged
 * verbatim — any other exception by class alone, because a database error's
 * message carries the child's details it failed to write.
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

    /** A cursor that keeps advancing past this many pages (100,000 rows) is not trusted. */
    private const MAX_PAGES = 500;

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
            'files_stored' => 0,
            'files_skipped' => 0,
            'files_to_fetch' => 0,
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
            ->reject(fn (int $n, string $key) => $key === 'files_to_fetch' && ! $dryRun)
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

    private function syncTable(ExportClient $client, string $table, Form $form, bool $dryRun, bool $includeInsurance): void
    {
        $after = null;
        $pages = 0;
        $unknown = [];
        $fileFields = FormSchema::for($form)->fileFields();

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
                    $this->counts['rows_failed']++;
                    Log::warning('alrazi:sync-website: a row had no usable id or created_at and was skipped.', [
                        'table' => $table,
                        'page' => $pages + 1,
                        'position' => $position,
                    ]);

                    continue;
                }

                try {
                    [$outcome, $response] = $this->upsert($form, $mapped, $fileFields, $dryRun);
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

                foreach ($this->missingFiles($response, $mapped['files'], $fileFields) as $file) {
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

        if ($unknown !== []) {
            $paths = array_keys($unknown);
            sort($paths);
            $this->counts['unknown_keys'] += count($paths);

            // Paths only. The site has grown (or renamed) a field this import does not
            // know; its values are NOT imported until the mapper and the form learn it.
            Log::warning('alrazi:sync-website: the website sent fields this import does not map; they were not imported.', [
                'table' => $table,
                'form_id' => $form->id,
                'paths' => $paths,
            ]);
        }
    }

    /**
     * Create or update the one response for this website row.
     *
     * @param  array<string,mixed>  $mapped  SubmissionMapper's result
     * @param  array<string,array<string,mixed>>  $fileFields
     * @return array{0: string, 1: FormResponse}  ['created'|'updated'|'unchanged', the row]
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
            $data = $schema->only($mapped['data']);

            // A file question's cell holds the stored file's name, which only()
            // just dropped; put back the names of the files already here.
            if (! $isNew) {
                foreach ($response->attachments()->orderBy('id')->get() as $attachment) {
                    if (isset($fileFields[$attachment->field])) {
                        $data[$attachment->field] = $attachment->original_name;
                    }
                }
            }

            $data = self::canonical($form, $data);

            $response->data = $data;
            $response->fill($schema->identity($data));
            $response->submitted_at = $mapped['submitted_at']->setTimezone((string) config('app.timezone', 'UTC'));

            if (! $isNew && ! $response->isDirty()) {
                return ['unchanged', $response];
            }

            if (! $dryRun) {
                $response->external_synced_at = now();
                $response->save();
            }

            return [$isNew ? 'created' : 'updated', $response];
        });
    }

    /**
     * The website's documents this response does not hold yet. One file per
     * question per response, so a field that has an attachment is never fetched
     * again — each document is downloaded once.
     *
     * @param  list<array<string,mixed>>  $files
     * @param  array<string,array<string,mixed>>  $fileFields
     * @return list<array<string,mixed>>
     */
    private function missingFiles(FormResponse $response, array $files, array $fileFields): array
    {
        $held = $response->exists
            ? $response->attachments()->pluck('field')->all()
            : [];

        return array_values(array_filter(
            $files,
            fn (array $file) => isset($fileFields[$file['field']]) && ! in_array($file['field'], $held, true)
        ));
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

            if (($file['size_bytes'] !== null && $file['size_bytes'] > $maxBytes)
                || ($file['content_type'] !== null && ! in_array($file['content_type'], $allowed, true))) {
                $this->skipFile($form, $item, 'its type or size is not one Manara accepts for an attachment');
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
                $bytes = $client->download($url, $maxBytes);
            } catch (ExportFailed $e) {
                $this->skipFile($form, $item, $e->getMessage());

                continue;
            }

            if ($bytes === null) {
                $this->skipFile($form, $item, 'it could not be downloaded, or is larger than the attachment ceiling');

                continue;
            }

            $this->storeFile($form, $item, $bytes);
        }
    }

    /**
     * @param  array{response_id: int, file: array<string,mixed>}  $item
     */
    private function storeFile(Form $form, array $item, string $bytes): void
    {
        $file = $item['file'];
        $tmp = tempnam(sys_get_temp_dir(), 'alrazi-');

        if ($tmp === false) {
            $this->skipFile($form, $item, 'no temporary file could be created');

            return;
        }

        try {
            if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                $this->skipFile($form, $item, 'the temporary file could not be written');

                return;
            }

            $stored = DB::transaction(function () use ($form, $item, $file, $tmp): ?bool {
                $response = FormResponse::query()->whereKey($item['response_id'])->lockForUpdate()->first();

                if ($response === null || $response->attachments()->where('field', $file['field'])->exists()) {
                    return false;
                }

                $name = FormAttachments::storeFromPath($response, $file['field'], $tmp, (string) $file['original_name']);

                if ($name === null) {
                    return null;
                }

                try {
                    $data = $response->data ?? [];
                    $data[$file['field']] = $name;
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

                return true;
            });

            if ($stored === true) {
                $this->counts['files_stored']++;
            } elseif ($stored === null) {
                $this->skipFile($form, $item, 'its type or size is not one Manara accepts for an attachment');
            }
        } catch (\Throwable $e) {
            $this->skipFile($form, $item, 'storing it failed (' . get_class($e) . ')');
        } finally {
            @unlink($tmp);
        }
    }

    /**
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
