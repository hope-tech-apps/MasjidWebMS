<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Form, Masjid, SchoolReenrolment, SchoolYear};
use App\Services\Schools\SchoolReenrolments;
use App\Support\TenantContext;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Office opening dates, paper answers and explicit reminder requests, behind their own grant. */
class SchoolReenrolmentController extends Controller
{
    public function __construct(private SchoolReenrolments $service) {}
    private function school(): int { return (int) app(TenantContext::class)->get(); }
    private function ok(array $data): JsonResponse { return response()->json(['status' => 'success', 'data' => $data]); }
    public function index(): JsonResponse
    {
        $campaigns = SchoolReenrolment::orderByDesc('id')->get();
        return $this->ok(['campaigns' => $campaigns->map(fn ($c) => $this->service->campaignPayload($c))->all(),
            'years' => SchoolYear::orderBy('first_day')->get(['id', 'label', 'first_day', 'last_day'])->toArray(),
            'forms' => Form::where('masjid_id', $this->school())->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'takes_payment' => $f->takesPayment()])->all(),
            ...($campaigns->first() ? $this->service->officeList($campaigns->first()) : ['children' => [], 'mails' => []])]);
    }
    public function show($masjid_id, $reenrolment_id): JsonResponse
    {
        return $this->ok($this->service->officeList(SchoolReenrolment::findOrFail($reenrolment_id)));
    }
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['school_year_id' => ['required', 'integer'], 'opens_on' => ['required', 'date_format:Y-m-d'], 'closes_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:opens_on'], 'deposit_form_id' => ['nullable', 'integer']]);
        SchoolYear::findOrFail($data['school_year_id']);
        if (! empty($data['deposit_form_id'])) {
            $depositForm = Form::where('masjid_id', $this->school())->findOrFail($data['deposit_form_id']);
            // Without child name questions the deposit form cannot be filled in for the child (walk finding).
            if (! \App\Services\Schools\SchoolAdmissionIdentity::hasChildFields($depositForm)) throw \Illuminate\Validation\ValidationException::withMessages(['deposit_form_id' => 'Choose a form that asks each child\'s first name and last name (as separate questions).']);
        }
        $campaign = DB::transaction(function () use ($data) {
            $school = Masjid::whereKey($this->school())->lockForUpdate()->firstOrFail();
            abort_unless(\App\Support\SchoolAdmissionsMode::enabled($school, 'school_reenrolment'), 403);
            SchoolYear::whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
            $campaign = SchoolReenrolment::updateOrCreate(['school_year_id' => $data['school_year_id']], $data);
            $this->service->proposals($campaign);
            return $campaign;
        });
        return $this->ok($this->service->campaignPayload($campaign));
    }
    public function answer(Request $request, $masjid_id, $reenrolment_id, $student_contact_id): JsonResponse
    {
        $data = $request->validate(['answer' => ['sometimes', 'required', Rule::in(['returning', 'not_returning'])], 'next_grade' => ['sometimes', 'nullable', 'string', 'max:80']]);
        abort_if($data === [], 422);
        $child = $this->service->roster()->firstWhere('contact_id', (int) $student_contact_id); abort_unless($child, 404);
        return $this->ok($this->service->answer(SchoolReenrolment::findOrFail($reenrolment_id), $child, $data, office: (int) $request->user()->id));
    }
    public function reminders(Request $request, $masjid_id, $reenrolment_id): JsonResponse
    {
        return $this->ok(['queued' => $this->service->reminders(SchoolReenrolment::findOrFail($reenrolment_id), (int) $request->user()->id)]);
    }
}
