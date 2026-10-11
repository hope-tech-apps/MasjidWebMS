import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { mountSfc, loadTs, flush, click, type, blur, select, submit, httpError, deferred } from './support/mountSfc.ts';
const require = createRequire(import.meta.url); const vue = require('vue');
async function screen(on = true, zeroRooms = false, options: any = {}) {
    const periods = [{ id: 21, name: 'P1', starts_at: '08:00', ends_at: '09:00', kind: 'teaching', position: 1 }];
    const data: any = { today: '2026-10-10', years: [{ id: 7, label: 'Practice year', first_day: '2026-09-07', last_day: '2027-06-25' }], weekdays: [1,5], sets: [{ id: 1, name: 'Regular', periods }, { id: 2, name: 'Friday', periods: [{ ...periods[0], id: 22, starts_at: '08:30', ends_at: '09:15' }] }], days: [{ weekday: 1, period_set_id: 1 }, { weekday: 5, period_set_id: 2 }], classes: [{ id: 3, name: 'Practice Class', room_id: 4, teachers: [8], subjects: [{ id: 10, name: 'Science', teacher_ids: [8] }] }], rooms: [{ id: 4, name: 'Practice Hall', capacity: null, active: true }], teachers: [{ id: 8, name: 'Practice Teacher' }], class_subjects_enabled: true };
    data.year = data.years[0];
    if (options.classes) data.classes = options.classes;
    const week = { days: data.days.map((d: any) => ({ ...d, periods: data.sets.find((s: any) => s.id === d.period_set_id).periods })), meetings: [{ id: 9, group_id: 3, weekday: 1, period_id: 21, kind: 'subject', class_subject_id: 10, label: 'Science', room_id: 4, room_name: 'Practice Hall', teachers: [data.teachers[0]], effective_from: '2026-10-10' }] };
    const clash = { kind: 'teacher', name: 'Practice Teacher', meetings: [{ group_name: 'Practice Other', label: 'Reading', starts_at: '08:00', ends_at: '09:00' }], effective_from: '2026-10-10', effective_until: '2027-06-25' };
    Object.assign(week, { clashes: [clash] });
    if (zeroRooms) { data.rooms = []; data.classes[0].room_id = null; week.meetings[0].room_id = null; week.meetings[0].room_name = null; }
    if (options.sameSet) week.days[1].period_set_id = 1;
    if (options.oneDay) week.days.splice(1);
    if (options.state) Object.assign(week, options.state, options.keepMeetings ? {} : { meetings: [] });
    const calls: any[] = []; let conflict = false; let confirmationRound = 0; let hold = false; let release: (() => void) | null = null;
    const api = { get: async (url: string) => { calls.push({ method: 'get', url }); if ((url.includes('/clashes') || url.includes('/week')) && options.read) await options.read(url); if (url.includes('/week') && hold) await new Promise<void>(resolve => { release = resolve; }); return { data: { data: url.includes('/week') ? (options.weekAt?.(url,week) ?? week) : url.includes('/clashes') ? [clash] : data } }; }, post: async (url: string, body: any) => { calls.push({ method: 'post', url, body }); if (conflict && (!body.confirm_clashes || confirmationRound++ === 0)) throw httpError(409, { clashes: body.confirm_clashes ? [{ ...clash, name: 'Practice New Clash' }] : [clash], clash_fingerprint: body.confirm_clashes ? 'b'.repeat(64) : 'a'.repeat(64) }); return { data: { data: {} } }; }, put: async (url: string, body: any) => { calls.push({ method: 'put', url, body }); return { data: { data: {} } }; }, deleteWithBody: async (url: string, body: any) => { calls.push({ method: 'delete', url, body }); return { data: { data: {} } }; }, delete: async (url: string, body: any) => { calls.push({ method: 'delete', url, body }); return { data: { data: {} } }; } };
    const dates = await loadTs('core/types/data/masjid-related/SchoolCalendar.ts', {});
    const times = await loadTs('core/helpers/scheduledSend.ts', {});
    const committed = await loadTs('composables/useCommittedDate.ts', { vue });
    const s = await mountSfc('views/dashboard/SchoolTimetableView.vue', {}, { vue, '@/composables/useCommittedDate': committed, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/scheduledSend': times, './OfficeSchedules.vue': { default: vue.defineComponent({render:()=>null}) }, '@/core/services/ApiService': { default: api }, '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { capabilities: { school_timetable: on } } }) } }); await flush(); if (on) { click(s.button('Class week')); await flush(); }
    return { ...s, calls, holdWeek: () => { hold = true; }, releaseWeek: () => { hold = false; release?.(); }, conflict: () => { conflict = true; } };
}
test('Timetable OFF makes no requests and hides controls', async () => { const s = await screen(false); try { assert.equal(s.calls.length, 0); assert.doesNotMatch(s.text(), /Add period set|Place meeting/); } finally { s.unmount(); } });
test('Timetable setup mounted edits sets locations weekdays and usual location', async () => {
    const s = await screen();
    try {
        click(s.button('Setup')); await flush(); assert.match(s.text(), /This changes the times for the whole year/);
        click(s.button('Add period set')); await flush(); type(s.all(n => n.props.id === 'set-name')[0], 'Practice New');
        click(s.all(n=>n.tag==='button' && n.textContent==='Add period')[0]); await flush(); type(s.all(n=>n.props.id==='period-name-0')[0],'P1'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.equal(s.calls.find(c=>c.method==='post').body.name,'Practice New');
        click(s.button('Edit Practice Hall')); await flush(); type(s.all(n=>n.props.id==='room-name')[0], 'Practice Library'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.deepEqual(s.calls.find(c=>c.url.endsWith('/rooms/4')).body,{name:'Practice Library',capacity:null,active:true});
        const day=s.all(n=>n.props.id==='school-day-1')[0]; day.value='2'; day.props.onChange({target:day}); await flush();
        assert.deepEqual(s.calls.find(c=>c.url.endsWith('/days')).body,{group_id:null,days:[{weekday:1,period_set_id:2}]});
        const usual=s.all(n=>n.props.id==='usual-room-3')[0]; usual.value=''; usual.props.onChange({target:usual}); await flush();
        assert.deepEqual(s.calls.find(c=>c.url.endsWith('/classes/3/room')).body,{room_id:null});
        assert.match(s.text(), /Locations|Location/); assert.doesNotMatch(s.text(), /Rooms|Usual room/);
    } finally { s.unmount(); }
});
test('Timetable class week has each weekday own times as of control and filled cell', async () => {
    const s = await screen();
    try {
        const columns=s.all(n=>n.tag==='section' && n.props.class==='tt-day'); assert.equal(columns.length,2);
        assert.match(columns[0].textContent,/Monday/); assert.match(columns[0].textContent,/8:00/); assert.match(columns[0].textContent,/9:00 AM/); assert.doesNotMatch(columns[0].textContent,/8:30/);
        assert.match(columns[1].textContent,/Friday/); assert.match(columns[1].textContent,/8:30/); assert.match(columns[1].textContent,/9:15 AM/); assert.doesNotMatch(columns[1].textContent,/8:00/);
        assert.match(s.text(),/Science/); assert.match(s.text(),/Practice Teacher/); assert.match(s.text(),/Practice Hall/);
        type(s.all(n=>n.props.id==='as-of')[0], '2026-11-01'); blur(s.all(n=>n.props.id==='as-of')[0]); await flush(); assert.ok(s.calls.some(c=>c.url.includes('as_of=2026-11-01')));
        click(s.button('Science')); await flush(); assert.match(s.text(), /From which date/); assert.match(s.text(),/Remove meeting/);
    } finally { s.unmount(); }
});
test('Timetable Save anyway echoes reviewed fingerprint and a fresh 409 replaces the list', async () => {
    const s = await screen();
    try {
        s.conflict(); click(s.button('Place meeting')); await flush(); select(s.all(n=>n.props.id==='meeting-kind')[0], 'activity'); await flush(); type(s.all(n=>n.props.id==='activity-name')[0], 'Break duty'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.match(s.text(), /Practice Other/); assert.match(s.text(),/Reading/); assert.match(s.text(),/Go back/);
        click(s.button('Save anyway')); await flush(); let saves=s.calls.filter(c=>c.method==='post');
        assert.deepEqual(saves[1].body,{...saves[0].body,confirm_clashes:true,clash_fingerprint:'a'.repeat(64)});
        assert.match(s.text(),/Practice New Clash/); click(s.button('Save anyway')); await flush(); saves=s.calls.filter(c=>c.method==='post');
        assert.deepEqual(saves[2].body,{...saves[0].body,confirm_clashes:true,clash_fingerprint:'b'.repeat(64)});
    } finally { s.unmount(); }
});
test('Timetable clashes panel and header count mount', async () => { const s=await screen(); try { click(s.button('Clashes (1)')); await flush(); assert.match(s.text(), /Practice Teacher|Practice Other|Reading|08:00/); } finally { s.unmount(); } });

test('Timetable as-of date clears earlier cells while the new dated read is pending', async () => {
    const s=await screen();
    try { assert.match(s.text(),/Science/); s.holdWeek(); type(s.all(n=>n.props.id==='as-of')[0],'2026-11-01'); blur(s.all(n=>n.props.id==='as-of')[0]); await flush(); assert.doesNotMatch(s.text(),/Science/); assert.match(s.text(),/Loading class week/); s.releaseWeek(); await flush(); assert.match(s.text(),/Science/); }
    finally { s.releaseWeek(); s.unmount(); }
});

test('Timetable zero locations names the class and has no empty location labels or required setup', async () => {
    const s=await screen(true,true);
    try {
        assert.match(s.text(), /Practice Class/); assert.doesNotMatch(s.text(), /No room|Room:|Location:|[—]/);
        assert.equal(s.all(n=>n.tag==='p' && n.textContent==='').length,0);
        click(s.button('Setup')); await flush(); assert.match(s.text(),/Locations are optional/); assert.doesNotMatch(s.text(),/Rooms|Usual room/);
        click(s.button('Class week')); await flush(); click(s.button('Place meeting')); await flush(); assert.match(s.text(),/Location \(optional\)/); assert.doesNotMatch(s.text(),/Room/);
    } finally { s.unmount(); }
});

const timetableFiles = ['views/dashboard/groups/GroupAttendanceTab.vue', 'views/dashboard/GroupDetailView.vue', 'views/dashboard/SchoolTimetableView.vue', 'views/dashboard/OfficeSchedules.vue', 'views/teacher/ScheduleWeek.vue', 'components/classes/ClassLocationField.vue', 'views/teacher/TeacherSchedule.vue', 'views/teacher/ScheduleMeeting.vue', 'layouts/TeacherLayout.vue'];
test('Timetable theme source uses no private style palette', () => {
    for (const file of timetableFiles) {
        const source = readFileSync(new URL('../'+file, import.meta.url), 'utf8');
        for (const style of source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)) {
            assert.doesNotMatch(style[1], /#[0-9a-f]{3,8}\b/i, file);
            for (const rule of style[1].matchAll(/(?:background(?:-color)?|color)\s*:\s*([^;}]*)/g)) assert.match(rule[1], /^var\(/, file);
        }
    }
});
test('Timetable change and remove name the slot and remove submits a Remove button', async () => {
    const s = await screen(); try {
        click(s.button('Science')); await flush(); assert.match(s.text(), /Change Science in Practice Class, P1, Monday/);
        click(s.button('Remove meeting')); await flush(); assert.match(s.text(), /Remove Science from Practice Class, P1, Monday/);
        assert.equal(s.button('Remove').textContent, 'Remove'); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.equal(s.calls.find(c=>c.method==='delete').body.effective_from,'2026-10-10');
    } finally { s.unmount(); }
});
test('Timetable bounds reject typed dates locally and good read clears server refusal and Retry', async () => {
    let fail = true; const s = await screen(true,false,{read:()=>{if(fail) throw httpError(422,{data:{as_of:['The as of field must match the format Y-m-d.']}});}});
    try {
        assert.match(s.text(), /Pick a date between September 7, 2026 and June 25, 2027\./); assert.doesNotMatch(s.text(), /must match/);
        const input=s.all(n=>n.props.id==='as-of')[0]; assert.equal(input.props.min,'2026-09-07'); assert.equal(input.props.max,'2027-06-25');
        const count=s.calls.length; type(input,'252026-11-01'); blur(input); await flush(); assert.equal(s.calls.length,count);
        fail=false; type(input,'2026-11-01'); blur(input); await flush(); assert.doesNotMatch(s.text(), /Pick a date|Retry/); assert.match(s.text(), /Science/);
    } finally {s.unmount();}
});
test('Timetable failed read recovers; dates commit once and older answers cannot replace newer ones', async () => {
    const old=deferred(); let fail=false; const s=await screen(true,false,{read:(url:string)=>{
        if(url.includes('2026-11-01')) return old.promise; if(fail) throw httpError(500,{message:'Failed read'});
    }});
    try {
        const input=s.all(n=>n.props.id==='as-of')[0]; const count=s.calls.length;
        for(const date of ['0002-11-01','0020-11-01','0202-11-01','2026-11-01']) {type(input,date); await flush();}
        assert.equal(s.calls.length,count); blur(input); await flush(); assert.equal(s.calls.length,count+1);
        type(input,'2026-11-08'); blur(input); await flush(); const newest=s.calls.length; blur(input); await flush(); assert.equal(s.calls.length,newest);
        old.reject(httpError(500,{message:'Old error'})); await flush(); assert.doesNotMatch(s.text(), /Old error|Retry/);
        fail=true; type(input,'2026-11-15'); blur(input); await flush(); assert.match(s.text(),/Retry/);
        fail=false; type(input,'2026-11-22'); blur(input); await flush(); assert.doesNotMatch(s.text(), /Retry|Failed read/);
    } finally {old.resolve({});s.unmount();}
});
test('Timetable copy only appears for another day with the same set', async () => {
    for(const options of [{oneDay:true},{},{sameSet:true}]) {
        const s=await screen(true,false,options); try {
            const buttons=s.all(n=>n.tag==='button' && n.textContent.startsWith('Copy ')); assert.equal(buttons.length,options.sameSet?2:0);
            if(options.sameSet) {click(buttons[0]);await flush();assert.match(s.text(),/Friday/);}
        }finally{s.unmount();}
    }
});
test('Timetable re-walk: a closure or non-meeting date is said above a class week that has meetings',async()=>{
    for(const state of [{state:'non_meeting',reason:null},{state:'closure',reason:'Planning day'}]) {
        const s=await screen(true,false,{state,keepMeetings:true});try {
            assert.match(s.text(),state.state==='closure'?/School is closed on .*Planning day\./:/The school does not meet on /);
            assert.match(s.text(),/The week below is the timetable as it stands on that date\./);assert.match(s.text(),/Science/,'the standing week is still drawn');
        }finally{s.unmount();}
    }
});
const pause = () => new Promise(r => setTimeout(r, 450));
test('Timetable re-walk: a typed date loads once typing stops or on Enter, a half-typed year waits, and a bounds refusal offers no Retry',async()=>{
    const s=await screen();try {
        const input=s.all(n=>n.props.id==='as-of')[0];const count=s.calls.length;
        for(const date of ['0002-11-01','0020-11-01','0202-11-01']) {type(input,date);input.props.onChange?.({target:input});await flush();}
        await pause();await flush();assert.equal(s.calls.length,count);assert.doesNotMatch(s.text(),/Pick a date/,'a year still being typed is never refused');
        // Typing 11/15 over another date passes through other complete dates: only the last is asked for.
        for(const date of ['2026-01-10','2026-11-01','2026-11-15']) {type(input,date);input.props.onChange?.({target:input});await flush();}
        assert.equal(s.calls.length,count,'nothing is asked while keys are still arriving');
        await pause();await flush();assert.equal(s.calls.length,count+1);assert.match(String(s.calls.at(-1).url),/as_of=2026-11-15/);
        type(input,'2026-11-09');input.props.onChange?.({target:input});input.props.onKeydown?.({key:'Enter',target:input,preventDefault(){}});await flush();assert.equal(s.calls.length,count+2);
        await pause();await flush();assert.equal(s.calls.length,count+2,'Enter is not followed by a second read');
        type(input,'2030-01-07');input.props.onChange?.({target:input});await pause();await flush();assert.equal(s.calls.length,count+2);assert.match(s.text(),/Pick a date between/);assert.doesNotMatch(s.text(),/Retry/);
    }finally{s.unmount();}
});
test('Timetable closed and non-meeting as-of states explain empty week without placement buttons',async()=>{
    for(const state of [{state:'non_meeting',reason:null},{state:'closure',reason:'Planning day'}]) {
        const s=await screen(true,false,{state});try {assert.match(s.text(),state.state==='closure'?/Planning day/:/The school does not meet/);assert.doesNotMatch(s.text(),/Place meeting/);}finally{s.unmount();}
    }
});
test('Timetable Add period follows previous end and duration with 45 minute first row',async()=>{
    const s=await screen();try {
        click(s.button('Setup'));await flush();click(s.button('Add period set'));await flush();click(s.all(n=>n.tag==='button' && n.textContent==='Add period')[0]);await flush();
        assert.equal(s.all(n=>n.props.id==='period-start-0')[0].value,'08:00');assert.equal(s.all(n=>n.props.id==='period-end-0')[0].value,'08:45');
        type(s.all(n=>n.props.id==='period-start-0')[0],'12:45');type(s.all(n=>n.props.id==='period-end-0')[0],'13:30');await flush();click(s.all(n=>n.tag==='button' && n.textContent==='Add period')[0]);await flush();
        assert.equal(s.all(n=>n.props.id==='period-start-1')[0].value,'13:30');assert.equal(s.all(n=>n.props.id==='period-end-1')[0].value,'14:15');
    }finally{s.unmount();}
});
test('Timetable clocks use existing school-local 12-hour wording',async()=>{
    const s=await screen();try {assert.match(s.text(),/8:00 - 9:00 AM/);click(s.button('Clashes (1)'));await flush();assert.match(s.text(),/Practice Teacher is in two places: Practice Other \(Reading\),/);assert.match(s.text(),/October 10, 2026/);assert.doesNotMatch(s.text(),/2026-10-10|teacher:/);}finally{s.unmount();}
});
test('Timetable OFF typed route returns the pre-feature not-found page for both office roles', async () => {
    for (const role of ['SuperAdmin','MasjidAdmin']) {
        let guard:any; const auth={isAuthenticated:true,user:{type:role}};
        await loadTs('router/router.ts',{'vue-router':{createRouter:()=>({beforeEach:(fn:any)=>guard=fn}),createWebHistory:()=>({})},'@/router/routes/routes':{default:[]},'@/stores/authStore':{useAuthStore:()=>auth},'@/stores/config/dashboardAsideStore':{useDashboardAsideStore:()=>({})},'@/core/constants/dashboardAsideMenuItems':{MASJID_DASHBOARD_ASIDE_MENU:[],SUPER_DASHBOARD_ASIDE_MENU:[]},'@/stores/masjidStore':{useMasjidStore:()=>({masjid:{capabilities:{school_timetable:false}}})},'@/core/constants/appConfigConstants':{LOCAL_STORAGE_KEYS:{}},'@/core/access/orgAccess':{hasGrant:()=>false,moduleIsOff:()=>false},'@/core/pageTitle':{setPageTitle:()=>{}}});
        (globalThis as any).document.documentElement={scroll:()=>{}};let destination:any;
        guard({fullPath:'/masjid/timetable',meta:{auth:true,allowedUsers:['SuperAdmin','MasjidAdmin'],requiresCapability:'school_timetable'}},{},(next:any)=>destination=next);
        assert.equal(destination,'/auth/404');
    }
});

test('Timetable teacher typed schedule route uses only the selected schools true-only flag', async () => {
    const calls:string[]=[];
    const auth={isAuthenticated:true,dashboardMasjidId:2,user:{type:'Teacher',masjid:{id:1,school_timetable_enabled:true},memberships:[{masjid_id:1,masjid:{school_timetable_enabled:true}},{masjid_id:2,masjid:{}}]}};
    let flag:any;
    const routes=await loadTs('router/routes/teacherRoutes.ts',{'vue-router':{},'@/stores/authStore':{useAuthStore:()=>auth},'@/core/services/TeacherApiService':{default:{get:async(url:string)=>{calls.push(url);return{data:{data:{id:2,school_timetable_enabled:flag}}};}}}});
    const route=routes.default[0].children.find((r:any)=>r.path==='schedule');
    assert.equal(typeof route.beforeEnter,'function');
    for (flag of [undefined,false,'true',1,true]) {
        assert.equal(await route.beforeEnter(),flag===true?true:'/auth/404');
    }
    assert.deepEqual(calls,Array(5).fill('/api/teacher/masjids/2/school'));
});

test('Timetable mounted controls and modal use neighbouring Bootstrap theme classes', async () => {
    const s=await screen();try {
        click(s.button('Science'));await flush();
        assert.ok(s.all(n=>n.props.role==='dialog' && String(n.props.class).includes('modal-content')).length);
        assert.ok(s.all(n=>String(n.props.class).includes('modal-backdrop')).length);
        for(const control of s.all(n=>['button','input','select'].includes(n.tag))) {
            assert.match(String(control.props.class),control.tag==='button'?/btn/:control.tag==='select'?/form-select/:/form-control|form-check-input/);
        }
    }finally{s.unmount();}
});
test('Timetable shared clocks retain noon midnight and school-wall dates',async()=>{
    const times=await loadTs('core/helpers/scheduledSend.ts',{});
    assert.equal(times.schoolTimeRange('12:45','13:30'),'12:45 - 1:30 PM');
    assert.equal(times.schoolTimeRange('00:00','00:45'),'12:00 - 12:45 AM');
    assert.equal(times.schoolTimeRange('11:40','12:20'),'11:40 AM - 12:20 PM');
});
test('Timetable committed as-of date fetches week and clashes in one HTTP read',async()=>{
    const s=await screen();try {
        const count=s.calls.length;const input=s.all(n=>n.props.id==='as-of')[0];type(input,'2026-11-01');blur(input);await flush();
        assert.equal(s.calls.length,count+1);assert.match(s.calls.at(-1).url,/\/week\?.*include_clashes=1/);assert.match(s.text(),/Clashes \(1\)/);
    }finally{s.unmount();}
});
test('Timetable date asks once for the date left in the box and never again on leaving it',async()=>{
    const s=await screen();try {
        const input=s.all(n=>n.props.id==='as-of')[0];const count=s.calls.length;
        for(const date of ['2026-11-01','2026-11-02','2026-11-22']) {
            type(input,date);input.props.onChange?.({target:input});await flush();
        }
        blur(input);await flush();assert.equal(s.calls.length,count+1);await pause();await flush();assert.equal(s.calls.length,count+1);
    }finally{s.unmount();}
});

test('Timetable older successful week cannot overwrite the newer committed date',async()=>{
    const old=deferred();const s=await screen(true,false,{read:(url:string)=>url.includes('as_of=2026-11-01')?old.promise:undefined,weekAt:(url:string,week:any)=>url.includes('as_of=2026-11-01')?{...week,meetings:week.meetings.map((m:any)=>({...m,label:'Old answer'}))}:week});
    try {
        const input=s.all(n=>n.props.id==='as-of')[0];type(input,'2026-11-01');blur(input);await flush();type(input,'2026-11-08');blur(input);await flush();
        assert.match(s.text(),/Science/);old.resolve({});await flush();assert.doesNotMatch(s.text(),/Old answer/);assert.match(s.text(),/Science/);
    }finally{old.resolve({});s.unmount();}
});

for (const count of [2,12]) test(`Setup class locations overview is read-only and includes all ${count} active classes`, async () => {
    const classes = Array.from({length:count}, (_,i) => ({id:i+3,name:`Practice Class ${i+1}`,room_id:i===0?4:null,teachers:[],subjects:[]}));
    const s = await screen(true,false,{classes});
    try {
        click(s.button('Setup')); await flush();
        const table = s.all(n => n.tag === 'table' && n.props['aria-label'] === 'Class locations')[0];
        assert.ok(table); assert.equal(table.children.find(n => n.tag === 'tbody')?.children.filter(n => n.tag === 'tr').length,count);
        assert.match(table.textContent,/Class\s*Location\s*Practice Class 1\s*Practice Hall/);
        assert.match(table.textContent,/Practice Class 2\s*No location set/);
        assert.equal(s.all(n => ['input','select','button'].includes(n.tag) && (()=>{let p=n.parent;while(p){if(p===table)return true;p=p.parent;}return false;})()).length,0);
    } finally { s.unmount(); }
});
