/**
 * Prices by answer in the form builder (settings.fee.byChoice): an office changes a price,
 * rewords, adds, removes or reorders a choice, and sets the pricing up on a new form, with
 * no import (2026-10-08). The REAL question editor is mounted inside the builder, so the
 * choices are edited through its own controls. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reactive } from 'vue';
import { type Node, check, choose, click, compileSfc, flush, mountSfc, select, type } from './support/mountSfc.ts';
import * as formTypes from '../core/types/data/masjid-related/Form.ts';
import * as feePricing from '../components/forms/formFeePricing.ts';

const stub = { template: '<div />' };

/** The first element with this tag under `node`. */
const under = (node: Node, tag: string): Node | null => {
    for (const child of node.children) {
        if (child.tag === tag) return child;
        const found = under(child, tag);
        if (found) return found;
    }
    return null;
};

/** A registration priced by one dropdown, as form:import leaves it. */
const afterschool = (overrides: Record<string, any> = {}) => ({
    id: 7, name: 'Afterschool registration', slug: 'afterschool-registration', is_active: true, capacity: null,
    schema: { sections: [{ id: 'payment', title: 'Payment', fields: [{
        name: 'paymentChoice', label: 'Children and payment', type: 'select', required: true,
        options: [
            { value: 'c1Month', label: '1 child: first month' },
            { value: 'c1Full', label: '1 child: full payment' },
            { value: 'c2Full', label: '2 children: full payment' },
        ],
    }] }] },
    settings: {
        fee: { currency: 'USD', byChoice: { field: 'paymentChoice', prices: [
            { value: 'c1Month', amount: 150, perQuantity: false, reservesDate: false },
            { value: 'c1Full', amount: 400, perQuantity: false, reservesDate: false },
            { value: 'c2Full', amount: 720, perQuantity: false, reservesDate: false },
        ] } },
        payment: { online: true },
    },
    ...overrides,
});

/** Choose a question in the picker by its wording, as a tap on that line does. */
const pick = (picker: Node, wording: string) => {
    const option = picker.children.find(child => child.tag === 'option' && child.textContent.trim() === wording);
    assert.ok(option, `the picker lists "${wording}"`);
    picker.value = option.props.value;
    select(picker, option.props.value);
};

/** The picker's lines, as read. */
const pickerLines = (picker: Node) => picker.children.filter(child => child.tag === 'option').map(option => option.textContent.trim());

/** A giving form as form:import leaves it: one level charged per person, two that reserve a date. */
const iftar = () => afterschool({
    schema: { sections: [{ id: 'giving', title: 'Giving', fields: [
        { name: 'sponsorship', label: 'Sponsorship', type: 'radio', required: true,
            options: [{ value: 'individual', label: 'Individual iftar' }, { value: 'quarter', label: 'Quarter of an evening' }, { value: 'half', label: 'Half of an evening' }] },
        { name: 'people', label: 'Number of people', type: 'number', required: true, min: 1 },
    ] }] },
    settings: {
        fee: { currency: 'USD', perQuantityOf: 'people', byChoice: { field: 'sponsorship', prices: [
            { value: 'individual', amount: 18, perQuantity: true },
            { value: 'quarter', amount: 450, reservesDate: true },
            { value: 'half', amount: 950, reservesDate: true },
        ] } },
        reservation: { dates: ['2027-02-10', '2027-02-11'] },
    },
});

async function builder(stored: any, refusal: Record<string, string[]> | null = null, optionsSources: any[] = []) {
    const writes: any[] = [];
    const store = reactive({ fieldTypes: formTypes.FORM_FIELD_TYPES, optionsSources,
        fetchForm: async () => stored, fetchFieldTypes: async () => [], fetchFormOptions: async () => [],
        updateForm: async (_id: number, body: any) => {
            writes.push(JSON.parse(JSON.stringify(body)));
            if (refusal) throw { refused: refusal };
            return body;
        },
        createForm: async (body: any) => { writes.push(JSON.parse(JSON.stringify(body))); return body; },
    });
    const editor = await compileSfc('components/forms/FormFieldEditor.vue', { '@/core/types/data/masjid-related/Form': formTypes });
    const screen = await mountSfc('components/forms/FormBuilder.vue', { formId: stored ? 7 : null }, {
        '@/core/types/data/masjid-related/Form': formTypes,
        '@/components/forms/formFeePricing': feePricing,
        '@/components/forms/FormFieldEditor.vue': { default: editor },
        '@/components/forms/FormStaffCodesModal.vue': { default: stub },
        '@/stores/masjid/formsStore': { useFormsStore: () => store },
        '@/stores/masjid/connectStore': { useConnectStore: () => ({}) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: {}, orgType: 'masjid', term: (key: string) => key }) },
        '@/core/access/orgAccess': { connectPlace: () => null, connectPlaceTitle: () => null },
        '@/core/types/data/Capability': { CAPABILITY_LABELS: { crm: 'CRM' } },
        '@/core/types/data/masjid-related/StripeConnect': { formsCardProblemIsLink: () => false, formsCardProblemText: () => '' },
        '@/core/helpers/serverMessage': { serverFieldErrors: (error: any) => error?.refused ?? {}, serverMessage: () => '' },
        'vue-router': { useRouter: () => ({ resolve: () => ({ href: '/' }) }) },
        sweetalert2: { default: { fire: async () => ({}) } },
    });
    await flush();

    const byId = (id: string) => screen.all(n => n.props.id === id)[0];

    return {
        screen, writes, byId,
        price: (index: number) => byId(`formFeeChoicePrice_${index}`),
        picker: () => byId('formFeeChoiceField'),
        /** The Charged column as read: one line of words per row. */
        charged: (): string[] => {
            const table = screen.all(n => n.props['data-test'] === 'choice-prices')[0];
            const out: string[] = [];
            const walk = (node: Node) => {
                const cells = node.tag === 'tr' ? node.children.filter(c => c.tag === 'td') : [];
                if (cells.length) out.push(cells[2].textContent.replace(/\s+/g, ' ').trim());
                node.children.forEach(walk);
            };
            if (table) walk(table);
            return out;
        },
        /** The price table as read off the screen: [choice, price in the box] per row. */
        rows: (): [string, string][] => {
            const table = screen.all(n => n.props['data-test'] === 'choice-prices')[0];
            const out: [string, string][] = [];
            const walk = (node: Node) => {
                if (node.tag === 'tr' && node.children.some(c => c.tag === 'td')) {
                    const label = under(node, 'label');
                    const input = under(node, 'input');
                    out.push([label ? label.textContent : '', input ? String(input.props.value ?? '') : '']);
                }
                node.children.forEach(walk);
            };
            if (table) walk(table);
            return out;
        },
        /** The question editor's "Shown to the visitor" boxes, one per choice. */
        wordings: () => screen.all(n => n.tag === 'input' && n.props.placeholder === 'e.g. Brothers'),
        removeButtons: () => screen.all(n => n.tag === 'button' && n.props.title === 'Remove Choice'),
        /** The choice rows' own "Move Up" buttons (the question has one too, with another class). */
        moveUpButtons: () => screen.all(n => n.tag === 'button' && n.props.title === 'Move Up' && /btn-outline-secondary/.test(String(n.props.class)) && n.parent?.props.class === 'btn-group' && n.parent.children.some(c => c.props?.title === 'Remove Choice')),
        save: async () => { click(screen.button('Save Form')); await flush(); },
    };
}

test('the stored prices are shown in boxes, and a changed price is what a save sends', async () => {
    const { screen, writes, rows, price, save } = await builder(afterschool());
    try {
        assert.deepEqual(rows(), [['1 child: first month', '150'], ['1 child: full payment', '400'], ['2 children: full payment', '720']]);
        assert.doesNotMatch(screen.text(), /import it again/, 'the office is no longer told to re-import');

        type(price(1), '425'); await flush();
        type(price(2), '800'); await flush();
        await save();

        assert.equal(writes.length, 1);
        assert.deepEqual(writes[0].settings.fee, { currency: 'USD', byChoice: { field: 'paymentChoice', prices: [
            { value: 'c1Month', amount: 150, perQuantity: false, reservesDate: false },
            { value: 'c1Full', amount: 425, perQuantity: false, reservesDate: false },
            { value: 'c2Full', amount: 800, perQuantity: false, reservesDate: false },
        ] } });
        assert.equal(writes[0].settings.payment.online, true, 'the payment switches are untouched');
    } finally { screen.unmount(); }
});

test('a form opened and saved with nothing changed sends its prices back as they were', async () => {
    const stored = afterschool();
    const { screen, writes, save } = await builder(stored);
    try {
        await save();
        assert.deepEqual(writes[0].settings.fee, JSON.parse(JSON.stringify(stored.settings.fee)));
    } finally { screen.unmount(); }
});

test('a reworded choice keeps its price, and its stored value when that was set by hand', async () => {
    const { screen, writes, rows, wordings, save } = await builder(afterschool());
    try {
        type(wordings()[1], '1 child: One Semester (3 months)'); await flush();

        assert.deepEqual(rows()[1], ['1 child: One Semester (3 months)', '400']);
        await save();
        assert.deepEqual(writes[0].schema.sections[0].fields[0].options[1], { value: 'c1Full', label: '1 child: One Semester (3 months)' });
        assert.deepEqual(writes[0].settings.fee.byChoice.prices[1], { value: 'c1Full', amount: 400, perQuantity: false, reservesDate: false });
    } finally { screen.unmount(); }
});

test('a removed choice takes its price with it, and the others keep theirs', async () => {
    const { screen, writes, rows, removeButtons, save } = await builder(afterschool());
    try {
        click(removeButtons()[0]); await flush();

        assert.deepEqual(rows(), [['1 child: full payment', '400'], ['2 children: full payment', '720']]);
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => [p.value, p.amount]), [['c1Full', 400], ['c2Full', 720]]);
        assert.deepEqual(writes[0].schema.sections[0].fields[0].options.map((o: any) => o.value), ['c1Full', 'c2Full']);
    } finally { screen.unmount(); }
});

test('choices moved into another order keep their own prices', async () => {
    const { screen, writes, rows, moveUpButtons, save } = await builder(afterschool());
    try {
        click(moveUpButtons()[2]); await flush();

        assert.deepEqual(rows(), [['1 child: first month', '150'], ['2 children: full payment', '720'], ['1 child: full payment', '400']]);
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => [p.value, p.amount]), [['c1Month', 150], ['c2Full', 720], ['c1Full', 400]]);
    } finally { screen.unmount(); }
});

test('a new choice has an empty price box and the form cannot be saved until it is priced', async () => {
    const { screen, writes, rows, price, wordings, save } = await builder(afterschool());
    try {
        click(screen.button('Add Choice')); await flush();
        type(wordings()[3], '3 children: full payment'); await flush();

        assert.deepEqual(rows()[3], ['3 children: full payment', '']);
        assert.match(screen.text(), /Price for "3 children: full payment": Every choice needs a price\./);
        assert.equal(screen.button('Save Form').disabled, true);
        await save();
        assert.equal(writes.length, 0, 'nothing is sent while a choice has no price');

        type(price(3), '1175'); await flush();
        assert.doesNotMatch(screen.text(), /Every choice needs a price/);
        await save();
        // The new choice's stored value is derived from its wording; its price is saved under that value.
        const added = writes[0].schema.sections[0].fields[0].options[3];
        assert.equal(added.label, '3 children: full payment');
        assert.ok(added.value);
        assert.deepEqual(writes[0].settings.fee.byChoice.prices[3], { value: added.value, amount: 1175 });
    } finally { screen.unmount(); }
});

test('a choice whose stored value follows its wording keeps its price while it is reworded', async () => {
    const { screen, writes, price, wordings, save } = await builder(afterschool());
    try {
        click(screen.button('Add Choice')); await flush();
        type(wordings()[3], 'Three'); await flush();
        type(price(3), '1175'); await flush();
        // The stored value was derived from "Three"; rewording it derives another one.
        type(wordings()[3], 'Three children'); await flush();

        assert.equal(price(3).value, '1175');
        await save();
        const reworded = writes[0].schema.sections[0].fields[0].options[3];
        assert.equal(reworded.label, 'Three children');
        assert.notEqual(reworded.value, formTypes.deriveFormIdentifier('Three', 'option'), 'the stored value moved on with the wording');
        assert.deepEqual(writes[0].settings.fee.byChoice.prices[3], { value: reworded.value, amount: 1175 });
    } finally { screen.unmount(); }
});

test('an emptied price box, a negative price and a card price under 50 cents each stop the save', async () => {
    const { screen, writes, price, save } = await builder(afterschool());
    try {
        type(price(0), ''); await flush();
        assert.match(screen.text(), /Price for "1 child: first month": Every choice needs a price\./);

        type(price(0), '-5'); await flush();
        assert.match(screen.text(), /Price for "1 child: first month": A price cannot be negative\./);

        type(price(0), '0.25'); await flush();
        assert.match(screen.text(), /Price for "1 child: first month": Every price on a form that takes payment must be at least \$0\.50/);

        type(price(0), '10.999'); await flush();
        assert.match(screen.text(), /Price for "1 child: first month": A price on a form that takes payment must be in whole cents/);

        await save();
        assert.equal(writes.length, 0);

        type(price(0), '155.50'); await flush();
        await save();
        assert.equal(writes[0].settings.fee.byChoice.prices[0].amount, 155.5);
    } finally { screen.unmount(); }
});

test('what an import set on a price (charged per unit, reserves a date) survives a price change, even through an emptied box', async () => {
    const { screen, writes, price, charged, byId, save } = await builder(iftar());
    try {
        assert.deepEqual(charged(), ['For each, by "Number of people" Reserves a date', 'For each, by "Number of people" Reserves a date', 'For each, by "Number of people" Reserves a date']);
        assert.deepEqual([0, 1, 2].map(i => [byId(`formFeeChoicePerUnit_${i}`).checked, byId(`formFeeChoiceReserves_${i}`).checked]), [[true, false], [false, true], [false, true]]);
        assert.match(screen.text(), /Dates that can be reserved:\s+2027-02-10, 2027-02-11/);

        // Select all, delete, type: the box is empty for a moment.
        type(price(0), ''); await flush();
        type(price(0), '20'); await flush();
        await save();

        assert.deepEqual(writes[0].settings.fee, { currency: 'USD', perQuantityOf: 'people', byChoice: { field: 'sponsorship', prices: [
            { value: 'individual', amount: 20, perQuantity: true },
            { value: 'quarter', amount: 450, reservesDate: true },
            { value: 'half', amount: 950, reservesDate: true },
        ] } });
        assert.deepEqual(writes[0].settings.reservation, { dates: ['2027-02-10', '2027-02-11'] });
    } finally { screen.unmount(); }
});

test('a level removed and added again can be charged for each and reserve a date again', async () => {
    const { screen, writes, price, byId, wordings, removeButtons, save } = await builder(iftar());
    try {
        click(removeButtons()[0]); await flush();
        click(screen.button('Add Choice')); await flush();
        type(wordings()[2], 'Individual iftar'); await flush();
        type(price(2), '18'); await flush();

        // New: charged once, reserving nothing, until the office says otherwise.
        assert.deepEqual([byId('formFeeChoicePerUnit_2').checked, byId('formFeeChoiceReserves_2').checked], [false, false]);
        check(byId('formFeeChoicePerUnit_2'), true); await flush();
        await save();

        const added = writes[0].schema.sections[0].fields[0].options[2].value;
        assert.deepEqual(writes[0].settings.fee.byChoice.prices, [
            { value: 'quarter', amount: 450, reservesDate: true },
            { value: 'half', amount: 950, reservesDate: true },
            { value: added, amount: 18, perQuantity: true },
        ]);
        assert.equal(writes[0].settings.fee.perQuantityOf, 'people');
    } finally { screen.unmount(); }
});

test('with no price charged for each any more, the number question is not sent with the prices', async () => {
    const { screen, writes, byId, save } = await builder(iftar());
    try {
        check(byId('formFeeChoicePerUnit_0'), false); await flush();
        await save();

        assert.equal('perQuantityOf' in writes[0].settings.fee, false, 'the server would stop asking that question and throw its answers away');
        assert.deepEqual(writes[0].settings.fee.byChoice.prices[0], { value: 'individual', amount: 18 });
    } finally { screen.unmount(); }
});

test('a list of dates that no price reserves stops the save and says what to tick', async () => {
    const { screen, writes, byId, save } = await builder(iftar());
    try {
        check(byId('formFeeChoiceReserves_1'), false); check(byId('formFeeChoiceReserves_2'), false); await flush();

        assert.match(screen.text(), /This form has a list of dates to reserve, and no price reserves one\. Tick "Reserves a date" on the prices that do\./);
        await save();
        assert.equal(writes.length, 0);

        check(byId('formFeeChoiceReserves_2'), true); await flush();
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => p.reservesDate === true), [false, false, true]);
    } finally { screen.unmount(); }
});

test('a form that charges each choice once shows no switches, only "Once"', async () => {
    const { screen, charged, byId } = await builder(afterschool());
    try {
        assert.deepEqual(charged(), ['Once', 'Once', 'Once']);
        assert.equal(byId('formFeeChoicePerUnit_0'), undefined);
        assert.equal(byId('formFeeChoiceReserves_0'), undefined);
        assert.equal(byId('formFeePerQuantity'), undefined);
    } finally { screen.unmount(); }
});

test('a form priced another way can be switched to prices by answer in the builder', async () => {
    const flat = afterschool({ settings: { fee: { amount: 80, currency: 'USD' } } });
    const { screen, writes, rows, price, byId, save } = await builder(flat);
    try {
        assert.equal(byId('formFeeChoiceField'), undefined, 'no price table under a flat price');
        assert.ok(byId('formFeePricing_choice'), 'prices by answer are offered on every form');

        choose(byId('formFeePricing_choice')); await flush();

        // One question can set a price, so it is chosen; each of its choices waits for a price.
        assert.deepEqual(rows(), [['1 child: first month', ''], ['1 child: full payment', ''], ['2 children: full payment', '']]);
        await save();
        assert.equal(writes.length, 0);

        type(price(0), '150'); type(price(1), '425'); type(price(2), '800'); await flush();
        await save();

        assert.deepEqual(writes[0].settings.fee, { currency: 'USD', byChoice: { field: 'paymentChoice', prices: [
            { value: 'c1Month', amount: 150 },
            { value: 'c1Full', amount: 425 },
            { value: 'c2Full', amount: 800 },
        ] } });
    } finally { screen.unmount(); }
});

test('the question that sets the price must be chosen, able to set one, and required', async () => {
    const twoQuestions = afterschool({
        schema: { sections: [{ id: 'payment', title: 'Payment', fields: [
            { name: 'program', label: 'Program', type: 'select', required: false,
                options: [{ value: 'online', label: 'Online' }, { value: 'hybrid', label: 'Hybrid' }] },
            { name: 'shirt', label: 'Shirt size', type: 'radio', required: true,
                options: [{ value: 's', label: 'Small' }, { value: 'l', label: 'Large' }] },
            { name: 'notes', label: 'Notes', type: 'text', required: false },
        ] }] },
        settings: { fee: { amount: 80, currency: 'USD' } },
    });
    const { screen, writes, rows, price, byId, save } = await builder(twoQuestions);
    try {
        choose(byId('formFeePricing_choice')); await flush();

        // Two questions could set the price, so none is chosen for the office.
        assert.match(screen.text(), /Choose the question whose answer sets the price, or choose No price\./);
        assert.deepEqual(pickerLines(byId('formFeeChoiceField')), ['Choose a question', 'Program', 'Shirt size']);
        await save();
        assert.equal(writes.length, 0);

        pick(byId('formFeeChoiceField'), 'Program'); await flush();
        assert.match(screen.text(), /"Program" must be required, or a registration that leaves it blank would have no price\./);
        assert.deepEqual(rows(), [['Online', ''], ['Hybrid', '']]);

        // Switching Required on for that question (the question editor's own switch) clears it.
        const required = byId('form_s0_f0_required');
        assert.ok(required, 'the question editor has a Required switch');
        check(required, true); await flush();
        assert.doesNotMatch(screen.text(), /must be required/);

        type(price(0), '80'); type(price(1), '150'); await flush();
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice, { field: 'program', prices: [{ value: 'online', amount: 80 }, { value: 'hybrid', amount: 150 }] });
    } finally { screen.unmount(); }
});

test('a brand-new form is priced by answer from nothing: add the question, its choices, and a price for each', async () => {
    const { screen, writes, rows, price, byId, wordings } = await builder(null);
    try {
        type(screen.all(n => n.tag === 'input' && typeof n.props.onInput === 'function' && n.props.placeholder !== 'e.g. Full name' && n.props.type === 'text')[0], 'Afterschool registration'); await flush();
        choose(byId('formFeePricing_choice')); await flush();

        assert.ok(byId('formFeeChoiceField'));
        assert.match(screen.text(), /Add a required dropdown or choose-one question with its own choices/);
        assert.equal(screen.button('Create Form').disabled, true);

        // A new question, made a dropdown, required, with two choices: all in the question editor.
        const before = screen.all(n => n.tag === 'input' && n.props.placeholder === 'e.g. Full name').length;
        click(screen.button('Add Question')); await flush();
        const questions = screen.all(n => n.tag === 'input' && n.props.placeholder === 'e.g. Full name');
        assert.equal(questions.length, before + 1);
        type(questions[before], 'Children and payment'); await flush();

        const answerType = screen.all(n => n.tag === 'select' && n.children.some(c => c.tag === 'option' && c.props.value === 'checkboxGroup'))[before];
        answerType.value = 'select';
        select(answerType, 'select'); await flush();
        check(byId(`form_s0_f${before}_required`), true); await flush();

        type(wordings()[0], 'One child'); await flush();
        click(screen.button('Add Choice')); await flush();
        type(wordings()[1], 'Two children'); await flush();

        // It is the only question that can set a price, so choosing it is one tap.
        pick(byId('formFeeChoiceField'), 'Children and payment'); await flush();
        assert.deepEqual(rows(), [['One child', ''], ['Two children', '']]);

        type(price(0), '425'); type(price(1), '800'); await flush();
        assert.equal(screen.button('Create Form').disabled, false, screen.text().slice(-900));
        click(screen.button('Create Form')); await flush();

        assert.equal(writes.length, 1);
        const question = writes[0].schema.sections[0].fields[before];
        assert.equal(question.name, 'childrenAndPayment');
        assert.equal(question.type, 'select');
        assert.equal(question.required, true);
        assert.deepEqual(writes[0].settings.fee, { currency: 'USD', byChoice: { field: 'childrenAndPayment', prices: [
            { value: question.options[0].value, amount: 425 },
            { value: question.options[1].value, amount: 800 },
        ] } });
    } finally { screen.unmount(); }
});

test('a pricing question whose answer key changes with its wording keeps setting the price', async () => {
    const stored = afterschool();
    // An answer key derived from the wording, as the builder makes one, follows the wording.
    stored.schema.sections[0].fields[0].name = formTypes.deriveFormIdentifier('Children and payment');
    stored.settings.fee.byChoice.field = stored.schema.sections[0].fields[0].name;
    const { screen, writes, rows, save } = await builder(stored);
    try {
        type(screen.all(n => n.tag === 'input' && n.props.placeholder === 'e.g. Full name')[0], 'Children and how you pay'); await flush();

        assert.equal(rows().length, 3, 'the price table still follows the question');
        await save();
        const renamed = writes[0].schema.sections[0].fields[0].name;
        assert.notEqual(renamed, stored.settings.fee.byChoice.field);
        assert.equal(writes[0].settings.fee.byChoice.field, renamed);
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => p.amount), [150, 400, 720]);
    } finally { screen.unmount(); }
});

test('a pricing question turned into a text question can no longer set the price, and says so', async () => {
    const { screen, writes, byId, save } = await builder(afterschool());
    try {
        const answerType = screen.all(n => n.tag === 'select' && n.children.some(c => c.tag === 'option' && c.props.value === 'checkboxGroup'))[0];
        answerType.value = 'text';
        select(answerType, 'text'); await flush();

        assert.match(screen.text(), /"Children and payment" is no longer a dropdown or choose-one question with its own choices, so it cannot set the price\. Change its answer type back, or choose another question\./);
        assert.deepEqual(pickerLines(byId('formFeeChoiceField')), ['Choose a question', 'Children and payment (cannot set the price)']);
        assert.ok(byId('formFeeChoiceField'));
        await save();
        assert.equal(writes.length, 0);
    } finally { screen.unmount(); }
});

test('the server\'s refusal of one price shows beside that price and clears when it is retyped', async () => {
    const refusal = { 'settings.fee.byChoice.prices.1.amount': ['The price for this choice was refused.'] };
    const { screen, writes, price, save } = await builder(afterschool(), refusal);
    try {
        await save();
        assert.equal(writes.length, 1);
        assert.match(screen.text(), /The price for this choice was refused\./);
        assert.match(String(price(1).props.class), /is-invalid/);
        assert.doesNotMatch(String(price(0).props.class), /is-invalid/);

        type(price(1), '410'); await flush();
        assert.doesNotMatch(String(price(1).props.class), /is-invalid/);
    } finally { screen.unmount(); }
});

// ------------------------------------------------------------------ found by the review of 2026-10-08

test('a price typed key by key keeps its cents: 425.00 stays 425.00 and is saved as 425', async () => {
    const { screen, writes, price, save } = await builder(afterschool());
    try {
        // What the box reports after each key: "425." reads back as "425", then "425.0", then "425.00".
        for (const typed of ['4', '42', '425', '425', '425.0']) { type(price(1), typed); await flush(); }
        assert.equal(price(1).value, '425.0', 'the screen did not write the box back to 425 under the cursor');

        type(price(1), '425.00'); await flush();
        assert.equal(price(1).value, '425.00');

        for (const typed of ['1', '10', '10', '10.0']) { type(price(0), typed); await flush(); }
        assert.equal(price(0).value, '10.0');
        type(price(0), '10.05'); await flush();

        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => p.amount), [10.05, 425, 720]);
    } finally { screen.unmount(); }
});

test('a price by number of entries typed key by key keeps its cents too', async () => {
    const counted = afterschool({
        schema: { sections: [{ id: 'children', title: 'Children', repeatable: true, minEntries: 1, maxEntries: 5,
            fields: [{ name: 'childName', label: 'Child name', type: 'text', required: true }] }] },
        settings: { fee: { currency: 'USD', perEntryOfSection: 'children', countTiers: [{ min: 1, amount: 100, label: '1 child' }] } },
    });
    const { screen, byId } = await builder(counted);
    try {
        const box = byId('formCountTierAmount0');
        for (const typed of ['1', '17', '170', '170', '170.0']) { type(box, typed); await flush(); }
        assert.equal(box.value, '170.0', 'not written back to 170, which made the next key 1700');
    } finally { screen.unmount(); }
});

test('a form priced "for each, times a number" switched to prices by answer leaves its number question out', async () => {
    const perPerson = afterschool({
        schema: { sections: [{ id: 'giving', title: 'Giving', fields: [
            { name: 'people', label: 'Number of people', type: 'number', required: true, min: 1 },
            { name: 'children', label: 'How many children', type: 'select', required: true,
                options: [{ value: 'one', label: 'One child' }, { value: 'two', label: 'Two children' }] },
        ] }] },
        settings: { fee: { amount: 17, currency: 'USD', perQuantityOf: 'people' } },
    });
    const { screen, writes, price, charged, byId, save } = await builder(perPerson);
    try {
        choose(byId('formFeePricing_choice')); await flush();
        type(price(0), '425'); type(price(1), '800'); await flush();

        assert.deepEqual(charged(), ['Once', 'Once'], 'no "for each" switch on a form that never charged a choice per unit');
        await save();
        assert.deepEqual(writes[0].settings.fee, { currency: 'USD', byChoice: { field: 'children', prices: [
            { value: 'one', amount: 425 },
            { value: 'two', amount: 800 },
        ] } });
    } finally { screen.unmount(); }
});

test('the school calendar chosen for the pricing question and a typed list chosen back keeps every price and switch', async () => {
    const calendar = [{ key: formTypes.SCHOOL_MEETING_DAYS, label: 'The school calendar (meeting days)', available: true }];
    const { screen, writes, rows, byId, save } = await builder(iftar(), null, calendar);
    try {
        choose(byId('form_s0_f0_src_calendar')); await flush();
        assert.match(screen.text(), /"Sponsorship" is no longer a dropdown or choose-one question with its own choices/);

        choose(byId('form_s0_f0_src_typed')); await flush();
        assert.deepEqual(rows(), [['Individual iftar', '18'], ['Quarter of an evening', '450'], ['Half of an evening', '950']]);
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices, [
            { value: 'individual', amount: 18, perQuantity: true },
            { value: 'quarter', amount: 450, reservesDate: true },
            { value: 'half', amount: 950, reservesDate: true },
        ]);
    } finally { screen.unmount(); }
});

test('the pricing question keeps setting the price when its answer key is edited by hand', async () => {
    const { screen, writes, rows, save } = await builder(afterschool());
    try {
        const answerKey = screen.all(n => n.tag === 'input' && n.value === 'paymentChoice')[0];
        assert.ok(answerKey, 'the question editor shows the answer key');
        type(answerKey, 'howYouPay'); await flush();

        assert.equal(rows().length, 3, 'the price table is still there');
        assert.doesNotMatch(screen.text(), /cannot set the price/);
        await save();
        assert.equal(writes[0].schema.sections[0].fields[0].name, 'howYouPay');
        assert.equal(writes[0].settings.fee.byChoice.field, 'howYouPay');
    } finally { screen.unmount(); }
});

test('a new question elsewhere whose answer key passes through the pricing question\'s does not take the price', async () => {
    const twoSections = afterschool({
        schema: { sections: [
            { id: 'about', title: 'About you', fields: [{ name: 'fullName', label: 'Full name', type: 'text', required: true }] },
            { id: 'payment', title: 'Payment', fields: [{ name: 'level', label: 'Level', type: 'select', required: true,
                options: [{ value: 'basic', label: 'Basic' }, { value: 'plus', label: 'Plus' }] }] },
        ] },
        settings: { fee: { currency: 'USD', byChoice: { field: 'level', prices: [{ value: 'basic', amount: 50 }, { value: 'plus', amount: 120 }] } } },
    });
    const { screen, writes, rows, save } = await builder(twoSections);
    try {
        // Add a question in the FIRST section and type "Level of study": at "Level" its key is `level` too.
        click(screen.all(n => n.tag === 'button' && /Add Question/.test(n.textContent))[0]); await flush();
        const added = screen.all(n => n.tag === 'input' && n.props.placeholder === 'e.g. Full name')[1];
        for (const typed of ['L', 'Level', 'Level o', 'Level of study']) { type(added, typed); await flush(); }

        assert.deepEqual(rows(), [['Basic', '50'], ['Plus', '120']]);
        await save();
        assert.equal(writes[0].settings.fee.byChoice.field, 'level');
        assert.equal(writes[0].schema.sections[0].fields[1].name, formTypes.deriveFormIdentifier('Level of study'));
    } finally { screen.unmount(); }
});

test('a pricing question that is removed is named by its wording, and another can be chosen', async () => {
    const { screen, writes, byId, picker, save } = await builder(afterschool());
    try {
        click(screen.all(n => n.tag === 'button' && n.props.title === 'Remove Question')[0]); await flush();

        assert.match(screen.text(), /"Children and payment" set the price, and it is no longer a question outside the repeating sections\. Choose another question, or put it back\./);
        assert.deepEqual(pickerLines(picker()), ['Choose a question', 'Children and payment (cannot set the price)']);
        assert.ok(byId('formFeeChoiceField'));
        await save();
        assert.equal(writes.length, 0);
    } finally { screen.unmount(); }
});

test('only a dropdown or choose-one question with its own choices, asked once, is offered to set the price', async () => {
    const calendar = [{ key: formTypes.SCHOOL_MEETING_DAYS, label: 'The school calendar (meeting days)', available: true }];
    const mixed = afterschool({
        schema: { sections: [
            { id: 'main', title: 'Main', fields: [
                { name: 'program', label: 'Program', type: 'select', required: true, options: [{ value: 'a', label: 'A' }] },
                { name: 'pickup', label: 'Pick-up', type: 'radio', required: true, options: [{ value: 'b', label: 'B' }] },
                { name: 'extras', label: 'Extras', type: 'checkboxGroup', required: false, options: [{ value: 'c', label: 'C' }] },
                { name: 'day', label: 'Day', type: 'select', required: true, optionsSource: formTypes.SCHOOL_MEETING_DAYS },
                { name: 'notes', label: 'Notes', type: 'text', required: false },
            ] },
            { id: 'children', title: 'Children', repeatable: true, minEntries: 1, maxEntries: 3, fields: [
                { name: 'grade', label: 'Grade', type: 'select', required: true, options: [{ value: 'k', label: 'K' }] },
            ] },
        ] },
        settings: { fee: { amount: 80, currency: 'USD' } },
    });
    const { screen, byId, picker } = await builder(mixed, null, calendar);
    try {
        choose(byId('formFeePricing_choice')); await flush();
        assert.deepEqual(pickerLines(picker()), ['Choose a question', 'Program', 'Pick-up']);
    } finally { screen.unmount(); }
});

test('stored prices listed in another order than the choices go to the choices they name', async () => {
    const stored = afterschool();
    stored.settings.fee.byChoice.prices.reverse();
    const { screen, writes, rows, save } = await builder(stored);
    try {
        assert.deepEqual(rows(), [['1 child: first month', '150'], ['1 child: full payment', '400'], ['2 children: full payment', '720']]);
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => [p.value, p.amount]), [['c1Month', 150], ['c1Full', 400], ['c2Full', 720]]);
    } finally { screen.unmount(); }
});

test('a stored price for a choice the question no longer has is left out, and a choice with no stored price waits for one', async () => {
    const stored = afterschool();
    stored.settings.fee.byChoice.prices[2] = { value: 'gone', amount: 999 };
    const { screen, writes, rows, price, save } = await builder(stored);
    try {
        assert.deepEqual(rows(), [['1 child: first month', '150'], ['1 child: full payment', '400'], ['2 children: full payment', '']]);
        assert.equal(screen.button('Save Form').disabled, true);

        type(price(2), '800'); await flush();
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice.prices.map((p: any) => p.value), ['c1Month', 'c1Full', 'c2Full']);
    } finally { screen.unmount(); }
});

test('what the server refuses about the prices as a whole, or about a row, is shown in the price block', async () => {
    const refusal = {
        'settings.fee.byChoice.prices': ['Every choice of "paymentChoice" needs a price. Missing: c9.'],
        'settings.fee.byChoice.prices.0.value': ['"c1Month" has two prices. Give each choice one price.'],
        'settings.reservation': ['No price reserves a date, so the list of dates would never be used.'],
    };
    const { screen, price, save } = await builder(afterschool(), refusal);
    try {
        await save();
        const block = screen.all(n => n.props['data-test'] === 'choice-prices')[0].textContent;

        assert.match(block, /Every choice of "paymentChoice" needs a price\. Missing: c9\./);
        assert.match(block, /"c1Month" has two prices\. Give each choice one price\./);
        assert.match(block, /No price reserves a date/);
        assert.match(String(price(0).props.class), /is-invalid/);
    } finally { screen.unmount(); }
});

test('a refusal beside one price does not move to another choice when a choice above it is removed', async () => {
    const refusal = { 'settings.fee.byChoice.prices.1.amount': ['The price for this choice was refused.'] };
    const { screen, price, removeButtons, save } = await builder(afterschool(), refusal);
    try {
        await save();
        assert.match(String(price(1).props.class), /is-invalid/);

        click(removeButtons()[0]); await flush();
        assert.doesNotMatch(String(price(0).props.class), /is-invalid/);
        assert.doesNotMatch(String(price(1).props.class), /is-invalid/, 'it would now sit beside "2 children: full payment"');
        assert.doesNotMatch(screen.all(n => n.props['data-test'] === 'choice-prices')[0].textContent, /was refused/);
    } finally { screen.unmount(); }
});

test('more than twenty choices, and a price over a million, stop the save with words about the choice', async () => {
    const many = afterschool();
    many.schema.sections[0].fields[0].options = Array.from({ length: 21 }, (_, i) => ({ value: `v${i}`, label: `Choice number ${i + 1}` }));
    many.settings.fee.byChoice.prices = many.schema.sections[0].fields[0].options.map((o: any) => ({ value: o.value, amount: 10 }));
    const { screen, writes, price, removeButtons, save } = await builder(many);
    try {
        assert.match(screen.text(), /A question that sets the price can have at most 20 choices\. Remove some, or price the form another way\./);
        await save();
        assert.equal(writes.length, 0);

        click(removeButtons()[20]); await flush();
        assert.doesNotMatch(screen.text(), /at most 20 choices/);

        type(price(0), '1000001'); await flush();
        assert.match(screen.text(), /Price for "Choice number 1": A price cannot be more than 1,000,000\./);
        await save();
        assert.equal(writes.length, 0);
    } finally { screen.unmount(); }
});

test('staff cash codes on a form priced by answer are refused before the office prices every choice', async () => {
    const withCodes = afterschool();
    withCodes.settings.payment = { staffCodes: true };
    const { screen, writes, save } = await builder(withCodes);
    try {
        assert.match(screen.text(), /Staff codes cannot be used on a form priced per quantity or by the answer to a question yet/);
        await save();
        assert.equal(writes.length, 0);
    } finally { screen.unmount(); }
});

test('prices by answer show the currency box, and a negative amount left in another pricing does not block them', async () => {
    const negative = afterschool({ settings: { fee: { amount: -5, currency: 'USD' } } });
    const { screen, writes, price, byId, save } = await builder(negative);
    try {
        choose(byId('formFeePricing_choice')); await flush();

        assert.ok(byId('formFeeCurrency'), 'the currency can be seen and changed here');
        assert.doesNotMatch(screen.text(), /The fee cannot be negative/);
        type(price(0), '150'); type(price(1), '425'); type(price(2), '800'); await flush();
        await save();
        assert.equal(writes.length, 1);
        assert.equal('amount' in writes[0].settings.fee, false);
    } finally { screen.unmount(); }
});

// ------------------------------------------------------------------ found by the second read of the fix, 2026-10-08

test('every price box keeps what is being typed when the screen redraws for another reason', async () => {
    // A flat price, then a date step: both were bound to their number, and any redraw wrote it back.
    const flat = afterschool({ settings: { fee: { amount: 80, currency: 'USD' } } });
    const one = await builder(flat);
    try {
        const amount = one.byId('formFeeAmount');
        for (const typed of ['4', '42', '425', '425', '425.0']) { type(amount, typed); await flush(); }
        // Something else on the screen changes while the box holds "425.0".
        check(one.byId('formPaymentStaffCodes'), true); await flush();
        assert.equal(amount.value, '425.0', 'not written back to 425, which made the next key 4250');

        type(amount, '425.00'); await flush();
        check(one.byId('formPaymentStaffCodes'), false); await flush();
        assert.equal(amount.value, '425.00');
        await one.save();
        assert.equal(one.writes[0].settings.fee.amount, 425);
    } finally { one.screen.unmount(); }

    const stepped = afterschool({ settings: { fee: { amount: 140, currency: 'USD', tiers: [{ amount: 100, until: '2027-08-14', label: 'Early bird' }] } } });
    const two = await builder(stepped);
    try {
        const step = two.byId('formTierAmount0');
        for (const typed of ['1', '10', '100', '100', '100.0']) { type(step, typed); await flush(); }
        check(two.byId('formPaymentStaffCodes'), true); await flush();
        assert.equal(step.value, '100.0');
    } finally { two.screen.unmount(); }

    const counted = afterschool({
        schema: { sections: [{ id: 'children', title: 'Children', repeatable: true, minEntries: 1, maxEntries: 5,
            fields: [{ name: 'childName', label: 'Child name', type: 'text', required: true }] }] },
        settings: { fee: { currency: 'USD', perEntryOfSection: 'children', countTiers: [{ min: 1, amount: 100, label: '1 child' }] } },
    });
    const three = await builder(counted);
    try {
        const box = three.byId('formCountTierAmount0');
        for (const typed of ['1', '17', '170', '170', '170.0']) { type(box, typed); await flush(); }
        check(three.byId('formPaymentStaffCodes'), true); await flush();
        assert.equal(box.value, '170.0');
    } finally { three.screen.unmount(); }
});

test('choices set aside for the school calendar come back to their own question, even after the questions are moved', async () => {
    const calendar = [{ key: formTypes.SCHOOL_MEETING_DAYS, label: 'The school calendar (meeting days)', available: true }];
    const twoQuestions = afterschool({
        schema: { sections: [{ id: 'payment', title: 'Payment', fields: [
            { name: 'paymentChoice', label: 'Children and payment', type: 'select', required: true,
                options: [{ value: 'c1Month', label: '1 child: first month' }, { value: 'c1Full', label: '1 child: full payment' }] },
            { name: 'day', label: 'Day', type: 'select', required: true, optionsSource: formTypes.SCHOOL_MEETING_DAYS },
        ] }] },
        settings: { fee: { currency: 'USD', byChoice: { field: 'paymentChoice', prices: [{ value: 'c1Month', amount: 150 }, { value: 'c1Full', amount: 400 }] } } },
    });
    const { screen, writes, rows, byId, wordings, save } = await builder(twoQuestions, null, calendar);
    try {
        // The pricing question takes the calendar, then is moved below "Day".
        choose(byId('form_s0_f0_src_calendar')); await flush();
        // [0] is the section's own Move Down; [1] is the first question's.
        click(screen.all(n => n.tag === 'button' && n.props.title === 'Move Down')[1]); await flush();
        assert.deepEqual(screen.all(n => n.tag === 'input' && n.props.placeholder === 'e.g. Full name').map(input => input.props.value), ['Day', 'Children and payment']);

        // "Day", now first, goes back to a typed list: it must not be handed the other question's choices.
        choose(byId('form_s0_f0_src_typed')); await flush();
        assert.deepEqual(wordings().map(input => input.props.value), [''], '"Day" starts with one empty choice of its own');

        // The pricing question, now second, gets its own choices and prices back.
        choose(byId('form_s0_f1_src_typed')); await flush();
        assert.deepEqual(rows(), [['1 child: first month', '150'], ['1 child: full payment', '400']]);

        type(wordings()[0], 'Saturday'); await flush();
        await save();
        assert.deepEqual(writes[0].settings.fee.byChoice, { field: 'paymentChoice', prices: [{ value: 'c1Month', amount: 150 }, { value: 'c1Full', amount: 400 }] });
        assert.deepEqual(writes[0].schema.sections[0].fields.map((f: any) => [f.name, (f.options ?? []).length]), [['day', 1], ['paymentChoice', 2]]);
    } finally { screen.unmount(); }
});

test('a refusal of a choice\'s stored value goes when that value is retyped', async () => {
    const refusal = { 'settings.fee.byChoice.prices.0.value': ['The stored value of this choice was refused.'] };
    const { screen, price, save } = await builder(afterschool(), refusal);
    try {
        await save();
        assert.match(String(price(0).props.class), /is-invalid/);

        const storedValue = screen.all(n => n.tag === 'input' && n.value === 'c1Month')[0];
        assert.ok(storedValue, 'the question editor shows the stored value');
        type(storedValue, 'firstMonth'); await flush();

        assert.doesNotMatch(String(price(0).props.class), /is-invalid/);
        assert.doesNotMatch(screen.all(n => n.props['data-test'] === 'choice-prices')[0].textContent, /was refused/);
    } finally { screen.unmount(); }
});
