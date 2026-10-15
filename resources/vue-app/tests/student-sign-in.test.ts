import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, click, type, select, submit, flush } from './support/mountSfc.ts';
import { studentSlotKey, studentTokenForUrl } from '../core/helpers/studentAccountSession.ts';
const require = createRequire(import.meta.url);
const vue = require('vue');

test('student credential selection cannot inherit or send a family/staff token or leave its origin and realm', () => {
    const values = new Map([['MASJID_APP_AUTH_TOKEN','staff'],['MANARA_FAMILY_SESSIONS','family'],[studentSlotKey(3),'student']]);
    const storage = { getItem: (key: string) => values.get(key) ?? null };
    for (const url of ['/api/admin/masjids/3','/api/family/masjids/3','/api/student/masjids/4/me','https://other.invalid/api/student/masjids/3/me','//other.invalid/api/student/masjids/3/me']) assert.equal(studentTokenForUrl(storage,url,'https://school.invalid'),null);
    assert.equal(studentTokenForUrl(storage,'/api/student/masjids/3/me','https://school.invalid'),'student');
    values.delete(studentSlotKey(3)); assert.equal(studentTokenForUrl(storage,'/api/student/masjids/3/me','https://school.invalid'),null);
});

test('office accounts reset explicitly shows password once and clears it on dismissal', async () => {
    const writes: any[] = [];
    const http={ get: async (url: string) => ({data:{data:url.endsWith('/settings') ? {from_grade:'6th',grades:['5th','6th']} : [{contact_id:8,name:'Practice Student',grade:'6th',eligible:true,account:{has_account:false,username:null,last_sign_in_at:null}}]}}),
        put: async (url: string, body: any) => {writes.push({url,body});return {data:{data:{one_time_password:'PracticeOnce42',username:'practice6',name:'Practice Student'},message:'Saved'}};}};
    const s=await mountSfc('components/classes/StudentAccounts.vue',{schoolId:3,groupId:4},{vue,'@/core/services/ApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Could not save'}});
    try { await flush(); type(s.all(n=>n.props['aria-label']==='Username for Practice Student')[0],'practice6'); submit(s.all(n=>n.tag==='form')[1]); await flush();
        assert.equal(writes.length,1); assert.equal(writes[0].body.username,'practice6'); assert.match(s.text(),/PracticeOnce42/);
        click(s.button('Done with passwords')); await flush(); assert.doesNotMatch(s.text(),/PracticeOnce42/);
    } finally {s.unmount();}
});

test('teacher hand-ins show history, late state and existing gradebook marking direction', async () => {
    const s=await mountSfc('components/classes/StudentHandIns.vue',{base:'/api/teacher/masjids/3/groups/4',assignmentId:5,teacher:true},{vue,
        '@/core/services/TeacherApiService':{default:{get:async()=>({data:{data:{students_hand_in:true,due_on:'2026-10-14',students:[{membership_id:8,name:'Practice Student',marked:false,history:[{id:9,version:2,kind:'link',link:'https://example.invalid/work',late:true,submitted_at:'2026-10-15T16:00:00Z'}]}]}}})}},
        '@/core/services/ApiService':{default:{}},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'}});
    try { await flush(); assert.match(s.text(),/Practice Student.*Version 2.*Late/); assert.match(s.text(),/gradebook/); assert.equal(s.all(n=>n.tag==='a')[0].props.rel,'noopener noreferrer'); }
    finally{s.unmount();}
});

test('student first sign-in adopts only its own token and requires password change before loading schoolwork', async () => {
    const writes: any[]=[]; const adopted: any[]=[];
    const http={token:()=>null,clear:()=>{},lastResponseAt:()=>Date.now(),adopt:(school:string,token:string)=>adopted.push({school,token}),
        post:async(url:string,body:any)=>{writes.push({url,body});return {data:{data:{token:'student-token',must_change_password:true}}};},
        put:async(url:string,body:any)=>{writes.push({url,body});return {data:{data:{token:'new-student-token'}}};},
        get:async(url:string)=>({data:{data:[]}})};
    const s=await mountSfc('views/student/StudentPortal.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:'3'}})},
        '@/core/services/StudentAccountApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'},'@/core/helpers/preparePhoto':{preparePhoto:async(file:any)=>file}});
    try {
        await flush();
        type(s.all(n=>n.tag==='input')[0],'practice6');type(s.all(n=>n.tag==='input')[1],'PracticeOnce42');
        submit(s.all(n=>n.tag==='form')[0]);await flush();
        assert.deepEqual(adopted,[{school:'3',token:'student-token'}]);assert.match(s.text(),/Choose your own password/);assert.doesNotMatch(s.text(),/Your marks/);
        const inputs=s.all(n=>n.tag==='input');type(inputs[0],'PracticeOnce42');type(inputs[1],'NewPractice43');type(inputs[2],'NewPractice43');submit(s.all(n=>n.tag==='form')[0]);await flush();
        assert.deepEqual(writes[1].body,{current_password:'PracticeOnce42',password:'NewPractice43',password_confirmation:'NewPractice43'});
        assert.equal(adopted[1].token,'new-student-token');assert.match(s.text(),/Your marks/);
    } finally{s.unmount();}
});

test('closed work still exposes the students own retained history without a submission form',async()=>{
    const http={token:()=> 'student',clear:()=>{},lastResponseAt:()=>Date.now(),get:async(url:string)=>({data:{data:
        url.endsWith('/classes')?[{membership_id:1,name:'Practice Class',teachers:[]}]:
        url.endsWith('/work')?[{id:2,title:'Retained work',students_hand_in:false,has_hand_in:true}]:
        url.endsWith('/hand-ins')?{marked:false,history:[{id:3,version:1,kind:'link',link:'https://example.invalid/work',submitted_at:'2026-10-15T16:00:00Z',late:false}]}:{scores:[],reports:[]}}})};
    const s=await mountSfc('views/student/StudentPortal.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:'3'}})},
        '@/core/services/StudentAccountApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'},'@/core/helpers/preparePhoto':{preparePhoto:async(f:any)=>f}});
    try{await flush();click(s.button('Your hand-in'));await flush();assert.match(s.text(),/Version 1/);assert.equal(s.all(n=>n.tag==='form').length,0);}
    finally{s.unmount();}
});

test('office can turn off a retained account after its child falls below the starting grade',async()=>{
    let enabled=true;const deletes:string[]=[];
    const http={get:async(url:string)=>({data:{data:url.endsWith('/settings')?{from_grade:'6th',grades:['6th']}:[{contact_id:8,name:'Practice Student',grade:'5th',eligible:false,account:{has_account:true,username:'practice5',enabled}}]}}),
        delete:async(url:string)=>{deletes.push(url);enabled=false;return{data:{}};}};
    const s=await mountSfc('components/classes/StudentAccounts.vue',{schoolId:3,groupId:4},{vue,'@/core/services/ApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'}});
    try{await flush();click(s.button('Turn off'));await flush();assert.deepEqual(deletes,['/api/admin/masjids/3/student-sign-in/accounts/8']);assert.match(s.text(),/Account off/);}
    finally{s.unmount();}
});

for(const outcome of ['response','refusal']) test('a delayed previous student '+outcome+' cannot overwrite the next student after sign-out',async()=>{
    let oldResolve:(value:any)=>void=()=>{};
    let oldReject:(reason:any)=>void=()=>{};
    const oldReply=new Promise((resolve,reject)=>{oldResolve=resolve;oldReject=reject;});
    let classReads=0;
    const http={token:()=> 'student-A',clear:()=>{},adopt:()=>{},lastResponseAt:()=>Date.now(),
        post:async(url:string)=>({data:{data:url.endsWith('/sign-out')?{}:{token:'student-B',must_change_password:false}}}),
        get:async(url:string)=>{
            if(url.endsWith('/classes')){classReads++;return classReads===1?oldReply:{data:{data:[{membership_id:2,name:'Practice B Class',teachers:[]}]}};}
            if(url.includes('/classes/1/'))return new Promise(()=>{});
            return {data:{data:url.endsWith('/work')?[{id:9,title:'Practice B work',students_hand_in:false}]:{scores:[],reports:[]}}};
        }};
    const s=await mountSfc('views/student/StudentPortal.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:'3'}})},
        '@/core/services/StudentAccountApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'},'@/core/helpers/preparePhoto':{preparePhoto:async(f:any)=>f}});
    try{
        await flush();click(s.button('Sign out'));await flush();
        type(s.all(n=>n.tag==='input')[0],'practiceB');type(s.all(n=>n.tag==='input')[1],'PracticeB42');submit(s.all(n=>n.tag==='form')[0]);await flush();
        assert.match(s.text(),/Practice B work/);
        if(outcome==='response')oldResolve({data:{data:[{membership_id:1,name:'PRIVATE_PREVIOUS_STUDENT_CLASS',teachers:['PRIVATE_PREVIOUS_TEACHER']}]}});
        else oldReject({response:{status:401}});
        await flush();
        assert.doesNotMatch(s.text(),/PRIVATE_PREVIOUS/);assert.match(s.text(),/Practice B work/);
    }finally{s.unmount();}
});

test('a photo still decoding at sign-out cannot be uploaded into the next students hand-in',async()=>{
    let photoResolve:(value:any)=>void=()=>{};
    const prepared=new Promise(resolve=>{photoResolve=resolve;});
    let classesRead=0,member=1;const uploads:string[]=[];
    const http={token:()=> 'student-A',clear:()=>{},adopt:()=>{},lastResponseAt:()=>Date.now(),
        post:async(url:string)=>{if(url.endsWith('/hand-ins'))uploads.push(url);return {data:{data:url.endsWith('/sign-out')?{}:{token:'student-B',must_change_password:false}}};},
        get:async(url:string)=>{
            if(url.endsWith('/classes')){member=++classesRead;return {data:{data:[{membership_id:member,name:'Practice class',teachers:[]}]}};}
            return {data:{data:url.endsWith('/work')?[{id:member,title:'Practice work',students_hand_in:true}]:url.endsWith('/hand-ins')?{marked:false,history:[]}:{scores:[],reports:[]}}};
        }};
    const s=await mountSfc('views/student/StudentPortal.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:'3'}})},
        '@/core/services/StudentAccountApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'},'@/core/helpers/preparePhoto':{preparePhoto:()=>prepared}});
    try{
        await flush();click(s.button('Your hand-in'));await flush();
        const original=new File(['private invented photo'],'practice.png',{type:'image/png'});
        const picker=s.all(n=>n.tag==='input' && n.props.type==='file')[0];
        picker.props.onChange({target:{files:[original]}});
        submit(s.all(n=>n.tag==='form')[0]);await flush();
        click(s.button('Sign out'));await flush();
        type(s.all(n=>n.tag==='input')[0],'practiceB');type(s.all(n=>n.tag==='input')[1],'PracticeB42');submit(s.all(n=>n.tag==='form')[0]);await flush();
        click(s.button('Your hand-in'));await flush();
        photoResolve(original);await flush();
        assert.deepEqual(uploads,[]);assert.match(s.text(),/Practice work/);
    }finally{s.unmount();}
});

test('student and family surfaces display server labels without browser date conversion',async()=>{
    const http={token:()=> 'student',clear:()=>{},lastResponseAt:()=>Date.now(),get:async(url:string)=>({data:{data:
        url.endsWith('/classes')?[{membership_id:1,name:'Practice Class',teachers:[]}]:
        url.endsWith('/work')?[{id:2,title:'Practice work',due_on:'2026-10-20',due_on_label:'Tue, Oct 20',students_hand_in:true}]:
        url.endsWith('/hand-ins')?{marked:false,history:[{id:3,version:1,kind:'link',link:'https://example.invalid/work',submitted_at:'2026-10-15T00:17:00Z',submitted_at_label:'Oct 14, 2026, 5:17 PM'}]}:
        url.includes('/timetable')?{days:[{date:'2026-10-12',date_label:'Mon, Oct 12',meetings:[{id:4,starts_at:'09:00',ends_at:'09:45',starts_at_label:'9:00 AM',ends_at_label:'9:45 AM',label:'Practice lesson',teachers:[]}]}]}:
        url.endsWith('/school-calendar')?{years:[{id:5,label:'Practice year',first_day:'2026-08-24',last_day:'2027-06-04',first_day_label:'Mon, Aug 24, 2026',last_day_label:'Fri, Jun 4, 2027',closures:[{closed_on:'2026-10-19',closed_on_label:'Mon, Oct 19',reason:'Practice closure'}],terms:[{id:6,name:'First term',starts_on:'2026-08-24',ends_on:'2026-12-18',starts_on_label:'Mon, Aug 24, 2026',ends_on_label:'Fri, Dec 18, 2026'}]}]}:{scores:[],reports:[]}}})};
    const s=await mountSfc('views/student/StudentPortal.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:'3'}})},
        '@/core/services/StudentAccountApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'},'@/core/helpers/preparePhoto':{preparePhoto:async(f:any)=>f}});
    try{
        await flush();assert.match(s.text(),/Due Tue, Oct 20/);click(s.button('Your hand-in'));await flush();assert.match(s.text(),/Oct 14, 2026, 5:17 PM/);
        click(s.button('Timetable'));await flush();assert.match(s.text(),/Mon, Oct 12.*9:00 AM–9:45 AM/);assert.doesNotMatch(s.text(),/2026-10-12/);
        click(s.button('Calendar'));await flush();assert.match(s.text(),/Mon, Aug 24, 2026 to Fri, Jun 4, 2027/);assert.match(s.text(),/Mon, Oct 19/);assert.match(s.text(),/First term: Mon, Aug 24, 2026 to Fri, Dec 18, 2026/);
    }finally{s.unmount();}
    const family=await mountSfc('components/classes/FamilyStudentAccount.vue',{schoolId:3,membershipId:1},{vue,
        '@/core/services/FamilyApiService':{default:{get:async()=>({data:{data:[{membership_id:1,has_account:true,enabled:true,username:'practice.student',last_sign_in_at:'2026-10-15T00:17:00Z',last_sign_in_label:'Oct 14, 2026, 5:17 PM'}]}})}},
        '@/views/family/familyI18n':{useFamilyLang:()=>({lang:vue.ref('en')})}});
    try{await flush();assert.match(family.text(),/Last sign-in: Oct 14, 2026, 5:17 PM/);}finally{family.unmount();}
});

for(const outcome of ['on','off','network']) test('student sign-in waits for availability: '+outcome,async()=>{
    let resolve:(v:any)=>void=()=>{},reject:(e:any)=>void=()=>{};const reply=new Promise((yes,no)=>{resolve=yes;reject=no;});const reads:string[]=[];
    const http={token:()=>null,clear:()=>{},lastResponseAt:()=>Date.now(),get:(url:string)=>{reads.push(url);return reply;}};
    const s=await mountSfc('views/student/StudentPortal.vue',{}, {vue,'vue-router':{useRoute:()=>({params:{masjidId:'3'}})},
        '@/core/services/StudentAccountApiService':{default:http},'@/core/services/ApiErrors':{apiErrorText:()=> 'Failed'},'@/core/helpers/preparePhoto':{preparePhoto:async(f:any)=>f}});
    try{
        assert.equal(s.all(n=>n.tag==='form').length,0);assert.deepEqual(reads,['/api/student/masjids/3/availability']);
        if(outcome==='on')resolve({data:{data:{available:true}}});else reject(outcome==='off'?{response:{status:404}}:new Error('Network'));
        await flush();assert.equal(s.all(n=>n.tag==='form').length,outcome==='on'?1:0);
        if(outcome==='off')assert.match(s.text(),/Student sign-in is not available at this school\./);
        if(outcome==='network')assert.match(s.text(),/Could not check student sign-in/);
    }finally{s.unmount();}
});
