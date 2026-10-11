<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{DutyRota, DutyRotaFamily, Form, SchoolYear, Masjid};
use App\Support\{DutyRotaService, DutyRotaMailService, TenantContext, SchoolCalendar, CsvCell};
use Illuminate\Http\Request;

class DutyRotaController extends Controller
{
    public function __construct(private DutyRotaService $service) {}
    private function school(): Masjid { return Masjid::findOrFail(app(TenantContext::class)->get()); }
    private function rota($id): DutyRota { return DutyRota::findOrFail($id); }
    private function reply(DutyRota $rota,array $extra=[]): mixed { return response()->json(['status'=>'success','data'=>[...$this->service->office($rota),...$extra]]); }
    public function index(): mixed
    {
        $rotas=DutyRota::orderBy('id')->get();
        $forms=Form::where('masjid_id',$this->school()->id)->get()->map(fn($f)=>['id'=>$f->id,'name'=>$f->name,'fields'=>DutyRotaService::sourceFields($f)])->filter(fn($f)=>count($f['fields'])>0)->values();
        return response()->json(['status'=>'success','data'=>['rotas'=>$rotas,'years'=>SchoolYear::orderBy('first_day')->get()->map(fn($y)=>['id'=>$y->id,'label'=>$y->label]),'forms'=>$forms,'selected'=>$rotas->first() ? $this->service->office($rotas->first()) : null]]);
    }
    public function show($masjid_id,$rota_id): mixed { return $this->reply($this->rota($rota_id)); }
    public function store(Request $request): mixed { return $this->reply($this->service->save($this->school(),null,$this->validated($request))); }
    public function update(Request $request,$masjid_id,$rota_id): mixed { return $this->reply($this->service->save($this->school(),$this->rota($rota_id),$this->validated($request))); }
    private function validated(Request $request): array
    {
        $request->merge(['active'=>filter_var($request->input('active',true),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE),'source_form_id'=>$request->input('source_form_id') ?: null,'source_field'=>$request->input('source_field') ?: null]);
        return $request->validate(['name'=>'required|string|max:120','school_year_id'=>'required|integer','capacity'=>'sometimes|integer|min:1|max:20','turns_owed'=>'sometimes|integer|min:1|max:1000','source_form_id'=>'nullable|integer','source_field'=>'nullable|string|max:120','reminder_lead'=>'sometimes|integer|min:0|max:14','reminder_line'=>'nullable|string|max:2000','active'=>'required|boolean']);
    }
    public function preview($masjid_id,$rota_id): mixed { return response()->json(['status'=>'success','data'=>$this->service->build($this->rota($rota_id),true)]); }
    public function build(Request $request,$masjid_id,$rota_id): mixed
    {
        $request->validate(['confirmed'=>'required|accepted']); $r=$this->rota($rota_id); $result=$this->service->build($r); return $this->reply($r,['result'=>$result]);
    }
    public function email(Request $request,$masjid_id,$rota_id): mixed
    {
        $data=$request->validate(['confirmed'=>'required|accepted','family_id'=>'nullable|integer']); $r=$this->rota($rota_id);
        if(isset($data['family_id'])) DutyRotaFamily::where('rota_id',$r->id)->findOrFail($data['family_id']);
        return $this->reply($r,['result'=>app(DutyRotaMailService::class)->emailNeeds($r,$data['family_id'] ?? null)]);
    }
    public function link(Request $request,$masjid_id,$rota_id,$family_id): mixed
    {
        $request->merge(['reissue'=>filter_var($request->input('reissue',false),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)]);
        $input=$request->validate(['reissue'=>'boolean']);
        $r=$this->rota($rota_id); $f=DutyRotaFamily::where('rota_id',$r->id)->findOrFail($family_id);
        $url=$input['reissue'] ? $this->service->issue($r,$f)['url'] : $this->service->locked($r,fn($locked)=>$this->service->currentLink($locked,$f->fresh()));
        return response()->json(['status'=>'success','data'=>['url'=>$url]])->header('Cache-Control','no-store');
    }
    public function assignment(Request $request,$masjid_id,$rota_id): mixed
    {
        $request->merge(['over_capacity'=>filter_var($request->input('over_capacity',false),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)]);
        $data=$request->validate(['action'=>'required|in:add,move,remove','assignment_id'=>'required_if:action,move,remove|integer','family_id'=>'nullable|integer','date'=>'required_unless:action,remove|date_format:Y-m-d','name'=>'nullable|required_without_all:family_id,assignment_id|string|max:200','email'=>'nullable|email|max:254','phone'=>'nullable|string|max:50','over_capacity'=>'boolean']);
        if($data['action']==='add' && empty($data['family_id']) && empty($data['email']) && empty($data['phone'])) throw \Illuminate\Validation\ValidationException::withMessages(['email'=>['Enter an email or phone for this family.']]);
        $r=$this->rota($rota_id); $this->service->officeAssignment($r,$data); return $this->reply($r);
    }
    public function export($masjid_id,$rota_id): mixed
    {
        $data=$this->service->office($this->rota($rota_id));
        return response()->streamDownload(function () use($data) {
            $out=fopen('php://output','w'); fputcsv($out,['Day','Family','Phone','Email','Source'],escape:'');
            foreach($data['days'] as $day) foreach($day['assignments'] as $a) fputcsv($out,array_map(fn($v)=>preg_match('/^[\s]*[=+@\-]/u',(string)$v)?"'".$v:$v,[$day['date'],$a['name'],$a['phone'],$a['email'],$a['source']]),escape:''); fclose($out);
        },'duty-rota.csv',['Content-Type'=>'text/csv']);
    }
}
