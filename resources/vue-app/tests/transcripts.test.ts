import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { mountSfc, loadTs, flush, click, submit } from './support/mountSfc.ts';

async function words() {
    const modules: Record<string, any> = { vue };
    for (const code of ['ur','ps','fa-AF','es','fr']) modules[`@/views/family/locales/${code}`] = await loadTs(`views/family/locales/${code}.ts`, {});
    return loadTs('views/family/familyI18n.ts', modules);
}
test('family loads only its child and consent names the receiving school before the guardian agrees', async () => {
    const language=await words(); language.useFamilyLang().setLang('en'); const calls: any[]=[];
    const api={ get: async (url: string)=>{ calls.push(['get',url]); return { data:{ data:{ designation:'Unofficial',student:{name:'Practice Student'},years:[],cumulative:{plain:'2.57',weighted:'3.00',attempted:'3.50',earned:'2.50'} },shares:[{id:4,receiving_school:'Practice Receiving School',status:'awaiting_family'}] } }; }, post:async(url:string,body:any)=>{calls.push(['post',url,body]);return{data:{}};} };
    const s=await mountSfc('views/family/FamilyTranscript.vue',{masjidId:'1',groupId:'3',membershipId:7},{vue,'@/views/family/familyI18n':language,'@/core/services/FamilyApiService':{default:api},'@/components/classes/TranscriptDocument.vue':{default:{template:'<div />'}}});
    try {
        await flush(); assert.match(s.text(),/Unofficial/); assert.match(s.text(),/Practice Receiving School/);
        click(s.button('I agree to share the official transcript')); await flush();
        assert.deepEqual(calls.find(c=>c[0]==='post'),['post','/api/family/masjids/1/groups/3/members/7/transcript/shares/4/consent',{consent:'1'}]);
        assert.ok(calls.every(c=>c[1].includes('/members/7/transcript')));
    } finally {s.unmount();}
});

test('office shows exact GPA method, sends decimal strings and creates a request without inventing consent', async()=>{
    const calls:any[]=[]; const api={get:async(url:string)=>({data:{data:url.endsWith('/inbox')?[]:url.endsWith('/students/7')?{document:{student:{name:'Practice Student'},years:[],cumulative:{plain:'2.57'}},issues:[],shares:[],prior_years:[],received:[]}:{students:{data:[{id:7,first_name:'Practice',last_name:'Student'}],current_page:1,last_page:1},policy:{points:{A:400,F:0},honors_extra:50,ap_extra:100,failing_letters:['F']},scale:[{letter:'A',minimum:90},{letter:'F',minimum:0}],method:'Arithmetic is exact; display rounds half up.',subjects:[{id:2,name:'Science',class:'Grade 9',setting:{credit_hundredths:50,level:'honors'}}],receiving_schools:[{id:3,name:'Practice Receiving School'}],received:[]}}}),put:async(url:string,body:any)=>{calls.push(['put',url,body]);return{data:{}};},post:async(url:string,body:any)=>{calls.push(['post',url,body]);return{data:{data:{id:4}}};}};
    const errors=await loadTs('core/helpers/serverMessage.ts',{});
    const s=await mountSfc('views/dashboard/TranscriptsView.vue',{}, {vue,'@/core/services/ApiService':{default:api},'@/stores/masjidStore':{useMasjidStore:()=>({masjid:{id:1}})},'@/core/helpers/transcriptDisplay':await loadTs('core/helpers/transcriptDisplay.ts',{}),'@/core/helpers/serverMessage':errors,'@/components/classes/TranscriptDocument.vue':{default:{template:'<div />'}},'@/components/classes/TranscriptFinalEditor.vue':{default:{template:'<div />'}}});
    try {await flush();assert.match(s.text(),/Arithmetic is exact/);submit(s.all((n:any)=>n.tag==='form')[1]);await flush();assert.deepEqual(calls[0],['put','/api/admin/masjids/1/transcripts/courses/2',{credit:'0.50',level:'honors',on_transcript:true}]);const studentSelect=s.all((n:any)=>n.tag==='select' && n.textContent.includes('Choose a student'))[0];studentSelect.props['onUpdate:modelValue']('7');studentSelect.props.onChange();await flush();const receiver=s.all((n:any)=>n.tag==='select' && n.textContent.includes('Practice Receiving School'))[0];receiver.props['onUpdate:modelValue']('3');submit(s.all((n:any)=>n.tag==='form' && n.textContent.includes('Request sharing'))[0]);await flush();assert.deepEqual(calls.find(c=>c[0]==='post'),['post','/api/admin/masjids/1/transcripts/students/7/shares',{receiving_masjid_id:'3'}]);}
    finally{s.unmount();}
});

test('final letter editor sends a reason and source identities through the teacher transport',async()=>{
    const calls:any[]=[]; const errors=await loadTs('core/helpers/serverMessage.ts',{});
    const course={name:'Science',calculated_letter:'A',letter:'A',average_percentage:'95.00',staff:{class_subject_id:2,school_year_id:3,override_letter:null,reason:null}};
    const s=await mountSfc('components/classes/TranscriptFinalEditor.vue',{course,year:'Practice year',scale:[{letter:'A'},{letter:'B'}],url:'/api/teacher/masjids/1/groups/2/members/7/transcript-finals',teacher:true},{vue,'@/core/services/ApiService':{default:{put:async()=>assert.fail('Teacher used office client')}},'@/core/services/TeacherApiService':{default:{put:async(url:string,body:any)=>{calls.push([url,body]);}}},'@/core/helpers/serverMessage':errors});
    try{ s.all((n:any)=>n.tag==='select')[0].props['onUpdate:modelValue']('B');s.all((n:any)=>n.tag==='textarea')[0].props['onUpdate:modelValue']('Additional staff evidence');submit(s.all((n:any)=>n.tag==='form')[0]);await flush();assert.deepEqual(calls[0][1],{class_subject_id:2,school_year_id:3,letter:'B',reason:'Additional staff evidence'});assert.match(s.text(),/Final letter saved/); }
    finally{s.unmount();}
});

test('transcript document shows yearly and cumulative figures without staff-only reasons',async()=>{
    const document={designation:'Unofficial',school:{name:'Practice School'},student:{name:'Practice Student'},years:[{year:'Practice year',grade:'9th',origin:'Practice School',courses:[{name:'Science',letter:'B',credit:'0.50',level:'ap',passing:true,staff:{reason:'Do not disclose staff evidence'}}],totals:{plain:'3.00',weighted:'4.00'}}],cumulative:{plain:'2.57',weighted:'3.00',attempted:'3.50',earned:'2.50'}};
    const s=await mountSfc('components/classes/TranscriptDocument.vue',{document},{vue,'@/core/helpers/transcriptDisplay':await loadTs('core/helpers/transcriptDisplay.ts',{})});
    try{assert.match(s.text(),/Unofficial/);assert.match(s.text(),/Science AP B 0.50 0.50/);assert.match(s.text(),/Plain GPA: 2.57/);assert.ok(!s.text().includes('Do not disclose'));}
    finally{s.unmount();}
});

test('ungraded rows show Not graded without credit cells, hidden courses stay absent, dates stay calendar days',async()=>{
    const document={designation:'Unofficial',school:{name:'Practice School'},student:{name:'Practice Student',date_of_birth:'2010-03-04',attended_from:'2025-08-25',attended_to:null},years:[{year:'Practice year',grade:'10th',courses:[{name:'Levels only',letter:null,credit:'2.00',level:'regular',passing:false},{name:'Hidden course',letter:'A',credit:'4.00',level:'regular',passing:true,on_transcript:false}],totals:{attempted:'0.00',earned:'0.00'}}],cumulative:{attempted:'0.00',earned:'0.00'}};
    const s=await mountSfc('components/classes/TranscriptDocument.vue',{document},{vue,'@/core/helpers/transcriptDisplay':await loadTs('core/helpers/transcriptDisplay.ts',{})});
    try{assert.match(s.text(),/Grade 10/);assert.match(s.text(),/Aug 25, 2025 to Present/);assert.match(s.text(),/Mar 4, 2010/);assert.match(s.text(),/Levels only Regular Not graded/);assert.doesNotMatch(s.text(),/Hidden course|2.00|4.00|Grade 10th/);const cells=s.all((n:any)=>n.tag==='td');assert.deepEqual(cells.slice(-2).map((n:any)=>n.textContent),['','']);}
    finally{s.unmount();}
});

test('accepted sharing stays in the family history with school and date and no further consent controls',async()=>{
    const language=await words();language.useFamilyLang().setLang('en');
    const api={get:async()=>({data:{data:{designation:'Unofficial',years:[]},shares:[{id:4,receiving_school:'Practice Receiving School',status:'accepted',resolved_on_display:'Oct 15, 2026'}]}})};
    const s=await mountSfc('views/family/FamilyTranscript.vue',{masjidId:'1',groupId:'3',membershipId:7},{vue,'@/views/family/familyI18n':language,'@/core/services/FamilyApiService':{default:api},'@/components/classes/TranscriptDocument.vue':{default:{template:'<div />'}}});
    try{await flush();assert.match(s.text(),/Sent to Practice Receiving School · Accepted on Oct 15, 2026/);assert.doesNotMatch(s.text(),/Awaiting acceptance|I agree|Withdraw request/);assert.equal(s.all((n:any)=>n.tag==='button').length,1);}
    finally{s.unmount();}
});

test('office warns for a stale official issue, offers reissue and sends the show-all filter',async()=>{
    const calls:any[]=[];let stale=true;
    const api={get:async(url:string)=>{calls.push(['get',url]);return{data:{data:url.endsWith('/inbox')?[]:url.endsWith('/students/7')?{document:{years:[]},latest_issue_stale:stale,issues:[{id:5,serial:'Practice serial',issued_at_display:'Oct 15, 2026, 11:06 PM',issued_by_name:'Practice Office'}],shares:[],prior_years:[],received:[]}:{students:{data:[{id:7,first_name:'Practice',last_name:'Student'}],current_page:1,last_page:1},policy:{points:{A:400},honors_extra:50,ap_extra:100,failing_letters:[]},scale:[],subjects:[],received:[],receiving_schools:[]}}};},post:async(url:string)=>{calls.push(['post',url]);stale=false;return{data:{}};}};
    const s=await mountSfc('views/dashboard/TranscriptsView.vue',{}, {vue,'@/core/services/ApiService':{default:api},'@/stores/masjidStore':{useMasjidStore:()=>({masjid:{id:1}})},'@/core/helpers/serverMessage':await loadTs('core/helpers/serverMessage.ts',{}),'@/core/helpers/transcriptDisplay':await loadTs('core/helpers/transcriptDisplay.ts',{}),'@/components/classes/TranscriptDocument.vue':{default:{template:'<div />'}},'@/components/classes/TranscriptFinalEditor.vue':{default:{template:'<div />'}}});
    try{
        await flush();assert.ok(calls[0][1].endsWith('show_all=0'));
        const toggle=s.all((n:any)=>n.tag==='input'&&n.props.type==='checkbox' && n.props.onChange)[0];toggle.props['onUpdate:modelValue'](true);toggle.props.onChange();await flush();assert.ok(calls.some(c=>c[1].endsWith('show_all=1')));
        const select=s.all((n:any)=>n.tag==='select'&&n.textContent.includes('Choose a student'))[0];select.props['onUpdate:modelValue']('7');select.props.onChange();await flush();
        assert.match(s.text(),/Grades have changed since the latest official issue/);assert.match(s.text(),/Oct 15, 2026, 11:06 PM · Practice Office/);
        click(s.button('Issue a new official copy first'));await flush();assert.ok(calls.some(c=>c[0]==='post'&&c[1].endsWith('/students/7/issues')));assert.doesNotMatch(s.text(),/Grades have changed since/);
    }finally{s.unmount();}
});
