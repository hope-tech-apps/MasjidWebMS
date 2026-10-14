<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Form, Group, Masjid, SchoolAdmissionSetting};
use App\Services\Schools\SchoolApplications;
use App\Support\TenantContext;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Staff-only application notes and decisions; every lookup is tenant bounded. */
class SchoolApplicationsController extends Controller
{
    public function __construct(private SchoolApplications $service) {}
    private function school(): int { return (int) app(TenantContext::class)->get(); }
    private function ok(array $data, int $status = 200): JsonResponse { return response()->json(['status' => 'success', 'data' => $data], $status); }
    public function index(): JsonResponse { return $this->ok($this->service->listing($this->school())); }
    public function show($masjid_id, $response_id): JsonResponse { return $this->ok($this->service->detail($this->service->response((int) $response_id, $this->school()))); }
    public function status(Request $request, $masjid_id, $response_id): JsonResponse
    {
        if ($request->has('without_email')) $request->merge(['without_email' => filter_var($request->input('without_email'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        $data = $request->validate(['status' => ['required', Rule::in(SchoolApplications::STATUSES)], 'note' => ['nullable', 'string', 'max:4000'], 'without_email' => ['sometimes', 'required', 'boolean']]);
        return $this->ok($this->service->change($this->service->response((int) $response_id, $this->school()), $data['status'], $data['note'] ?? null, (bool) ($data['without_email'] ?? false), (int) $request->user()->id));
    }
    public function roster(Request $request, $masjid_id, $response_id): JsonResponse
    {
        $data = $request->validate(['entry_index' => ['required', 'integer', 'min:0'], 'group_id' => ['required', 'integer']]);
        return $this->ok($this->service->roster($this->service->response((int) $response_id, $this->school()), (int) $data['entry_index'], Group::findOrFail($data['group_id']), $request->user()), 201);
    }
    public function settings(): JsonResponse { return $this->ok($this->service->setting($this->school())); }
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(collect(SchoolApplications::TEXTS)->mapWithKeys(fn ($v, $k) => [$k.'_text' => ['required', 'string', 'max:8000']])->all());
        foreach ($data as $key => $text) {
            preg_match_all('/\{[^{}]*\}/u', $text, $matches);
            if (array_diff($matches[0], ['{school}', '{children}'])) throw ValidationException::withMessages([$key => 'Use only {school} and {children} as placeholders.']);
        }
        DB::transaction(function () use ($data) {
            $school = Masjid::whereKey($this->school())->lockForUpdate()->firstOrFail();
            abort_unless(\App\Support\SchoolAdmissionsMode::enabled($school, 'school_applications'), 403);
            SchoolAdmissionSetting::updateOrCreate(['masjid_id' => $this->school()], $data);
        });
        return $this->settings();
    }
    public function form($masjid_id, $form_id): JsonResponse
    {
        Form::where('masjid_id', $this->school())->findOrFail($form_id);
        return $this->ok(['application' => in_array((int) $form_id, array_map('intval', $this->service->setting($this->school())['application_form_ids']), true)]);
    }
    public function saveForm(Request $request, $masjid_id, $form_id): JsonResponse
    {
        if ($request->has('application')) $request->merge(['application' => filter_var($request->input('application'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        $data = $request->validate(['application' => ['required', 'boolean']]);
        $form = Form::where('masjid_id', $this->school())->findOrFail($form_id);
        if ($data['application'] && ! \App\Services\Schools\SchoolAdmissionIdentity::hasChildFields($form)) throw ValidationException::withMessages(['application' => 'Choose a registration form with child first and last name questions.']);
        DB::transaction(function () use ($data, $form_id) {
            $school = Masjid::whereKey($this->school())->lockForUpdate()->firstOrFail();
            abort_unless(\App\Support\SchoolAdmissionsMode::enabled($school, 'school_applications'), 403);
            $ids = array_values(array_diff(array_map('intval', $this->service->setting($this->school())['application_form_ids']), [(int) $form_id]));
            if ($data['application']) $ids[] = (int) $form_id;
            SchoolAdmissionSetting::updateOrCreate(['masjid_id' => $this->school()], ['application_form_ids' => $ids]);
        });
        return $this->form($masjid_id, $form_id);
    }
}
