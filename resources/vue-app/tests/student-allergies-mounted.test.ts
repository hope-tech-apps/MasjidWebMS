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
        const s = await mount('components/classes/AllergyBadge.vue', { note: { ...note, allergies } }, { vue });
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
    assert.equal(area.props.maxlength, '500');
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
    register:elementFrom(teacherSource,'<div class="flex-grow-1" :class="{ \'tc-allergy-name\': s.student_allergy !== undefined }">','div'),
};
for (const surface of ['office','teacher','register'] as const) {
    for (const allergies of ['Peanuts: use care','none',null]) {
        test(`${surface} name row mounts the real badge: ${String(allergies)}`,async()=>{
            const AllergyBadge=await compileSfc('components/classes/AllergyBadge.vue',{vue});
            const record={...note,allergies};
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
