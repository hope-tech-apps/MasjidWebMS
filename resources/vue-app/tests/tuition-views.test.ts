import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, flush, click, submit, type, chooseOption } from './support/mountSfc.ts';
import { tuitionMoney, tuitionDecimal, tuitionMinor } from '../core/helpers/tuitionMoney.ts';
const require = createRequire(import.meta.url), vue = require('vue');
const amountHelpers = { tuitionMoney, tuitionDecimal, tuitionMinor };

test('a disabled school never loads or draws the office tuition screen', async () => {
    let reads=0;
    const s=await mountSfc('views/dashboard/TuitionLedgerView.vue', {}, { vue, '@/stores/masjidStore': { useMasjidStore:()=>({masjid:{id:1,capabilities:{}}}) }, '@/core/services/ApiService':{default:{get:async()=>{reads++;throw new Error();}}}, '@/core/helpers/tuitionMoney':amountHelpers });
    try { await flush();assert.equal(reads,0);assert.doesNotMatch(s.text(),/Family tuition ledger/); }
    finally{s.unmount();}
});

test('returning from Checkout only reads the ledger and waits for confirmation', async () => {
    const reads:string[]=[], writes:any[]=[];
    const account={id:4,currency:'usd',balance_minor:1000,minimum_payment_minor:0,next_due_on:null,default_from:'2026-08-24',school_today:'2026-10-14',card_available:true,children:[{id:7,name:'Practice Student'}]};
    const s=await mountSfc('views/family/FamilyTuition.vue',{}, { vue, 'vue-router':{useRoute:()=>({params:{masjidId:'1'},query:{checkout:'success'}})}, '@/core/services/FamilyApiService':{default:{get:async(url:string)=>{reads.push(url);return{data:{data:url.includes('?')?{statement:{opening_minor:0,closing_minor:1000,lines:[]}}:{accounts:[account]}}};},post:async(...args:any[])=>{writes.push(args);return{};}}}, './FamilyLangPicker.vue':{default:{render:()=>null}}, './familyI18n':{useFamilyLang:()=>({t:(key:string)=>key,dir:vue.ref('ltr'),lang:vue.ref('en'),locale:vue.ref('en')})}, '@/core/helpers/tuitionMoney':amountHelpers, '@/core/types/data/masjid-related/SchoolCalendar':{formatSchoolDay:(d:string)=>d} });
    try { await flush();assert.equal(writes.length,0);assert.equal(reads.length,2);assert.match(s.text(),/tuition_pending/);assert.match(s.text(),/\$10.00/); }
    finally{s.unmount();}
});

test('an office-only family account explains card availability and offers history', async () => {
    const account={id:4,currency:'usd',balance_minor:1000,minimum_payment_minor:0,next_due_on:null,default_from:'2026-08-24',school_today:'2026-10-14',card_available:false,children:[]};
    const s=await mountSfc('views/family/FamilyTuition.vue',{}, { vue, 'vue-router':{useRoute:()=>({params:{masjidId:'1'},query:{}})}, '@/core/services/FamilyApiService':{default:{get:async(url:string)=>({data:{data:url.includes('?')?{statement:{opening_minor:0,closing_minor:1000,lines:[]}}:{accounts:[account]}}})}}, './FamilyLangPicker.vue':{default:{render:()=>null}}, './familyI18n':{useFamilyLang:()=>({t:(key:string)=>key,dir:vue.ref('ltr'),lang:vue.ref('en'),locale:vue.ref('en')})}, '@/core/helpers/tuitionMoney':amountHelpers, '@/core/types/data/masjid-related/SchoolCalendar':{formatSchoolDay:(d:string)=>d} });
    try { await flush();assert.match(s.text(),/tuition_card_unavailable/);assert.match(s.text(),/tuition_download/);assert.doesNotMatch(s.text(),/tuition_pay_full/); }
    finally{s.unmount();}
});

test('family renders descriptions, friendly methods and coming charges, with school-year range and no private notes', async () => {
    const reads:string[]=[];
    const account={id:4,currency:'usd',balance_minor:63500,minimum_payment_minor:0,next_due_on:'2026-10-17',next_due_minor:2000,default_from:'2026-08-24',school_today:'2026-10-14',card_available:false,children:[]};
    const s=await mountSfc('views/family/FamilyTuition.vue',{}, { vue, 'vue-router':{useRoute:()=>({params:{masjidId:'1'},query:{}})}, '@/core/services/FamilyApiService':{default:{get:async(url:string)=>{reads.push(url);return{data:{data:url.includes('?')?{statement:{opening_minor:0,closing_minor:63500,lines:[{id:1,kind:'office_payment',description:'October payment',note:'PRIVATE-NOTE',reason:'PRIVATE-LEGACY-REASON',method:'cash_app',method_label:'Cash App',date:'2026-10-14',amount_minor:-2000}]}}:{accounts:[account]}}};}}}, './FamilyLangPicker.vue':{default:{render:()=>null}}, './familyI18n':{useFamilyLang:()=>({t:(key:string)=>({tuition_coming_up:'Coming up',tuition_on:'on',tuition_method_cash_app:'Cash App'}[key]??key),dir:vue.ref('ltr'),lang:vue.ref('en'),locale:vue.ref('en')})}, '@/core/helpers/tuitionMoney':amountHelpers, '@/core/types/data/masjid-related/SchoolCalendar':{formatSchoolDay:(d:string)=>d==='2026-10-17'?'Oct 17':d} });
    try {
        await flush(); assert.match(s.text(),/Coming up: \$20.00 on Oct 17/); assert.match(s.text(),/\$635.00/);
        assert.match(s.text(),/October payment/); assert.match(s.text(),/Cash App/); assert.doesNotMatch(s.text(),/PRIVATE-/);
        assert.match(reads[1],/from=2026-08-24&to=2026-10-14/);
    } finally{s.unmount();}
});

test('office posts public descriptions and private notes, acknowledges saves, labels methods and confirms counted recipients', async () => {
    const writes:any[]=[], confirmations:string[]=[];
    let confirmed=false;
    const oldWindow=(globalThis as any).window;
    (globalThis as any).window={confirm:(text:string)=>{confirmations.push(text);return confirmed;}};
    const account={id:4,currency:'usd',balance_minor:1000,overdue_minor:0,children:[{id:7,name:'Practice Student'}]};
    const detail={account:{minimum_payment_minor:0},balance_minor:1000,statement:{opening_minor:0,closing_minor:1000,lines:[{id:6,kind:'charge',description:'Tuition',note:'Private office detail',amount_minor:1000,date:'2026-10-14',recorded_by:'Practice Clerk',recorded_at:'Oct 14, 2026, 5:17 PM'}]},recipients:[{contact_id:1,name:'Guardian One',eligible:true,address:'one@example.invalid'},{contact_id:2,name:'Guardian Two',eligible:true,address:'two@example.invalid'},{contact_id:3,name:'Blocked',eligible:false,address:'three@example.invalid'}],mails:[{id:1,guardian_contact_id:1,kind:'statement',status:'sent',created_at:'Oct 14, 2026, 5:17 PM',sent_at:'Oct 14, 2026, 5:18 PM'}]};
    const options={default_from:'2026-08-24',school_today:'2026-10-14',currency:'usd',billing_settings:{currency:'usd',minimum_payment_minor:0},contacts:[{id:7,name:'Practice Student'},{id:1,name:'Guardian One'}],participants:[{contact_id:7}],guardians:[{contact_id:1,guardian_of_contact_id:7}],methods:['cash','cash_app','zelle','check','bank_transfer'],method_labels:{cash:'Cash',cash_app:'Cash App',zelle:'Zelle',check:'Check',bank_transfer:'Bank transfer'},offerings:[],years:[],groups:[],grades:[]};
    const api={get:async(url:string)=>({data:{data:url.includes('/options')?options:url.includes('/schedules')?[]:url.includes('/4?')?detail:{accounts:[account],card_available:false}}}),post:async(url:string,body:any)=>{writes.push({url,body:{...body}});return{data:{data:{queued:2}}};}};
    const s=await mountSfc('views/dashboard/TuitionLedgerView.vue',{}, {vue,'@/stores/masjidStore':{useMasjidStore:()=>({masjid:{id:1,capabilities:{tuition_ledger:true}}})},'@/core/services/ApiService':{default:api},'@/core/helpers/tuitionMoney':amountHelpers});
    try {
        await flush();
        const formWith=(text:string)=>s.all(n=>n.tag==='form'&&n.textContent.includes(text))[0];
        const inputIn=(form:any,label:string)=>form.children.find((n:any)=>n.tag==='label'&&n.textContent.includes(label)).children.find((n:any)=>n.tag==='input');
        const opening=formWith('Open account');
        const selects=s.all(n=>n.tag==='select'&&n.props.multiple!==undefined);
        (selects[0] as any).multiple=true;(selects[1] as any).multiple=true;chooseOption(selects[0],7);await flush();chooseOption(selects[1],1);submit(opening);await flush();
        assert.match(s.text(),/Saved/);assert.deepEqual(writes[0].body,{children:[7],guardians:[1]});
        click(s.button('Open ledger'));await flush();
        assert.match(s.text(),/Oct 14, 2026, 5:17 PM/);assert.match(s.text(),/Oct 14, 2026, 5:18 PM/);
        assert.match(s.text(),/Families see this/);assert.match(s.text(),/Staff only/);
        const entry=formWith('Add entry');
        chooseOption(s.all(n=>n.tag==='select'&&n.children.some(c=>c.props.value==='office_payment'))[0],'office_payment');await flush();
        for(const label of ['Cash App','Zelle','Check','Bank transfer'])assert.match(s.text(),new RegExp(label));
        type(inputIn(entry,'Amount'), '20.00');type(inputIn(entry,'Description'),'October payment');type(inputIn(entry,'Note'),'Private saved note');
        submit(entry);await flush();
        assert.equal(writes[1].body.description,'October payment');assert.equal(writes[1].body.note,'Private saved note');assert.equal(writes[1].body.amount_minor,2000);assert.equal(writes[1].body.effective_on,'2026-10-14');assert.match(s.text(),/Saved/);
        click(s.button('Void'));await flush();const voidForm=formWith('Add void entry');
        type(inputIn(voidForm,'Note'),'Private correction');type(inputIn(voidForm,'Description'),'Payment correction');submit(voidForm);await flush();
        assert.deepEqual(writes[2].body,{note:'Private correction',description:'Payment correction'});assert.match(s.text(),/Saved/);
        click(s.button('Email statement'));await flush();assert.equal(writes.length,3);assert.equal(confirmations[0],'Email statement to 2 recipients?');
        confirmed=true;click(s.button('Email statement'));await flush();assert.equal(writes.length,4);assert.deepEqual(writes[3].body,{from:'2026-08-24',to:'2026-10-14'});assert.match(s.text(),/2 statement email\(s\) queued/);
    } finally{s.unmount();(globalThis as any).window=oldWindow;}
});
