import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { mountSfc, compileSfc, loadTs, flush, submit, click } from './support/mountSfc.ts';

const errors = await loadTs('core/helpers/serverMessage.ts', {});
const display = await loadTs('core/helpers/transcriptDisplay.ts', {});
const statuses = ['awaiting_family', 'pending', 'accepted', 'declined', 'withdrawn'];
const labels = ['Waiting for the family', 'Waiting for the other school', 'Accepted', 'Declined', 'Withdrawn'];
async function transcripts() {
    const selectVue = {...vue, vModelSelect:{...vue.vModelSelect, mounted(el:any,binding:any,vnode:any) { el.options=el.children.filter((n:any)=>n.tag==='option'); vue.vModelSelect.mounted(el,binding,vnode); }}};
    const calls: any[] = [];
    let fail = false;
    const course = { name:'Science', letter:'A', calculated_letter:'A', staff:{class_subject_id:2,school_year_id:3,override_letter:null,reason:null} };
    const student = () => ({document:{years:[{year:'Practice year',courses:[structuredClone(course)]}]},issues:[],prior_years:[],received:[],shares:statuses.map((status,id)=>({id,status,receiving_school:'Practice School',resolved_at:'2026-10-15',resolved_at_display:'Oct 15, 2026'}))});
    const api = {get:async(url:string)=>({data:{data:url.endsWith('/inbox')?statuses.map((status,id)=>({id,status,sending_school:'Practice Sender',student_name:'Practice Student'})):url.includes('/students/')?student():{students:{data:[{id:7,first_name:'Practice',last_name:'Student'}]},policy:{points:{A:400},failing_letters:[],honors_extra:0,ap_extra:0},scale:[{letter:'A'}],subjects:[],receiving_schools:[],received:[]}}}),put:async(url:string,body:any)=>{calls.push({url,body});if(fail)throw Error('failed');},post:async(url:string,body:any)=>{calls.push({url,body});}};
    const editor = await compileSfc('components/classes/TranscriptFinalEditor.vue',{vue,'@/core/services/ApiService':{default:api},'@/core/services/TeacherApiService':{default:api},'@/core/helpers/serverMessage':errors});
    const s = await mountSfc('views/dashboard/TranscriptsView.vue',{}, {vue:selectVue,'@/core/services/ApiService':{default:api},'@/stores/masjidStore':{useMasjidStore:()=>({masjid:{id:1}})},'@/core/helpers/serverMessage':errors,'@/core/helpers/transcriptDisplay':display,'@/components/classes/TranscriptDocument.vue':{default:{render:()=>null}},'@/components/classes/TranscriptFinalEditor.vue':{default:editor}});
    await flush();
    const box=s.all(n=>n.tag==='select'&&n.textContent.includes('Choose a student'))[0];box.props['onUpdate:modelValue']('7');box.props.onChange();await flush();
    return {...s,calls,fail:()=>{fail=true;}};
}
test('polish: final save retains the open overrides box and success after the server refresh',async()=>{
    const s=await transcripts();
    try {
        const details=s.all(n=>n.tag==='details'&&n.textContent.includes('Final letter overrides'))[0];details.props.open=true;
        submit(s.all(n=>n.tag==='form'&&n.textContent.includes('Save final letter'))[0]);await flush();
        assert.ok(s.all(n=>n.tag==='details'&&n.textContent.includes('Final letter overrides'))[0]===details);
        assert.equal(details.props.open,true);assert.match(s.text(),/Final letter saved\./);
        s.fail();submit(s.all(n=>n.tag==='form'&&n.textContent.includes('Save final letter'))[0]);await flush();
        assert.doesNotMatch(s.text(),/Final letter saved\./);assert.match(s.text(),/failed/);
        const box=s.all(n=>n.tag==='select'&&n.textContent.includes('Choose a student'))[0];box.props['onUpdate:modelValue']('');box.props.onChange();await flush();assert.doesNotMatch(s.text(),/Final letter saved\./);
    } finally{s.unmount();}
});
test('polish: both office share lists label every request state and pending links default unlinked',async()=>{
    const s=await transcripts();
    try {
        for(const label of labels)assert.ok(s.text().includes(label),label);
        for(const state of statuses)assert.ok(!s.text().includes(state),state);
        for(const heading of ['Share with another Manara school','Incoming requests']) {
            // The labels occur in the sending history and receiving inbox independently.
            assert.ok(s.text().includes(heading));
        }
        for(const label of labels.slice(1))assert.ok(s.text().split(label).length>=3,label);
        const box=s.all(n=>n.tag==='select'&&n.textContent.includes('Keep unlinked until enrolled'))[0];
        assert.equal(box.options[box.selectedIndex]?.props.value,'');
        click(s.button('Accept and copy transcript'));await flush();assert.equal(s.calls.at(-1).body.contact_id,null);
    } finally{s.unmount();}
});

async function language() {
    const modules:Record<string,any>={vue};
    for(const code of ['ur','ps','fa-AF','es','fr'])modules[`@/views/family/locales/${code}`]=await loadTs(`views/family/locales/${code}.ts`,{});
    return loadTs('views/family/familyI18n.ts',modules);
}
const methods=['cash','check','zelle','cash_app','venmo','bank_transfer','other','stripe'];
for(const [code,cash,bank,separator] of [['en','Cash','Bank transfer',', '],['ar','نقدًا','تحويل بنكي','، '],['ur','نقد','بینک ٹرانسفر','، '],['ps','نغدې','بانکي لېږد','، '],['fa-AF','نقد','انتقال بانکی','، '],['es','Efectivo','Transferencia bancaria',', '],['fr','Espèces','Virement bancaire',', ']])test(`polish: ${code} family statement translates methods with its list separator`,async()=>{
    const lang=await language();lang.useFamilyLang().setLang(code);
    const lines=methods.map((method,id)=>({id,method,method_label:method==='cash'?'Cash':method,kind:'office_payment',date:'2026-10-15',amount_minor:-100,description:'Practice payment'}));
    const original=JSON.stringify(lines);let writes=0;
    const money=await loadTs('core/helpers/tuitionMoney.ts',{});
    const s=await mountSfc('views/family/FamilyTuition.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:1},query:{}})},'./familyI18n':lang,'./FamilyLangPicker.vue':{default:{render:()=>null}},'@/core/helpers/tuitionMoney':money,'@/core/types/data/masjid-related/SchoolCalendar':{formatSchoolDay:(value:string)=>value},'@/core/services/FamilyApiService':{default:{get:async(url:string)=>({data:{data:url.includes('?')?{statement:{lines,opening_minor:0,closing_minor:-800}}:{accounts:[{id:1,children:[],currency:'usd',balance_minor:0,school_today:'2026-10-15'}]}}}),post:async()=>{writes++;}}}});
    try {
        await flush();const cells=s.all(n=>n.tag==='td');
        assert.equal(cells[2].textContent.replace(/ ([,،])/g,'$1'),`${lang.useFamilyLang().t('tuition_office_payment')}${separator}${cash}`);
        assert.ok(s.text().includes(bank));assert.equal(JSON.stringify(lines),original);assert.equal(writes,0);
        for(const method of methods)assert.notEqual(lang.useFamilyLang().t('tuition_method_'+method),'tuition_method_'+method);
    }finally{s.unmount();}
});
