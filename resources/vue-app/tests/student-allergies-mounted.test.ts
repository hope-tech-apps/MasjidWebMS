import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { mountSfc, click, flush, pressKey, type } from './support/mountSfc.ts';

async function mount(...args: Parameters<typeof mountSfc>) {
    const s = await mountSfc(...args);
    return { ...s, find: (predicate: any) => s.all(predicate)[0] };
}

const note = { allergies: 'Peanuts: use care', source: 'form', changed_at: '2026-10-11T16:00:00Z' };

test('Allergy badge is named, reachable by keyboard and shows the words on focus/tap', async () => {
    const s = await mount('components/classes/AllergyBadge.vue', { note }, { vue });
    const badge = s.find(n => n.props?.role === 'button')!;
    assert.ok(badge); assert.equal(String(badge.props.tabindex), '0');
    assert.match(badge.props['aria-label'], /Allergy.*Peanuts: use care/);
    assert.equal(badge.props.title, 'Peanuts: use care');
    const handlers = Array.isArray(badge.props.onKeydown) ? badge.props.onKeydown : [badge.props.onKeydown];
    handlers.forEach(h => h({ key: 'Enter', preventDefault() {}, stopPropagation() {} }));
    await flush();
    assert.match(s.text(), /Peanuts: use care/);
    assert.equal(badge.props['aria-expanded'], true);
    s.unmount();
});

for (const allergies of [null, '', 'none', '(NONE)!', 'no allergies', 'N/A']) {
    test(`Allergy badge absent for ${String(allergies)}`, async () => {
        const s = await mount('components/classes/AllergyBadge.vue', { note: { ...note, allergies: null } }, { vue });
        assert.equal(s.find(n => n.props?.role === 'button'), undefined);
        s.unmount();
    });
}

test('Student allergy field loads source, edits and clears through office route', async () => {
    const calls: any[] = [];
    const s = await mount('views/dashboard/groups/StudentAllergyField.vue', { masjidId: 1, groupId: 2, membershipId: 3 }, {
        vue,
        '@/core/services/ApiService': { default: { get: async () => ({ data: { data: note } }), put: async (...args: any[]) => { calls.push(args); return { data: { data: { ...note, allergies: null, source: 'office' } } }; } } },
    });
    await flush();
    assert.match(s.text(), /Allergies or special needs/);
    assert.match(s.text(), /from the enrolment form/);
    assert.match(s.text(), /2026-10-11/);
    const area = s.find(n => n.tag === 'textarea')!;
    assert.equal(area.props.maxlength, undefined);
    await type(area, '');
    click(s.find(n => n.tag === 'button' && n.textContent.includes('Save'))!); await flush();
    assert.equal(calls[0][1].allergies, '');
    assert.match(s.text(), /entered by the office/);
    s.unmount();
});

test('Copy allergies previews counts then copies and lists unresolved children', async () => {
    const calls: any[]=[];
    const data={counts:{children_read:3,with_allergy:2,matched:1,already_set:0,unmatched:1,ambiguous:0,written:1,office_wins:0},children:[{response_id:4,entry_index:1,name:'Avery Willow',outcome:'unmatched'}]};
    const s=await mount('views/dashboard/groups/CopyStudentAllergies.vue',{masjidId:1},{vue,
        '@/core/services/ApiService':{default:{get:async()=>({data:{data:[{id:9,name:'Practice Enrollment'}]}}),post:async(...args:any[])=>{calls.push(args);return {data:{data}};}}},
    });
    await flush();
    assert.equal(s.find(n=>n.tag==='button' && n.textContent.includes('Copy now')),undefined);
    // A school chooses a form explicitly; the preview must precede the write.
    const select=s.find(n=>n.tag==='select')!;
    const { select: choose }=await import('./support/mountSfc.ts');
    choose(select,'9'); await flush();
    click(s.find(n=>n.tag==='button' && n.textContent.includes('Preview'))!); await flush();
    assert.equal(calls[0][1].dry_run,true);
    assert.match(s.text(),/Children read: 3/);
    assert.match(s.text(),/With an allergy: 2/);
    assert.match(s.text(),/Avery Willow.*unmatched/s);
    click(s.find(n=>n.tag==='button' && n.textContent.includes('Copy now'))!); await flush();
    assert.equal(calls[1][1].dry_run,false);
    assert.match(s.text(),/Copied: 1/);
    s.unmount();
});

// TeacherClass and the office roster are large screens. Mount their exact name-row
// templates, extracted from the production SFC, with the real compiled badge.
import { readFileSync } from 'node:fs';
import { compileSfc } from './support/mountSfc.ts';
import { mountTemplate } from './support/mountTemplate.ts';
const source = (file: string) => readFileSync(new URL('../' + file, import.meta.url), 'utf8');
function elementFrom(text: string, marker: string, tag: string) {
    const start=text.indexOf(marker);
    assert.ok(start>=0,marker);
    const tokens=new RegExp(String.raw`<\/?${tag}(?:\s[^>]*|)>`,'g');
    tokens.lastIndex=start;
    let depth=0, match: RegExpExecArray|null;
    while ((match=tokens.exec(text))) {
        depth+=match[0].startsWith('</')?-1:1;
        if (!depth) return text.slice(start,tokens.lastIndex);
    }
    throw new Error(`Unclosed ${marker}`);
}
const officeSource=source('views/dashboard/groups/GroupRosterTab.vue');
const teacherSource=source('views/teacher/TeacherClass.vue');
const fragments={
    office:elementFrom(officeSource,'<span class="student-name-with-allergy">','span'),
    teacher:elementFrom(teacherSource,'<section v-if="activeTab === \'roster\'">','section'),
    register:elementFrom(teacherSource,'<div class="flex-grow-1" :class="{ \'tc-allergy-name\': registerAllergies[s.membership_id] !== undefined }">','div'),
};
for (const surface of ['office','teacher','register'] as const) {
    for (const allergies of ['Peanuts: use care','none',null]) {
        test(`${surface} name row mounts the real badge: ${String(allergies)}`,async()=>{
            const AllergyBadge=await compileSfc('components/classes/AllergyBadge.vue',{vue});
            const record={...note,allergies: allergies==='none'?null:allergies};
            const row={id:3,membership_id:3,role:'member',contact:{first_name:'Robin',last_name:'Willow'},student_allergy:record};
            const ctx={membership:row,s:row,students:[row],allergiesEnabled:true,activeTab:'roster',sheetFor:null,
                registerAllergies:{3:record},fullName:(c:any)=>`${c.first_name} ${c.last_name}`,name:(c:any)=>`${c.first_name} ${c.last_name}`,
                isStudentRow:()=>true,openStudent:()=>{},ageLabel:()=>'',
            };
            const s=mountTemplate(fragments[surface],ctx,{AllergyBadge,PersonAvatar:vue.defineComponent({render:()=>null})});
            assert.match(s.text(),/Robin Willow/);
            const badge=s.all(n=>n.props.role==='button')[0];
            if (allergies==='Peanuts: use care') {
                assert.ok(badge); assert.match(badge.props['aria-label'],/Peanuts: use care/);
                const handlers=Array.isArray(badge.props.onKeydown)?badge.props.onKeydown:[badge.props.onKeydown];
                handlers.forEach(h=>h({key:'Enter',preventDefault(){},stopPropagation(){}})); await flush();
                assert.match(s.text(),/Peanuts: use care/);
                click(badge); await flush(); assert.match(s.text(),/Peanuts: use care/);
            } else assert.equal(badge,undefined);
            s.unmount();
        });
    }
}

test('office panel replaces the footer only while enabled and copy requires a preview',()=>{
    const panel=source('views/dashboard/groups/StudentDetailsPanel.vue');
    assert.match(panel,/<slot v-if="allergiesEnabled" name="allergies"/);
    assert.match(panel,/<p v-else[^>]*>\s*Allergies, medical notes/);
    assert.match(officeSource,/<StudentAllergyField v-if="allergiesEnabled"/);
    assert.match(officeSource,/<CopyStudentAllergies v-for="copyKey in \(allergiesEnabled \?/);
    assert.match(teacherSource,/registerAllergies\.value = Object\.fromEntries\(\(data\.students/);
    assert.match(officeSource,/max-inline-size: 11rem/);
    assert.match(officeSource,/min-inline-size: 0; white-space: normal; overflow-wrap: anywhere; text-align: start/);
});

for (const surface of ['office','teacher','register'] as const) {
    test(`${surface} shows an unreadable allergy warning`, async () => {
        const AllergyBadge=await compileSfc('components/classes/AllergyBadge.vue',{vue});
        const record={unavailable:true,allergies:null};
        const row={id:3,membership_id:3,role:'member',contact:{first_name:'Robin',last_name:'Willow'},student_allergy:record};
        const ctx={membership:row,s:row,students:[row],activeTab:'roster',sheetFor:null,registerAllergies:{3:record},fullName:()=> 'Robin Willow',name:()=> 'Robin Willow',isStudentRow:()=>true,openStudent:()=>{},ageLabel:()=>''};
        const s=mountTemplate(fragments[surface],ctx,{AllergyBadge,PersonAvatar:vue.defineComponent({render:()=>null})});
        assert.match(s.text(),/Allergy note unavailable: ask the office/);
        s.unmount();
    });
}
test('register shows the response note with no roster allergy key', async () => {
    const AllergyBadge=await compileSfc('components/classes/AllergyBadge.vue',{vue});
    const s=mountTemplate(fragments.register,{s:{membership_id:3,contact:{}},registerAllergies:{3:note},name:()=> 'Robin Willow'},{AllergyBadge});
    assert.ok(s.all(n=>n.props.role==='button')[0]);
    s.unmount();
});
test('office retains overlong paste and refuses save with its live count', async () => {
    let writes=0;
    const s=await mount('views/dashboard/groups/StudentAllergyField.vue',{masjidId:1,groupId:2,membershipId:3},{vue,'@/core/services/ApiService':{default:{get:async()=>({data:{data:note}}),put:async()=>{writes++;}}}});
    await flush();
    const area=s.find(n=>n.tag==='textarea')!;
    assert.equal(area.props.maxlength,undefined);
    const text='x'.repeat(500)+' Peanuts';
    await type(area,text); await flush();
    assert.match(s.text(),/508 \/ 500/);
    click(s.find(n=>n.tag==='button' && n.textContent.includes('Save'))!); await flush();
    assert.equal(writes,0);
    assert.match(s.text(),/508 characters.*500/s);
    assert.equal(area.value,text);
    s.unmount();
});
for (const allergies of ['🥜','🥜 🥛','none🥜']) test(`badge preserves server answer ${allergies}`,async()=>{
    const s=await mount('components/classes/AllergyBadge.vue',{note:{allergies}},{vue});
    assert.ok(s.find(n=>n.props?.role==='button')); s.unmount();
});

test('merge dialog shows the allergy reconciliation refusal in words', async () => {
    const { modulesFor, page } = await import('./support/batch3Modules.ts');
    const doc = (globalThis as any).document;
    doc.addEventListener ??= () => {}; doc.removeEventListener ??= () => {};
    doc.body = { style: {} };
    (globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
    const alerts: any[] = [];
    const refusal = 'Both records have allergy notes too long to combine. Shorten one, then merge.';
    const source = { id: 3, first_name: 'Source record', last_name: '', email: 'source@example.invalid', cards: [], donations: [] };
    const target = { id: 4, first_name: 'Kept record', last_name: '', email: 'kept@example.invalid' };
    const store = { contactsPaginated: { data: [source], current_page: 1, total: 1, per_page: 25 }, tags: [], fetchContacts: async () => {}, fetchTags: async () => {}, fetchContact: async () => source };
    const screen = await mountSfc('views/dashboard/ContactsView.vue', {}, await modulesFor('views/dashboard/ContactsView.vue', {
        '@/components/PageDataContainer.vue': { default: page },
        '@/stores/masjid/contactsStore': { useContactsStore: () => store },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 }, term: () => 'Members' }) },
        'vue-router': { useRoute: () => ({ params: {}, query: { contact: '3' } }), useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) },
        '@/core/services/ApiService': { default: {
            get: async (url: string) => ({ data: { data: url.includes('search=') ? { data: [target] } : {} } }),
            post: async () => { throw { response: { data: { status: 'failed', data: { allergies: [refusal] } } } }; },
        } },
        sweetalert2: { default: { fire: async (options: any) => { alerts.push(options); return { isConfirmed: alerts.length === 1 }; } } },
    }));
    try {
        await flush(); click(screen.button('Merge into another member')); await flush();
        type(screen.all(n => n.props.placeholder === 'Name or email…')[0], 'Kept');
        await new Promise(r => setTimeout(r, 320)); await flush(); click(screen.button('Kept record')); await flush();
        click(screen.all(n => n.tag === 'button' && n.textContent === 'Merge')[0]); await flush(12);
        assert.equal(alerts.at(-1).text, refusal);
    } finally { screen.unmount(); }
});
