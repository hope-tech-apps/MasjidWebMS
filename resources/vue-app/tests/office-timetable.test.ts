import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { mountSfc, compileSfc, loadTs, flush, click, chooseOption as select, type, blur, deferred, httpError } from './support/mountSfc.ts';
const require = createRequire(import.meta.url); const vue = require('vue');

const teachers = [{id:8,name:'Practice Specialist'},{id:9,name:'Practice Junior Teacher'},{id:10,name:'Practice Senior Teacher'},{id:11,name:'Practice Duty'},{id:12,name:'Practice Unplaced'}];
const groups = [{id:3,name:'Practice Junior'},{id:4,name:'Practice Senior'}];
const clocks = [['08:15','09:00'],['09:10','09:55'],['10:05','10:50'],['11:50','12:35'],['12:45','13:30'],['14:00','14:45']];
const periods = clocks.map(([starts_at,ends_at],i)=>({id:21+i,name:`P${i+1}`,starts_at,ends_at,kind:'teaching'}));
const slot = (id:number, group:number, period:number, teacherIds:number[], extra:any={}) => ({id,group_id:group,group_name:groups.find(g=>g.id===group)!.name,period_id:periods[period].id,kind:'subject',label:'Science',starts_at:periods[period].starts_at,ends_at:periods[period].ends_at,teachers:teachers.filter(t=>teacherIds.includes(t.id)),co_teachers:teachers.filter(t=>teacherIds.includes(t.id)),room_name:null,href:null,effective_from:'2026-09-07',effective_until:null,...extra});
const wholeMeetings = [slot(1,3,0,[9]),slot(2,4,0,[10]),slot(3,4,1,[8,10]),slot(4,3,2,[9]),slot(5,4,2,[10]),slot(6,4,3,[8]),slot(7,4,4,[8]),slot(8,3,4,[11],{kind:'activity',label:'Nap'}),slot(9,3,5,[9])];

async function screen(options:any={}) {
    const calls:string[]=[]; const emitted:any[]=[];
    const setup:any={year:{id:7,label:'Practice year',first_day:'2026-09-07',last_day:'2027-06-25'},today:'2026-10-14',weekdays:[1,2,3,4,5],teachers,classes:groups,rooms:options.locations?[{id:5,name:'Practice Hall'}]:[]};
    if(options.weekend) {setup.weekdays=[0];setup.today='2026-10-18';}
    function response(url:string) {
        const match=/schedules\/(\w+)(?:\/(\d+))?/.exec(url)!; const lens=match[1];const selectionId=Number(match[2]);
        const query=new URLSearchParams(url.split('?')[1]);const date=query.get('as_of')!; const weekday=Number(query.get('weekday')??(options.weekend?0:3));
        let meetings=wholeMeetings;
        if(options.weekend) meetings=[slot(20,3,0,[8],{starts_at:'11:00',ends_at:'11:40',column_key:'period:21'}),slot(21,4,0,[8],{starts_at:'11:00',ends_at:'11:45',column_key:'period:21'}),slot(22,3,1,[9,10],{starts_at:'11:40',ends_at:'12:20',column_key:'period:22'}),slot(23,4,1,[8],{starts_at:'11:45',ends_at:'12:40',column_key:'period:22'})];
        if(options.dated) meetings=lens==='teacher'?[slot(30,3,1,[8],{effective_until:'2026-10-14'}),slot(31,3,1,[10],{effective_from:'2026-10-15'})]:date<'2026-10-15'?[slot(30,3,1,[8],{effective_until:'2026-10-14'})]:[slot(31,3,1,[10],{effective_from:'2026-10-15'})];
        const closures=options.closure;
        if(closures) meetings=[];
        const dayDates=options.weekend?['2026-10-18']:['2026-10-12','2026-10-13','2026-10-14','2026-10-15','2026-10-16'];
        let days=setup.weekdays.map((dw:number,i:number)=>({date:dayDates[i],weekday:dw,state:closures?'closure':'meetings',reason:closures?'Practice planning day':null,periods:lens==='class'?periods:null,meetings:dw===5&&!options.dated?[]:meetings.filter(m=>lens==='teacher'?m.teachers.some(t=>t.id===selectionId)&&m.effective_from<=dayDates[i]&&(!m.effective_until||m.effective_until>=dayDates[i]):lens==='class'?m.group_id===selectionId:true).map(m=>({...m,co_teachers:m.teachers.filter(t=>lens!=='teacher'||t.id!==selectionId),room_name:options.locations?'Practice Hall':null}))}));
        if(lens==='teacher') days=days.filter((day:any)=>day.meetings.length||day.reason||day.date===setup.today);
        const columns=options.weekend?[{key:'period:21',starts_at:'11:00',ends_at:'11:40',names:['P1']},{key:'period:22',starts_at:'11:40',ends_at:'12:20',names:['P2']},{key:'period:23',starts_at:'12:20',ends_at:'12:45',names:['Break']},{key:'period:24',starts_at:'12:45',ends_at:'13:30',names:['P3']},{key:'period:25',starts_at:'13:30',ends_at:'13:40',names:['Cleaning']},{key:'period:26',starts_at:'13:40',ends_at:'14:00',names:['Prayer']}]:periods.map(p=>({...p,key:`period:${p.id}`,names:[p.name]}));
        meetings=meetings.map(m=>({...m,column_key:m.column_key??`period:${m.period_id}`}));
        const data:any={as_of:date,week_start:'2026-10-12',week_end:'2026-10-18',days,lens,selection_id:selectionId,weekday,day:days.find((d:any)=>d.weekday===weekday)??days[0],columns,meetings:weekday===5?[]:meetings,clashes:options.weekend?[{kind:'teacher',id:8,name:'Practice Specialist',meetings:meetings.slice(0,2)}]:[],placed_teacher_ids:[8,9,10,11],teachers,classes:groups,may_edit:true,as_of_state:closures?'closure':'meetings',as_of_reason:closures?'Practice planning day':null};
        if(options.weekend) data.placed_teacher_ids=[8];
        return data;
    }
    const api={get:async(url:string)=>{calls.push(url); if(options.parent && !url.includes('/schedules/')) return {data:{data:url.endsWith('/setup')?{...setup,sets:[],days:[],class_days:[],class_subjects_enabled:true}:url.includes('/week?')?{days:[{weekday:3,periods}],meetings:wholeMeetings,clashes:[]}: {today:setup.today,years:[setup.year]}}}; if(options.read) await options.read(url);return{data:{data:options.pageAt?.(url,response(url))??response(url)}};}};
    const dates=await loadTs('core/types/data/masjid-related/SchoolCalendar.ts',{});const times=await loadTs('core/helpers/scheduledSend.ts',{});
    const link=vue.defineComponent({props:['to'],setup:(props:any,{slots}:any)=>()=>vue.h('a',{href:props.to},slots.default?.())});
    const meeting=await compileSfc('views/teacher/ScheduleMeeting.vue',{'@/core/types/data/masjid-related/SchoolCalendar':dates,'@/core/helpers/scheduledSend':times,'vue-router':{RouterLink:link}});
    const week=await compileSfc('views/teacher/ScheduleWeek.vue',{'@/core/types/data/masjid-related/SchoolCalendar':dates,'@/core/helpers/scheduledSend':times,'./ScheduleMeeting.vue':{default:meeting}});
    const committed = await loadTs('composables/useCommittedDate.ts', { vue });
    const modules={'@/composables/useCommittedDate': committed,'@/core/services/ApiService':{default:api},'@/core/types/data/masjid-related/SchoolCalendar':dates,'@/core/helpers/scheduledSend':times,'../teacher/ScheduleMeeting.vue':{default:meeting},'../teacher/ScheduleWeek.vue':{default:week}};
    const child=options.parent?await compileSfc('views/dashboard/OfficeSchedules.vue',modules):null;
    const s=options.parent?await mountSfc('views/dashboard/SchoolTimetableView.vue',{}, {...modules,'./OfficeSchedules.vue':{default:child},'@/stores/authStore':{useAuthStore:()=>({dashboardMasjidId:1})},'@/stores/masjidStore':{useMasjidStore:()=>({masjid:{capabilities:{school_timetable:true}}})}}):await mountSfc('views/dashboard/OfficeSchedules.vue',{base:'/api/admin/masjids/1/timetable/years/7',setup,onEditClass:(...args:any[])=>emitted.push(args)},modules);await flush();
    return {...s,calls,emitted,setup,modules};
}
const control=(s:any,id:string)=>s.all((n:any)=>n.props.id===id)[0];
const row=(s:any,id:number)=>s.all((n:any)=>n.tag==='tr'&&n.props['data-row-id']===id)[0];
const cell=(r:any,clock:string)=>r.children.find((n:any)=>n.tag==='td'&&n.props['data-clock']===clock);

test('office timetable opens Whole school with lead specialist duty free teachers and a local Classes toggle',async()=>{
    const s=await screen();try{
        assert.equal(s.calls.length,1);assert.match(s.calls[0],/schedules\/school\?as_of=2026-10-14$/);
        assert.match(cell(row(s,9),'08:15').textContent,/Practice Junior/);assert.match(cell(row(s,10),'08:15').textContent,/Practice Senior/);
        for(const time of ['09:10','11:50','12:45']) assert.match(cell(row(s,8),time).textContent,/Practice Senior/);
        for(const time of ['08:15','10:05','14:00']) assert.equal(cell(row(s,8),time).textContent,'');
        assert.match(cell(row(s,11),'12:45').textContent,/Nap/);assert.ok(row(s,12).children.filter((n:any)=>n.tag==='td').every((n:any)=>n.textContent===''));
        assert.match(s.text(),/Teachers with nothing placed.*Practice Unplaced/);assert.equal(s.all(n=>n.props['data-test']==='location').length,0);
        click(s.button('Classes'));await flush();assert.equal(s.calls.length,1);assert.match(cell(row(s,3),'08:15').textContent,/Practice Junior/);
        select(control(s,'schedule-weekday'),5);await flush();assert.equal(s.calls.length,2);assert.match(s.calls[1],/weekday=5$/);
        blur(control(s,'schedule-as-of'));await flush();assert.equal(s.calls.length,2);assert.match(s.calls.at(-1)!,/weekday=5$/);
    }finally{s.unmount();}
});

test('office whole school leaves the default day to the server and resets it when the date changes',async()=>{
    const s=await screen({pageAt:(_url:string,data:any)=>({...data,weekday:2,day:{date:'2026-10-13',weekday:2,state:'meetings',reason:null}})});
    try {
        assert.doesNotMatch(s.calls[0],/weekday=/);assert.match(s.text(),/October 13, 2026/);
        control(s,'schedule-weekday').props.onChange();await flush();assert.match(s.calls[1],/weekday=2$/,'the day control uses the server-selected weekday');
        select(control(s,'schedule-weekday'),1);await flush();assert.match(s.calls[2],/weekday=1$/);
        type(control(s,'schedule-as-of'),'2026-10-17');blur(control(s,'schedule-as-of'));await flush();
        assert.equal(s.calls.length,4);assert.match(s.calls[3],/as_of=2026-10-17$/);assert.doesNotMatch(s.calls[3],/weekday=/);
    }finally{s.unmount();}
});

test('office teacher and class controls reuse real week cards with co-teachers dates and no editing controls',async()=>{
    const s=await screen();try{
        click(s.button('By teacher'));await flush();assert.equal(s.calls.length,2);assert.match(s.calls[1],/teacher\/8/);
        const days=s.all(n=>n.props['data-test']==='week-day');assert.equal(days.length,4);
        for(const day of days) {assert.equal(day.children.flatMap((n:any)=>n.children).filter((n:any)=>String(n.props.class??'').includes('schedule-meeting')).length,3);assert.match(day.textContent,/Practice Senior Teacher/);}
        assert.ok(days.every(day=>!day.textContent.includes('Friday')));assert.doesNotMatch(days[0].textContent,/Practice Specialist/);
        assert.equal(s.all(n=>n.tag==='a'&&n.props.href?.startsWith('/teacher')).length,0);
        select(control(s,'schedule-teacher'),12);await flush();assert.equal(s.calls.length,3);assert.match(s.text(),/Nothing is placed for this teacher yet/);
        assert.match(control(s,'schedule-teacher').textContent,/Teachers with nothing placed|Practice Unplaced/);
        click(s.button('By class'));await flush();assert.equal(s.calls.length,4);assert.match(s.text(),/Edit this class's week/);assert.doesNotMatch(s.text(),/Place meeting|Copy Monday|Remove meeting/);
        select(control(s,'schedule-class'),4);await flush();assert.equal(s.calls.length,5);assert.match(s.calls.at(-1)!,/class\/4/);
        click(s.all(n=>n.tag==='a'&&n.textContent==="Edit this class's week")[0]);await flush();assert.deepEqual(s.emitted,[[4,'2026-10-14']]);
    }finally{s.unmount();}
});

test('office weekend school columns draw each card once with differing clocks and clash list badges',async()=>{
    const s=await screen({weekend:true});try{
        assert.doesNotMatch(s.text(),/By location/);assert.match(s.calls[0],/as_of=2026-10-18$/);
        const shared=row(s,8);assert.equal(shared.children.filter((n:any)=>n.tag==='td').length,6);
        assert.equal(cell(shared,'11:00').children.filter((n:any)=>n.props['data-meeting-id']).length,2);
        assert.equal(cell(shared,'11:40').children.filter((n:any)=>n.props['data-meeting-id']).length,1);
        assert.match(cell(shared,'11:40').textContent,/11:45 AM - 12:40 PM/);
        assert.doesNotMatch(cell(row(s,9),'11:40').textContent,/11:40 AM - 12:20 PM/);
        for(const id of [20,21,23]) assert.equal(s.all(n=>n.props['data-meeting-id']===id).length,1);
        let badges=s.all(n=>n.tag==='span'&&n.textContent==='Clash');assert.equal(badges.length,2);
        assert.match(badges[0].props.title,/Practice Senior/);assert.match(badges[1].props.title,/Practice Junior/);
        click(s.button('Classes'));await flush();
        for(const id of [20,21,22,23]) assert.equal(s.all(n=>n.props['data-meeting-id']===id).length,1,'co-teachers remain one class card');
        assert.equal(s.all(n=>n.tag==='span'&&n.textContent==='Clash').length,2);
        click(s.button('By teacher'));await flush();assert.equal(s.all(n=>n.props['data-test']==='week-day').length,1);
        assert.match(s.text(),/Teachers with nothing placed.*Practice Unplaced/);
    }finally{s.unmount();}
});

test('office location is offered when set and real cards show it only when present',async()=>{
    const s=await screen({locations:true});try{
        click(s.button('By location'));await flush();assert.equal(s.calls.length,2);assert.match(s.calls[1],/location\/5/);
        assert.ok(s.all(n=>n.props['data-test']==='location').length>0);assert.ok(s.all(n=>n.props['data-test']==='location').every(n=>n.textContent==='Practice Hall'));
    }finally{s.unmount();}
});

test('office committed date loads once bounded dates refuse locally and stale answers cannot replace a later view',async()=>{
    const old=deferred();let fail=false;const s=await screen({read:(url:string)=>{if(url.includes('2026-10-15'))return old.promise;if(fail)throw httpError(500,{message:'Practice failure'});}});try{
        const input=control(s,'schedule-as-of');assert.equal(input.props.min,'2026-09-07');assert.equal(input.props.max,'2027-06-25');
        for(const date of ['0002-10-15','0020-10-15','0202-10-15','2026-10-15']) {type(input,date);await flush();}assert.equal(s.calls.length,1);
        blur(input);await flush();assert.equal(s.calls.length,2);assert.doesNotMatch(s.text(),/Nap/);assert.match(s.text(),/Loading schedule/);
        click(s.button('By class'));await flush();type(input,'2026-10-16');blur(input);await flush();assert.match(s.text(),/As of October 16, 2026/);
        old.reject(httpError(500,{}));await flush();assert.doesNotMatch(s.text(),/could not be loaded/);
        const count=s.calls.length;blur(input);await flush();assert.equal(s.calls.length,count);
        type(input,'252026-10-16');blur(input);await flush();assert.equal(s.calls.length,count);assert.match(s.text(),/Pick a date between September 7, 2026 and June 25, 2027/);
        fail=true;type(input,'2026-10-19');blur(input);await flush();assert.match(s.text(),/Retry/);
        fail=false;click(s.button('Retry'));await flush();assert.doesNotMatch(s.text(),/Retry/);
    }finally{old.resolve({});s.unmount();}
});

test('office dated teacher and whole school controls replace old teachers and carry From Until notes',async()=>{
    const s=await screen({dated:true});try{
        click(s.button('By teacher'));await flush();assert.match(s.text(),/Until October 14, 2026/);
        type(control(s,'schedule-as-of'),'2026-10-15');blur(control(s,'schedule-as-of'));await flush();assert.match(s.text(),/Until October 14, 2026/);assert.doesNotMatch(s.text(),/From October 15, 2026/);
        select(control(s,'schedule-teacher'),10);await flush();assert.match(s.text(),/From October 15, 2026/);
        click(s.button('Whole school'));await flush();assert.equal(cell(row(s,8),'09:10').textContent,'');assert.match(cell(row(s,10),'09:10').textContent,/From October 15, 2026/);
    }finally{s.unmount();}
});

test('office closure states say why there are no meetings and never show editor controls',async()=>{
    const s=await screen({closure:true});try{
        assert.match(s.text(),/School is closed.*Practice planning day/);assert.equal(cell(row(s,8),'09:10').textContent,'');
        click(s.button('By class'));await flush();assert.match(s.text(),/No school: Practice planning day/);assert.doesNotMatch(s.text(),/Place meeting|Remove meeting/);
    }finally{s.unmount();}
});

test('office Print calls browser print with scoped schedule-only landscape repeated-header CSS and cleanup',async()=>{
    const active=new Set();let prints=0;const originalWindow=(globalThis as any).window;const originalBody=(globalThis as any).document.body;
    (globalThis as any).document.body={classList:{add:(s:string)=>active.add(s),remove:(s:string)=>active.delete(s)}};
    (globalThis as any).window={print:()=>{assert.ok(active.has('timetable-printing'));prints++;},addEventListener:()=>{},removeEventListener:()=>{}};
    const s=await screen();try{click(s.button('Print'));assert.equal(prints,1);assert.equal(active.size,0);}finally{s.unmount();(globalThis as any).window=originalWindow;(globalThis as any).document.body=originalBody;}
    const source=readFileSync(new URL('../views/dashboard/OfficeSchedules.vue',import.meta.url),'utf8');
    assert.match(source,/@page timetable-schedule\s*\{\s*size: landscape/);assert.match(source,/@media print/);assert.match(source,/body\.timetable-printing/);assert.match(source,/page: timetable-schedule/);assert.match(source,/thead \{ display: table-header-group/);assert.match(source,/inset-inline-start: 0/);assert.match(source,/overflow-x: auto/);
    for(const file of ['views/dashboard/OfficeSchedules.vue','views/teacher/ScheduleWeek.vue']) assert.ok(readFileSync(new URL('./school-calendar-terms.test.ts',import.meta.url),'utf8').includes(file));
});

test('office Timetable real parent opens Schedules Whole school once and Edit link opens the selected class week',async()=>{
    const s=await screen({parent:true});try{
        assert.equal(s.calls.length,3);assert.equal(s.calls.filter(url=>url.includes('/schedules/school')).length,1);assert.equal(s.calls.filter(url=>url.includes('/week?')).length,0);
        click(s.button('By class'));await flush();select(control(s,'schedule-class'),4);await flush();
        type(control(s,'schedule-as-of'),'2026-10-15');blur(control(s,'schedule-as-of'));await flush();
        click(s.all(n=>n.tag==='a'&&n.textContent==="Edit this class's week")[0]);await flush();
        assert.equal(s.calls.filter(url=>url.includes('/week?')).length,1);assert.match(s.calls.at(-1)!,/week\?group_id=4&as_of=2026-10-15&include_clashes=1$/);assert.match(s.text(),/Showing the timetable as of/);
    }finally{s.unmount();}
});


test('office selection buttons use the app filled active style and track real view and row controls',async()=>{
    const s=await screen({locations:true,parent:true});
    const selected=(labels:string[],chosen:string)=>{for(const label of labels){const button=s.button(label);const active=label===chosen;assert.equal(button.props['aria-pressed']??false,active);assert.equal(String(button.props.class).split(/\s+/).includes('active'),active);assert.equal(String(button.props.class).split(/\s+/).includes('btn-primary'),active);assert.equal(String(button.props.class).includes('btn-outline-primary'),!active);}};
    try{
        selected(['Class week','Schedules','Setup','Clashes'],'Schedules');
        const views=['Whole school','By teacher','By class','By location'];
        selected(views,'Whole school');selected(['Teachers','Classes'],'Teachers');
        click(s.button('Classes'));await flush();selected(['Teachers','Classes'],'Classes');
        for(const view of ['By teacher','By class','By location','Whole school']){click(s.button(view));await flush();selected(views,view);}
        for(const panel of ['Setup','Class week','Clashes','Schedules']){click(s.button(panel));await flush();selected(['Class week','Schedules','Setup','Clashes'],panel);}
    }finally{s.unmount();}
});

test('office date and each selection keep labels in one responsive field unit',async()=>{
    const s=await screen({locations:true});try{
        for(const [view,id] of [['Whole school','schedule-weekday'],['By teacher','schedule-teacher'],['By class','schedule-class'],['By location','schedule-location']]){
            click(s.button(view));await flush();
            for(const controlId of ['schedule-as-of',id]){const input=control(s,controlId);assert.match(String(input.parent.props.class),/schedule-field/);assert.ok(input.parent.children.some((n:any)=>n.tag==='label'&&n.props.for===controlId));}
        }
    }finally{s.unmount();}
    const source=readFileSync(new URL('../views/dashboard/OfficeSchedules.vue',import.meta.url),'utf8');
    assert.match(source,/@media \(max-width: 820px\)/);assert.match(source,/\.schedule-field \{[^}]*flex-direction: column[^}]*width: 100%/);
});

test('office whole school closure is one dated full sentence and unplaced teachers belong only to school or teacher prints',async()=>{
    const s=await screen({locations:true,closure:true,pageAt:(_url:string,data:any)=>({...data,as_of:'2026-11-22',as_of_reason:'Thanksgiving break',day:{...data.day,date:'2026-11-22',reason:'Thanksgiving break'}})});
    try{
        assert.equal((s.text().match(/School is closed/g)??[]).length,1);assert.match(s.text(),/School is closed on November 22, 2026: Thanksgiving break\./);
        for(const [view,visible] of [['By class',false],['By location',false],['By teacher',true],['Whole school',true]]){click(s.button(view as string));await flush();const list=s.all(n=>n.tag==='h4'&&n.textContent==='Teachers with nothing placed');assert.equal(list.length,visible?1:0);}
    }finally{s.unmount();}
});

test('office print keeps headings and names in cells and protects grid rows without protecting the entire document',()=>{
    const source=readFileSync(new URL('../views/dashboard/OfficeSchedules.vue',import.meta.url),'utf8');
    const print=source.slice(source.indexOf('@media print'));
    assert.match(print,/\.school-grid th, \.school-grid td \{[^}]*overflow-wrap: normal[^}]*word-break: normal/);
    assert.match(print,/\.school-grid tbody > tr \{ break-inside: avoid; \}/);
    assert.doesNotMatch(print,/(?:^|\n)\s*tr \{ break-inside: avoid/);
    assert.match(print,/font-size: 7pt/);
    assert.match(print,/\.school-grid \{[^}]*font-size: inherit !important/);
    assert.match(print,/padding: \.1rem \.15rem !important/);
    assert.match(print,/white-space: normal !important/);
    const meeting=readFileSync(new URL('../views/teacher/ScheduleMeeting.vue',import.meta.url),'utf8').split('@media print')[1];
    assert.match(meeting,/overflow-wrap: normal/);assert.match(meeting,/word-break: normal/);
});

test('office phone grid uses a narrow sticky name and 150px time columns inside its scroll strip',()=>{
    const source=readFileSync(new URL('../views/dashboard/OfficeSchedules.vue',import.meta.url),'utf8');
    const phone=source.slice(source.indexOf('@media (max-width: 820px)'),source.indexOf('@page'));
    assert.match(phone,/\.school-grid \{[^}]*table-layout: fixed/);
    assert.match(phone,/\.school-grid th, \.school-grid td \{[^}]*min-width: 150px[^}]*max-width: 150px/);
    assert.match(phone,/\.school-grid \.schedule-row-label \{[^}]*min-width: 96px[^}]*max-width: 96px/);
    assert.match(phone,/\.school-grid-scroll \{[^}]*width: calc\(100% \+ 1rem\)/);
});


test('office off-scale card renders once under Other times with its full clock and no empty clock heading',async()=>{
    const s=await screen({pageAt:(_url:string,data:any)=>({...data,columns:[...data.columns,{key:'other',starts_at:null,ends_at:null,names:['Other times']}],meetings:[slot(80,3,0,[8],{starts_at:'17:00',ends_at:'18:00',column_key:'other'})]})});
    try{
        assert.equal(s.all(n=>n.props['data-meeting-id']===80).length,1);
        const heading=s.all(n=>n.tag==='th'&&n.textContent==='Other times')[0];assert.ok(heading);assert.equal(heading.children.filter((n:any)=>String(n.props.class??'').includes('school-column-time')).length,0);
        assert.match(row(s,8).children.filter((n:any)=>n.tag==='td').at(-1).textContent,/5:00 - 6:00 PM/);
    }finally{s.unmount();}
});
