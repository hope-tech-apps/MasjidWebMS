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

async function builder(stored: any, refusal: Record<string, string[]> | null = null) {
    const writes: any[] = [];
    const store = reactive({ fieldTypes: formTypes.FORM_FIELD_TYPES, optionsSources: [],
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

        assert.equal(price(3).props.value, 1175);
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

test('what an import set on a price (charged per unit, reserves a date) survives a price change', async () => {
    const iftar = afterschool({
        schema: { sections: [{ id: 'giving', title: 'Giving', fields: [
            { name: 'sponsorship', label: 'Sponsorship', type: 'radio', required: true,
                options: [{ value: 'individual', label: 'Individual iftar' }, { value: 'quarter', label: 'Quarter of an evening' }] },
            { name: 'people', label: 'Number of people', type: 'number', required: true, min: 1 },
        ] }] },
        settings: {
            fee: { currency: 'USD', perQuantityOf: 'people', byChoice: { field: 'sponsorship', prices: [
                { value: 'individual', amount: 18, perQuantity: true },
                { value: 'quarter', amount: 450, reservesDate: true },
            ] } },
            reservation: { dates: ['2027-02-10', '2027-02-11'] },
        },
    });
    const { screen, writes, price, save } = await builder(iftar);
    try {
        assert.match(screen.text(), /For each, by "Number of people"/);
        assert.match(screen.text(), /reserves a date/);
        assert.match(screen.text(), /Dates that can be reserved:\s+2027-02-10, 2027-02-11/);

        type(price(0), '20'); await flush();
        await save();

        assert.deepEqual(writes[0].settings.fee, { currency: 'USD', perQuantityOf: 'people', byChoice: { field: 'sponsorship', prices: [
            { value: 'individual', amount: 20, perQuantity: true },
            { value: 'quarter', amount: 450, reservesDate: true },
        ] } });
        assert.deepEqual(writes[0].settings.reservation, { dates: ['2027-02-10', '2027-02-11'] });
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
        assert.deepEqual(byId('formFeeChoiceField').children.filter(c => c.tag === 'option').map(o => o.textContent.trim()), ['Choose a question', 'Program', 'Shirt size']);
        await save();
        assert.equal(writes.length, 0);

        select(byId('formFeeChoiceField'), 'program'); await flush();
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
        select(byId('formFeeChoiceField'), 'childrenAndPayment'); await flush();
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

        assert.match(screen.text(), /"paymentChoice" is not a dropdown or choose-one question with its own choices outside the repeating sections, so it cannot set the price\./);
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
