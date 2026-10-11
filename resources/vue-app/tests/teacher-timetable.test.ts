import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { mountSfc, compileSfc, loadTs, flush, click, select } from './support/mountSfc.ts';
const require = createRequire(import.meta.url); const vue = require('vue');

async function language(code = 'en') {
    const modules: Record<string, any> = { vue };
    for (const file of ['ur','ps','fa-AF','es']) modules[`@/views/family/locales/${file}`] = await loadTs(`views/family/locales/${file}.ts`, {});
    const i18n = await loadTs('views/family/familyI18n.ts', modules);
    const hook = i18n.useFamilyLang(); hook.setLang(code);
    return { module: i18n, hook };
}
const slot = (extra = {}) => ({ id: 1, group_id: 3, group_name: 'Practice Class', class_subject_id: 10, kind: 'subject', label: 'Science', starts_at: '09:10', ends_at: '09:55', room_name: null, co_teachers: [{id: 9, name: 'Practice Co Teacher'}], effective_from: '2026-09-07', effective_until: null, href: '/teacher/classes/3?subject=10', ...extra });
async function screen(state = 'meetings', code = 'en', location: string | null = null) {
    const lang = await language(code); const calls: string[] = [];
    const data: any = { timezone: 'America/New_York', week_start: '2026-10-12', week_end: '2026-10-18', today: { date: '2026-10-12', state, reason: state === 'closure' ? 'Practice planning day' : null, meetings: state === 'meetings' ? [slot({room_name: location}),slot({id: 2, starts_at: '11:50', ends_at: '12:35', room_name: location, href: '/teacher/classes/3'})] : [] }, days: [ { date: '2026-10-12', weekday: 1, reason: null, meetings: [slot({room_name:location})] }, { date: '2026-10-14', weekday: 3, reason: null, meetings: [slot({id:3, starts_at:'11:00', ends_at:'11:45', effective_from:'2026-10-14', effective_until:'2026-10-16', room_name:location})] }, {date:'2026-10-16',weekday:5,reason:'Practice closure',meetings:[]} ] };
    const api = { get: async (url: string) => { calls.push(url); if(url.includes('week=2026-10-19')) return {data:{data:{...data,week_start:'2026-10-19',week_end:'2026-10-25',days:[{date:'2026-10-19',weekday:1,reason:null,meetings:[slot({label:'Next week duty',effective_from:'2026-10-19'})]}]}}}; return {data:{data}}; } };
    const link = vue.defineComponent({props:['to'], setup: (props:any,{slots}:any)=>()=>vue.h('a',{href:props.to},slots.default?.())});
    const meeting = await compileSfc('views/teacher/ScheduleMeeting.vue',{vue,'@/views/family/familyI18n':lang.module,'vue-router':{RouterLink:link}});
    const s = await mountSfc('views/teacher/TeacherSchedule.vue',{}, {vue,'@/core/services/TeacherApiService':{default:api},'@/stores/authStore':{useAuthStore:()=>({dashboardMasjidId:1})},'@/views/family/familyI18n':lang.module,'./ScheduleMeeting.vue':{default:meeting}});
    await flush(); return {...s,calls,lang};
}
test('teacher schedule today ordered clocks now and next device instant use school timezone; no empty location anywhere', async () => {
    mock.method(Date,'now',()=>Date.parse('2026-10-12T13:20:00Z'));
    const s=await screen();
    try {
        assert.equal(s.calls.length,1); assert.match(s.text(),/Science/); assert.match(s.text(),/Practice Co Teacher/);
        assert.match(s.text(),/Happening now/); assert.match(s.text(),/Next/);
        assert.equal(s.all(n=>n.props['data-test']==='location').length,0); assert.doesNotMatch(s.text(),/Location:|[—]/);
        assert.ok(s.all(n=>n.tag==='a' && n.props.href==='/teacher/classes/3?subject=10').length>0);
        assert.ok(s.all(n=>n.tag==='a' && n.props.href==='/teacher/classes/3').length>0);
        assert.equal(s.all(n=>n.props['data-test']==='today-meeting' && n.textContent.includes('Happening now')).length,1);
        assert.equal(s.all(n=>n.props['data-test']==='today-meeting' && n.textContent.includes('Next')).length,1);
    } finally {s.unmount(); mock.restoreAll();}
});
for(const [state,words] of Object.entries({non_meeting:'The school does not meet today.',closure:'Practice planning day',not_started:'The school year has not started.',ended:'The school year has ended.',unplaced:'Nothing is placed for you yet.'})) test(`teacher schedule today ${state} state`,async()=>{
    const s=await screen(state); try {assert.match(s.text(),new RegExp(words.replaceAll('.','\\.'))); assert.equal(s.all(n=>n.props['data-test']==='today-meeting').length,0);}finally{s.unmount();}
});
test('teacher schedule own weekday clock columns closures dated notes and location when set',async()=>{
    const s=await screen('meetings','en','Practice Hall'); try {
        const cols=s.all(n=>n.props['data-test']==='week-day'); assert.equal(cols.length,3);
        assert.match(cols[0].textContent,/09:10/); assert.doesNotMatch(cols[0].textContent,/11:00/);
        assert.match(cols[1].textContent,/11:00/); assert.match(cols[1].textContent,/From 14 October/); assert.match(cols[1].textContent,/Until 16 October/);
        assert.match(cols[2].textContent,/Practice closure/); assert.ok(s.all(n=>n.props['data-test']==='location').every(n=>n.textContent==='Practice Hall'));
    }finally{s.unmount();}
});
test('teacher schedule week picker sends one request per move and preserves today',async()=>{
    const s=await screen(); try {
        click(s.button('Next week')); await flush(); assert.equal(s.calls.length,2); assert.match(s.calls[1],/week=2026-10-19/); assert.match(s.text(),/Next week duty/);
        assert.equal(s.all(n=>n.props['data-test']==='today-meeting').length,2);
        click(s.button('Previous week')); await flush(); assert.match(s.calls[2],/week=2026-10-12/);
        click(s.button('This week')); await flush(); assert.equal(s.calls[3],'/api/teacher/masjids/1/schedule');
    }finally{s.unmount();}
});
test('teacher schedule Arabic and every carried locale has schedule words and logical direction',async()=>{
    const s=await screen('non_meeting','ar'); try {
        assert.match(s.text(),/جدولي/); assert.match(s.text(),/لا توجد دراسة اليوم/);
        assert.ok(s.all(n=>n.props.dir==='rtl').length>0);
        const picker=s.all(n=>n.tag==='select')[0]; picker.props.onChange({target:{value:'en'}}); await flush(); assert.match(s.text(),/My schedule/);
        for(const code of ['en','ar','es','ur','ps','fa-AF']) {s.lang.hook.setLang(code); await flush(); assert.doesNotMatch(s.text(),/schedule_/);}
    }finally{s.unmount();}
});
test('teacher schedule OFF menu retains base markup and the new view and layout are guarded against bare template',()=>{
    const layout=readFileSync(new URL('../layouts/TeacherLayout.vue',import.meta.url),'utf8');
    assert.match(layout,/v-if="timetableEnabled"/);
    const guards=readFileSync(new URL('./school-calendar-terms.test.ts',import.meta.url),'utf8');
    for(const f of ['layouts/TeacherLayout.vue','views/teacher/TeacherSchedule.vue','views/teacher/ScheduleMeeting.vue']) assert.ok(guards.includes(f),f);
});

test('teacher schedule menu follows selected school true-only header; OFF keeps base requests and visible menu',async()=>{
    const lang=await language();
    const helpers=await loadTs('core/helpers/teacherSchools.ts',{});
    async function layout(on:boolean,selectedId=1) {
        const calls:string[]=[];
        const auth={dashboardMasjidId:selectedId,user:{memberships:[{masjid_id:1,masjid:{name:'Practice School'}}]}};
        const api={get:async(url:string)=>{calls.push(url);return{data:{data:url.endsWith('/school')?{id:selectedId,name:'Practice School',school_calendar_published:true,...(on?{school_timetable_enabled:true}:{})}:{} }}},post:async()=>({})};
        const route=vue.reactive({path:'/teacher',name:'teacherClasses'});
        const modules={vue,'@/core/services/TeacherApiService':{default:api},'@/components/teacher/TeacherSchoolPicker.vue':{default:vue.defineComponent({render:()=>null})},'@/components/dashboard/TenantMismatchNotice.vue':{default:vue.defineComponent({render:()=>null})},'@/stores/authStore':{useAuthStore:()=>auth},'@/core/pageTitle':{setOrgTitle:()=>{}},'vue-router':{useRoute:()=>route,useRouter:()=>({push:()=>{}})},'@/core/helpers/teacherSchools':helpers,'@/core/tenancy/teacherSchoolGuard':{clearTeacherSchoolNotices:()=>{},provideSelectedSchool:()=>{},teacherSchoolMismatch:vue.ref(null),teacherSchoolRefused:vue.ref(false)},'@/core/tenancy/tenantRequests':{bumpTenantEpoch:()=>{},forgetServerTenant:()=>{}},'@/stores/plugins/tenantStoreReset':{resetTenantScopedStores:()=>{}},'@/core/helpers/staffChrome':{useStaffChrome:()=>{}},'@/views/family/familyI18n':lang.module};
        const s=await mountSfc('layouts/TeacherLayout.vue',{},modules);await flush();return{...s,calls};
    }
    const off=await layout(false,2);try{assert.doesNotMatch(off.text(),/My schedule/);assert.deepEqual(off.calls,['/api/teacher/user','/api/teacher/masjids/2/school']);assert.match(off.text(),/Help/);assert.match(off.text(),/Sign out/);}finally{off.unmount();}
    const on=await layout(true);try{assert.match(on.text(),/My schedule/);assert.deepEqual(on.calls,['/api/teacher/user','/api/teacher/masjids/1/school']);}finally{on.unmount();}
});

test('teacher schedule marker clock respects the end boundary and a different school date without another read',async()=>{
    let now=Date.parse('2026-10-12T13:20:00Z'); let tick:(()=>void)|null=null;
    mock.method(Date,'now',()=>now); mock.method(globalThis,'setInterval',(callback:()=>void)=>{tick=callback;return 123;});
    const s=await screen();try{
        assert.match(s.text(),/Happening now/); now=Date.parse('2026-10-12T13:55:00Z'); tick!(); await flush();
        assert.doesNotMatch(s.text(),/Happening now/); assert.equal(s.all(n=>n.props['data-test']==='today-meeting' && n.textContent.includes('Next')).length,1);
        now=Date.parse('2026-10-13T13:20:00Z'); tick!();await flush(); assert.doesNotMatch(s.text(),/Happening now|Next (?=Practice Class)/);assert.equal(s.all(n=>n.props['data-test']==='today-meeting' && n.textContent.includes('Next')).length,0);
        assert.equal(s.calls.length,1);
    }finally{s.unmount();mock.restoreAll();}
});
