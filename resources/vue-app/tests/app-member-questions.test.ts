import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, click, check, type, chooseOption, flush, loadTs } from './support/mountSfc.ts';
const require=createRequire(import.meta.url); const vue=require('vue');
const lists={ field:[{id:8,label:'Healthcare',active:true,is_other:false}], settings:{field:{active:true,prompt:'Your field?'},occupation:{active:true,prompt:'Your profession?'},help:{active:true,prompt:'Your skills?'}}, questions:[{kind:'field',prompt:'Your field?',multiple:false,options:[{id:8,label:'Healthcare'}]},{kind:'occupation',prompt:'Your profession?',multiple:false,options:[{id:1,label:'Education'}]},{kind:'help',prompt:'Your skills?',multiple:true,options:[{id:3,label:'Events'}]}], occupation:[{id:1,label:'Education',active:true,is_other:false},{id:2,label:'Other',active:true,is_other:true}], help:[{id:3,label:'Events',active:true,is_other:false}], interests:[{id:7,title:'Practice service'}] };
test('platform option editor adds renames reorders deactivates and saves all three complete lists and settings with JSON',async()=>{
    const writes:any[]=[];
    const s=await mountSfc('components/super/MemberQuestionOptions.vue',{orgId:1},{vue,'@/core/services/ApiService':{default:{get:async()=>({data:{data:lists}}),put:async(url:string,body:any,_:any,headers:any)=>{writes.push({url,body,headers});return{data:{data:body}};}}}});
    try{await flush(); type(s.all(n=>n.props['aria-label']==='Profession option 1')[0],'Learning'); await flush(); click(s.all(n=>n.props['aria-label']==='Move Education down'||n.props['aria-label']==='Move Learning down')[0]); await flush();
        click(s.all(n=>n.props['aria-label']==='Deactivate Learning')[0]); await flush();
        type(s.all(n=>n.props.id==='new-occupation')[0],'Creative work'); await flush(); click(s.button('Add occupation option')); await flush();
        type(s.all(n=>n.props.id==='prompt-occupation')[0],'Your role?');await flush();check(s.all(n=>n.props.id==='asked-help')[0],false);await flush();click(s.button('Save option lists'));await flush();assert.equal(writes.length,1);assert.equal(writes[0].body.settings.occupation.prompt,'Your role?');assert.equal(writes[0].body.settings.help.active,false);assert.equal(writes[0].body.occupation[1].label,'Learning');assert.equal(writes[0].body.occupation[1].active,false);assert.equal(writes[0].body.occupation[2].label,'Creative work');assert.equal(writes[0].headers['Content-Type'],'application/json');
    }finally{s.unmount();}
});
test('office filters use server options and emit every selected parameter, and refused read shows nothing',async()=>{
    const events:any[]=[];
    const s=await mountSfc('components/contacts/MemberQuestionFilters.vue',{orgId:1,onChange:(x:any)=>events.push(x)},{vue,'@/core/services/ApiService':{default:{get:async()=>({data:{data:lists}})}}});
    try{await flush(); const select=s.all(n=>n.tag==='select'&&n.props['aria-label']==='Profession')[0]; chooseOption(select,'1');await flush();assert.equal(events.at(-1).occupation_id,'1');assert.match(s.text(),/How they could help.*Interested in/s);}finally{s.unmount();}
    const denied=await mountSfc('components/contacts/MemberQuestionFilters.vue',{orgId:1},{vue,'@/core/services/ApiService':{default:{get:async()=>{throw{response:{status:403}};}}}});try{await flush();assert.equal(denied.all(n=>n.tag==='select').length,0);}finally{denied.unmount();}
});
test('contact From the app is a separate read and never offers a member answer write',async()=>{
    const requests:string[]=[];
    const s=await mountSfc('components/contacts/MemberQuestionsBlock.vue',{orgId:1,contactId:9},{vue,'@/core/services/ApiService':{default:{get:async(url:string)=>{requests.push(url);return{data:{data:{questions:[{kind:'field',prompt:'Your field?',answer:[{id:8,label:'Healthcare'}],text:null},{kind:'occupation',prompt:'Your profession?',answer:[{id:1,label:'Education'}],text:null},{kind:'help',prompt:'Your skills?',answer:[{id:3,label:'Events'}],text:'Can organise'}],interests:[{id:7,title:'Practice service'}]}}};}}}});
    try{await flush();assert.deepEqual(requests,['/api/admin/masjids/1/contacts/9/from-app']);assert.match(s.text(),/From the app.*Your field.*Healthcare.*Your profession.*Education.*Your skills.*Events.*Can organise.*Practice service/s);assert.equal(s.all(n=>n.tag==='input'||n.tag==='select'||n.tag==='textarea').length,0);}finally{s.unmount();}
});
test('contacts directory serialiser includes four optional filters and its existing URL is identical when absent',async()=>{
    const m=await loadTs('views/dashboard/contacts/contactTags.ts',{});
    assert.equal(m.contactsListUrl(1,1),'/api/admin/masjids/1/contacts?page=1');
    assert.equal(m.contactsListUrl(1,1,'','',null,{field_id:8,occupation_id:2,help_id:3,interest_id:7}),'/api/admin/masjids/1/contacts?page=1&field_id=8&occupation_id=2&help_id=3&interest_id=7');
});
test('Volunteer editor defaults title, validates the test link, saves and clears with JSON',async()=>{
    const writes:any[]=[]; const events:any[]=[];
    const originalWindow=(globalThis as any).window;(globalThis as any).window={addEventListener(){},removeEventListener(){}};
    const s=await mountSfc('components/contacts/AppVolunteerField.vue',{orgId:1,onUpdated:(x:any)=>events.push(x)},{vue,'@/core/tenancy/unsavedChanges':{registerUnsavedChanges:()=>()=>{}},sweetalert2:{default:{fire:async()=>({isConfirmed:true})}},'@/core/services/ApiService':{default:{get:async()=>({data:{data:{title:'Volunteer',url:null}}}),put:async(url:string,body:any,_:any,headers:any)=>{writes.push({url,body,headers});return{data:{data:body}};}}}});
    try{await flush();type(s.all(n=>n.props.id==='volunteer-url')[0],'https://example.invalid/form');await flush();const a=s.all(n=>n.tag==='a')[0];assert.equal(a.props.href,'https://example.invalid/form');assert.equal(a.props.target,'_blank');click(s.button('Save Volunteer link'));await flush();assert.equal(writes[0].body.url,'https://example.invalid/form');assert.equal(writes[0].headers['Content-Type'],'application/json');assert.equal(events.length,1);type(s.all(n=>n.props.id==='volunteer-url')[0],'');await flush();click(s.button('Save Volunteer link'));await flush();assert.equal(writes[1].body.url,null);}finally{s.unmount();(globalThis as any).window=originalWindow;}
});
test('Volunteer editor says why an address that is not https cannot be saved, and Saved never stands beside unsaved edits',async()=>{
    const writes:any[]=[]; const events:any[]=[];
    const originalWindow=(globalThis as any).window;(globalThis as any).window={addEventListener(){},removeEventListener(){}};
    const s=await mountSfc('components/contacts/AppVolunteerField.vue',{orgId:1,onUpdated:(x:any)=>events.push(x)},{vue,'@/core/tenancy/unsavedChanges':{registerUnsavedChanges:()=>()=>{}},sweetalert2:{default:{fire:async()=>({isConfirmed:true})}},'@/core/services/ApiService':{default:{get:async()=>({data:{data:{title:'Volunteer',url:null}}}),put:async(url:string,body:any,_:any,headers:any)=>{writes.push({url,body,headers});return{data:{data:body}};}}}});
    try{await flush();const box=()=>s.all(n=>n.props.id==='volunteer-url')[0];type(box(),'http://example.invalid/volunteer');await flush();assert.match(s.text(),/Use a full address that starts with https:\/\//);assert.equal(writes.length,0);type(box(),'https://example.invalid/volunteer');await flush();assert.doesNotMatch(s.text(),/Use a full address/);click(s.button('Save Volunteer link'));await flush();assert.match(s.text(),/Saved\./);type(box(),'https://example.invalid/other');await flush();assert.doesNotMatch(s.text(),/Saved\./,'an edit after a save clears Saved');}finally{s.unmount();(globalThis as any).window=originalWindow;}
});

test('office filters show only asked kinds and count tables export without member names',async()=>{
    const s=await mountSfc('components/contacts/MemberQuestionFilters.vue',{orgId:1},{vue,'@/core/services/ApiService':{default:{get:async()=>({data:{data:{...lists,questions:lists.questions.slice(0,2)}}})}}});
    try{await flush();assert.deepEqual(s.all(n=>n.tag==='select').map(n=>n.props['aria-label']),['Field','Profession','Interested in']);}finally{s.unmount();}
    const writes:string[]=[];const downloads:any[]=[];const links:any[]=[];
    const oldDocument=(globalThis as any).document;const oldCreate=URL.createObjectURL;const oldRevoke=URL.revokeObjectURL;
    (globalThis as any).document={createElement:()=>{const link:any={click(){links.push({href:this.href,download:this.download});}};return link;}};URL.createObjectURL=()=> 'blob:counts';URL.revokeObjectURL=()=>{};
    const counts=await mountSfc('components/contacts/MemberQuestionCounts.vue',{orgId:1},{vue,'@/core/services/ApiService':{default:{VueApp:{axios:{get:async(url:string,config:any)=>{downloads.push({url,config});return{data:new Blob(['Option,Members'])};}}},get:async(url:string)=>{writes.push(url);return{data:{data:{field:[{id:8,label:'Healthcare',count:50}],occupation:[{id:1,label:'Physician',count:50}]}}};}}}});
    try{await flush();assert.match(counts.text(),/Members by field.*Healthcare.*50.*Members by profession.*Physician.*50/s);assert.equal(counts.all(n=>n.tag==='table').length,2);assert.equal(counts.all(n=>n.tag==='button'&&n.textContent==='Download CSV').length,1);assert.deepEqual(writes,['/api/admin/masjids/1/contacts/from-app-counts']);click(counts.button('Download CSV'));await flush();assert.deepEqual(downloads,[{url:'/api/admin/masjids/1/contacts/from-app-counts/csv',config:{responseType:'blob'}}]);assert.deepEqual(links,[{href:'blob:counts',download:'member-question-counts.csv'}]);}finally{counts.unmount();(globalThis as any).document=oldDocument;URL.createObjectURL=oldCreate;URL.revokeObjectURL=oldRevoke;}
});
