<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forms\StoreFormStaffCodeRequest;
use App\Models\Form;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use App\Support\FormCashTotals;
use App\Support\FormStaffCodes;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The "Staff codes" panel on a form that takes cash at the gate (DECISIONS.md
 * 2026-09-11): one secret code per staff member.
 *
 * Behind the forms group's auth:sanctum + admin + tenant like every other forms route,
 * and gated by nothing more — no capability, no crm, no new permission
 * (Permission::count() stays 8). The form is resolved through $masjid->forms(), the
 * hand-scoped legacy pattern, and a code through the form's staffCodes(), which the
 * BelongsToMasjid scope filters as well: another masjid's form or code anywhere in the
 * path is a 404, and another masjid in the URL is a 403 (ResolveMasjidTenant).
 *
 * ## The plaintext exists once
 *
 * store() is the only response that ever carries a code. The admin copies it and hands
 * it over; it is stored nowhere (FormStaffCode::issue()) and the response says no-store
 * so no cache keeps it. Nothing here returns the digest, or the id of the device a code is
 * bound to — only whether it is bound, when, and the id's last four characters
 * (deviceHint()), which is enough to see that a code has moved to another phone.
 *
 * ## Revoked, never deleted
 *
 * A code's row is the reconciliation record its cash rows point at, so there is no
 * delete: DELETE revokes, the first press is the one recorded, and the row stays.
 *
 * ## An instant, stated with its timezone
 *
 * Every code expires (the column is NOT NULL). Left out, the expiry is midnight at the
 * end of the form's event day (settings.payment.eventDate) on the masjid's own clock
 * (defaultExpiry()), and a form that names no event day issues no code without an
 * explicit expiry. The day is never guessed. Read off closes_at (online sales ending
 * the night before) or off "today" (codes handed out the evening before), it put every
 * code's end at 00:00 on the festival's own morning. masjids.timezone
 * defaults to 'UTC', which is what an unset timezone reads as, and the end of a festival
 * day in UTC is 8 PM Eastern — mid-festival. So an unset timezone is read as
 * America/New_York, and every answer names the timezone it used and whether it was
 * assumed. The instant is converted to the app's timezone before it is stored, because
 * Eloquent writes a Carbon's wall-clock in whatever zone it carries: an unconverted
 * Eastern midnight would be stored as a UTC midnight, the same four-hour error.
 *
 * Pinned by tests/Feature/FormStaffCodesAdminTest.php.
 */
class FormStaffCodesController extends Controller
{
    /** What an unset (or unknown) masjid timezone is read as (festival brief: MEC's may be unset). */
    public const FALLBACK_TIMEZONE = 'America/New_York';

    /** GET /api/admin/masjids/{masjid_id}/forms/{form_id}/staff-codes */
    public function index($masjid_id, $form_id): JsonResponse
    {
        [$masjid, $form] = $this->resolve($masjid_id, $form_id);
        $tz = self::timezoneFor($masjid);

        $codes = $form->staffCodes()
            ->with(['createdBy:id,name', 'revokedBy:id,name', 'bindingReleasedBy:id,name'])
            ->orderBy('holder_name')
            ->orderBy('id')
            ->get();

        $cash = $this->cashFor($masjid, $form, $codes);

        return response()->json([
            'status' => 'success',
            'data' => $codes->map(fn (FormStaffCode $code) => $this->serialize($code, $tz, $cash->get($code->id)))->values(),
            'meta' => [
                'timezone' => $tz['name'],
                'timezone_assumed' => $tz['assumed'],
                // The form's event day, and what a code added now would expire at, so the
                // panel can say so first. Both null when the form names no day: a code then
                // carries its own expiry, or is not issued.
                'event_date' => $form->eventDate(),
                'default_expires_at' => self::local(self::defaultExpiry($form, $tz['name']), $tz['name']),
                'staff_codes_enabled' => $form->takesStaffCodes(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/admin/masjids/{masjid_id}/forms/{form_id}/staff-codes
     *
     * 201 with `code`, the plaintext, which no other response ever carries. A code may be
     * issued before staff codes are switched on for the form; it opens nothing until
     * they are (FormStaffCodes refuses it).
     */
    public function store(StoreFormStaffCodeRequest $request, $masjid_id, $form_id): JsonResponse
    {
        [$masjid, $form] = $this->resolve($masjid_id, $form_id);
        $tz = self::timezoneFor($masjid);

        $named = trim((string) $request->validated('expires_at')) !== '';
        $expiresAt = self::expiryFrom($request->validated('expires_at'), $form, $tz['name']);

        if ($expiresAt === null || ! $expiresAt->greaterThan(now())) {
            return response()->json([
                'status' => 'failed',
                'data' => ['expires_at' => [self::expiryProblem($expiresAt, $named)]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        [$code, $plain] = FormStaffCode::issue(
            $form,
            (string) $request->validated('holder_name'),
            $expiresAt->setTimezone((string) config('app.timezone')),
            $request->user()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Copy this code now. It will not be shown again.',
            'data' => ['code' => $plain] + $this->serialize($code->fresh(['createdBy:id,name']), $tz, null),
        ], Response::HTTP_CREATED, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * DELETE /api/admin/masjids/{masjid_id}/forms/{form_id}/staff-codes/{code_id}
     *
     * Revokes: the code stops working at once (a phone holding its token included), and
     * the row stays. A second press changes nothing and says so.
     */
    public function revoke(Request $request, $masjid_id, $form_id, $code_id): JsonResponse
    {
        [$masjid, $form] = $this->resolve($masjid_id, $form_id);
        $code = $form->staffCodes()->findOrFail($code_id);

        $first = $code->revoke($request->user());

        return $this->answer($masjid, $form, $code, $first ? 'The code has been revoked.' : 'This code was already revoked.');
    }

    /**
     * POST /api/admin/masjids/{masjid_id}/forms/{form_id}/staff-codes/{code_id}/reset-device
     *
     * A code belongs to the first phone that uses it. When its holder changes phones, this
     * releases it: the next phone to present the code claims it, and the old phone's
     * token stops working with the binding it stood on.
     *
     * Recorded: who released it, when, and how many phones have held the code
     * (FormStaffCode::releaseDevice()). A release is how a code comes to be used from a
     * phone that is not its holder's. The admin who issued a code saw its plaintext, so
     * they could release it, claim it, record walk-ups its holder then owes, and release
     * it back. The panel shows the last release and the count, so that leaves a mark.
     */
    public function resetDevice(Request $request, $masjid_id, $form_id, $code_id): JsonResponse
    {
        [$masjid, $form] = $this->resolve($masjid_id, $form_id);
        $code = $form->staffCodes()->findOrFail($code_id);

        $released = $code->releaseDevice($request->user());

        return $this->answer($masjid, $form, $code, $released
            ? 'The next phone to enter this code will claim it.'
            : 'No phone has claimed this code yet.');
    }

    /**
     * POST /api/admin/masjids/{masjid_id}/forms/{form_id}/staff-codes/clear-lockout
     *
     * Lifts every wrong-code lockout on the form at once — a phone's own, and the
     * form-wide ceiling (FormStaffCodes::clearLockout()). Declared before /{code_id}.
     */
    public function clearLockout($masjid_id, $form_id): JsonResponse
    {
        [, $form] = $this->resolve($masjid_id, $form_id);

        FormStaffCodes::clearLockout($form);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff code lockouts on this form have been cleared.',
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------------ expiry

    /**
     * The timezone a masjid's codes are stated in, and whether it was assumed.
     *
     * 'UTC' is masjids.timezone's column default, i.e. never set: no masjid keeps its day
     * in UTC. A name PHP does not know is no better than none.
     *
     * @return array{name: string, assumed: bool}
     */
    public static function timezoneFor(Masjid $masjid): array
    {
        $name = trim((string) $masjid->timezone);

        if ($name !== '' && strcasecmp($name, 'UTC') !== 0) {
            try {
                return ['name' => (new DateTimeZone($name))->getName(), 'assumed' => false];
            } catch (Throwable) {
                // falls through to the fallback
            }
        }

        return ['name' => self::FALLBACK_TIMEZONE, 'assumed' => true];
    }

    /**
     * Midnight at the end of the form's event day, on the masjid's clock, or null when the
     * form names no event day (Form::eventDate()), and a new code must then carry its own
     * expiry. The panel shows this before a code is added (index()'s
     * meta.default_expires_at).
     *
     * Never inferred. A form carries no other event date, and both guesses fail in an
     * ordinary set-up. Online sales closing the night before (closes_at) and codes handed
     * out the evening before ("today") each put the expiry at 00:00 on the festival's own
     * morning. From then on every exchange and cash entry at the gate is refused, and
     * each refusal counts towards locking the gate out.
     */
    public static function defaultExpiry(Form $form, string $tz): ?CarbonImmutable
    {
        $day = $form->eventDate();

        if ($day === null) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $day, $tz);

        return $start ? $start->addDay()->startOfDay() : null;
    }

    /**
     * The instant a new code stops working, or null when $input cannot be read, or is
     * left out on a form with no event day. A bare date is a whole day: midnight at its
     * end, on the masjid's clock. A date and time is read on the masjid's clock unless it
     * carries its own offset.
     */
    public static function expiryFrom(?string $input, Form $form, string $tz): ?CarbonImmutable
    {
        $input = trim((string) $input);

        if ($input === '') {
            return self::defaultExpiry($form, $tz);
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $input, $tz);

                return $day ? $day->addDay()->startOfDay() : null;
            }

            return CarbonImmutable::parse($input, $tz);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Why store() cannot issue a code with this expiry, in words for the panel's field.
     * $named: the admin typed one, rather than leaving it to the form's event day.
     */
    private static function expiryProblem(?CarbonImmutable $expiresAt, bool $named): string
    {
        return match (true) {
            $named && $expiresAt === null => 'The expiry must be a date, or a date and time.',
            $named => 'The expiry must be in the future.',
            $expiresAt === null => 'Set the event date on this form, or give this code an expiry.',
            default => 'This form\'s event date has passed. Give this code an expiry, or change the event date.',
        };
    }

    // --------------------------------------------------------------- internals

    /**
     * masjid → form, OUTSIDE any try/catch, so another masjid's form is a clean 404.
     *
     * @return array{0: Masjid, 1: Form}
     */
    private function resolve($masjid_id, $form_id): array
    {
        $masjid = Masjid::findOrFail($masjid_id);

        return [$masjid, $masjid->forms()->findOrFail($form_id)];
    }

    /**
     * Each code's cash, over every row on the form (the panel is the code's whole record,
     * not the list's current filter).
     *
     * @param  Collection<int,FormStaffCode>  $codes
     * @return Collection<int,array<string,mixed>> keyed by code id
     */
    private function cashFor(Masjid $masjid, Form $form, Collection $codes): Collection
    {
        return collect(FormCashTotals::for($form->responses()->where('masjid_id', $masjid->id), $codes)['holders'])
            ->where('kind', 'code')
            ->keyBy('staff_code_id');
    }

    private function answer(Masjid $masjid, Form $form, FormStaffCode $code, string $message): JsonResponse
    {
        $code->load(['createdBy:id,name', 'revokedBy:id,name', 'bindingReleasedBy:id,name']);

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $this->serialize(
                $code,
                self::timezoneFor($masjid),
                $this->cashFor($masjid, $form, collect([$code]))->get($code->id)
            ),
        ], Response::HTTP_OK);
    }

    /**
     * One code, as the panel shows it. An explicit allowlist: never the digest, and never
     * the whole id of the phone it is bound to.
     *
     * @param  array{name: string, assumed: bool}  $tz
     * @param  array<string,mixed>|null  $cash  its FormCashTotals holder line
     * @return array<string,mixed>
     */
    private function serialize(FormStaffCode $code, array $tz, ?array $cash): array
    {
        return [
            'id' => $code->id,
            'form_id' => $code->form_id,
            'holder_name' => $code->holder_name,
            'code_hint' => $code->code_hint,
            'expires_at' => self::local($code->expires_at, $tz['name']),
            'timezone' => $tz['name'],
            'timezone_assumed' => $tz['assumed'],
            'expired' => $code->isExpired(),
            'revoked_at' => self::local($code->revoked_at, $tz['name']),
            'revoked_by' => self::person($code->revokedBy),
            'usable' => $code->isUsable(),
            'device_bound' => $code->bound_device_id !== null,
            'bound_device_hint' => self::deviceHint($code->bound_device_id),
            'bound_at' => self::local($code->bound_at, $tz['name']),
            // The trail a release leaves (resetDevice()): how many phones have held the
            // code, and who last freed it for another, and when.
            'binding_count' => (int) $code->binding_count,
            'binding_released_at' => self::local($code->binding_released_at, $tz['name']),
            'binding_released_by' => self::person($code->bindingReleasedBy),
            'use_count' => (int) $code->use_count,
            'last_used_at' => self::local($code->last_used_at, $tz['name']),
            'created_at' => self::local($code->created_at, $tz['name']),
            'created_by' => self::person($code->createdBy),
            'submissions' => (int) ($cash['submissions'] ?? 0),
            'people' => (int) ($cash['people'] ?? 0),
            'cash_minor' => (int) ($cash['cash_minor'] ?? 0),
            'cancelled_submissions' => (int) ($cash['cancelled_submissions'] ?? 0),
            'cancelled_cash_minor' => (int) ($cash['cancelled_cash_minor'] ?? 0),
        ];
    }

    /**
     * The last four characters of the phone a code is bound to, so the panel can tell one
     * phone from another at a glance. Never the whole id: a code plus the id of the phone
     * it is bound to is accepted from ANY phone, so the full id would let anyone who ever
     * saw a code — the admin who issued it included — use it without the reset that would
     * show up here. A short id (none is written today) shows nothing rather than most of
     * itself.
     */
    private static function deviceHint(?string $deviceId): ?string
    {
        $deviceId = trim((string) $deviceId);

        return strlen($deviceId) >= 12 ? substr($deviceId, -4) : null;
    }

    /** An instant on the masjid's clock, with its offset: "2026-10-18T00:00:00-04:00". */
    private static function local(?DateTimeInterface $at, string $tz): ?string
    {
        return $at !== null ? CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String() : null;
    }

    /** @return array{id: int, name: ?string}|null */
    private static function person(?User $user): ?array
    {
        return $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
