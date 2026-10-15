import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, flush } from './support/mountSfc.ts';
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
    const account={id:4,currency:'usd',balance_minor:1000,minimum_payment_minor:0,next_due_on:null,card_available:true,children:[{id:7,name:'Practice Student'}]};
    const s=await mountSfc('views/family/FamilyTuition.vue',{}, { vue, 'vue-router':{useRoute:()=>({params:{masjidId:'1'},query:{checkout:'success'}})}, '@/core/services/FamilyApiService':{default:{get:async(url:string)=>{reads.push(url);return{data:{data:url.includes('?')?{statement:{opening_minor:0,closing_minor:1000,lines:[]}}:{accounts:[account]}}};},post:async(...args:any[])=>{writes.push(args);return{};}}}, './FamilyLangPicker.vue':{default:{render:()=>null}}, './familyI18n':{useFamilyLang:()=>({t:(key:string)=>key,dir:vue.ref('ltr'),lang:vue.ref('en'),locale:vue.ref('en')})}, '@/core/helpers/tuitionMoney':amountHelpers, '@/core/types/data/masjid-related/SchoolCalendar':{formatSchoolDay:(d:string)=>d} });
    try { await flush();assert.equal(writes.length,0);assert.equal(reads.length,2);assert.match(s.text(),/tuition_pending/);assert.match(s.text(),/USD 10.00/); }
    finally{s.unmount();}
});

test('an office-only family account explains card availability and offers history', async () => {
    const account={id:4,currency:'usd',balance_minor:1000,minimum_payment_minor:0,next_due_on:null,card_available:false,children:[]};
    const s=await mountSfc('views/family/FamilyTuition.vue',{}, { vue, 'vue-router':{useRoute:()=>({params:{masjidId:'1'},query:{}})}, '@/core/services/FamilyApiService':{default:{get:async(url:string)=>({data:{data:url.includes('?')?{statement:{opening_minor:0,closing_minor:1000,lines:[]}}:{accounts:[account]}}})}}, './FamilyLangPicker.vue':{default:{render:()=>null}}, './familyI18n':{useFamilyLang:()=>({t:(key:string)=>key,dir:vue.ref('ltr'),lang:vue.ref('en'),locale:vue.ref('en')})}, '@/core/helpers/tuitionMoney':amountHelpers, '@/core/types/data/masjid-related/SchoolCalendar':{formatSchoolDay:(d:string)=>d} });
    try { await flush();assert.match(s.text(),/tuition_card_unavailable/);assert.match(s.text(),/tuition_download/);assert.doesNotMatch(s.text(),/tuition_pay_full/); }
    finally{s.unmount();}
});
