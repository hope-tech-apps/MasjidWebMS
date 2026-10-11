<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{DutyRota, Masjid};
use App\Support\{DutyRotaMode, DutyRotaService};
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DutyRotaClaimController extends Controller
{
    public function handle(Request $request,$masjid_id,$rota_id,$action): mixed
    {
        $school=Masjid::find($masjid_id);
        // OFF routes remain 404; wrong enabled-school IDs and tokens share one neutral refusal.
        abort_unless(DutyRotaMode::enabled($school),404);
        $r=DutyRota::withoutMasjidScope()->where('masjid_id',$school->id)->find($rota_id);
        if (! $r || ! is_string($request->input('token')) || strlen($request->input('token'))!==64) return $this->unavailable();
        try {
            // Authenticate before exposing validation details.
            $service=app(DutyRotaService::class);
            if ($action==='page') $data=$service->publicAction($r,$request->input('token'),'page');
            else {
                $service->publicAction($r,$request->input('token'),'page');
                $input=$request->validate($action==='claim' ? ['dates'=>'required|array|min:1|max:1000','dates.*'=>'required|date_format:Y-m-d|distinct'] : ['date'=>'required|date_format:Y-m-d']);
                $data=$service->publicAction($r,$request->input('token'),$action,$input['dates'] ?? [],$input['date'] ?? null);
            }
            return response()->json(['status'=>'success','data'=>$data])->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
        } catch(HttpException $e) { if($e->getStatusCode()===410) return $this->unavailable(); throw $e; }
    }
    private function unavailable(): mixed { return response()->json(['status'=>'error','message'=>DutyRotaService::UNAVAILABLE],410)->header('Cache-Control','no-store'); }
}
