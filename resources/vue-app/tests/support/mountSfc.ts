/**
 * Mount a real single-file component in `npm run test:spa`, with no DOM and no new dependency.
 *
 * The suite runs on Node's own test runner (no Vite, no jsdom), so a screen used to be tested only
 * by reading its source. This compiles the .vue file with the project's own @vue/compiler-sfc,
 * swaps every import for a module the test hands in (the API service, the stores, a child
 * component), and renders it with Vue's `createRenderer` into plain objects. A test can then click
 * a button, type into a field, wait for the answers it scripted, and read what the screen says.
 *
 * What it is NOT: a browser. There is no layout, no CSS and no real event bubbling. A click runs
 * the element's own handler, and (as a browser does) a click on a disabled element runs nothing;
 * `press()` runs the handler anyway, for a guard that has to hold even when the button was not
 * re-rendered in time (a double tap).
 */
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const sfc = require('@vue/compiler-sfc');
const vue = require('vue');

// vModelText reads `document.activeElement` when a bound value changes under a field. A screen that moves
// focus asks the document for an element by id, so the stub finds one among the roots that are mounted.
const mountedRoots = new Set<any>();
if (typeof (globalThis as any).document === 'undefined') {
    (globalThis as any).document = {
        activeElement: null,
        getElementById(id: string) {
            const find = (n: any): any => (n.kind === 'el' && n.props.id === id ? n : n.children.map(find).find(Boolean) ?? null);
            for (const root of mountedRoots) { const hit = find(root); if (hit) return hit; }
            return null;
        },
    };
}

export class Node {
    kind: 'el' | 'text' | 'comment';
    tag: string;
    text = '';
    props: Record<string, any> = {};
    children: Node[] = [];
    parent: Node | null = null;
    listeners: Record<string, Array<(e: any) => void>> = {};
    // What the runtime's v-model directives read and write.
    value: any = '';
    options: any[] = [];
    selectedIndex = -1;
    type = '';

    constructor(kind: 'el' | 'text' | 'comment', tag = '', text = '') {
        this.kind = kind;
        this.tag = tag;
        this.text = text;
    }

    addEventListener(name: string, fn: (e: any) => void) { (this.listeners[name] ??= []).push(fn); }
    removeEventListener(name: string, fn: (e: any) => void) { this.listeners[name] = (this.listeners[name] ?? []).filter((f) => f !== fn); }
    setAttribute(key: string, value: any) { this.props[key] = value; }
    removeAttribute(key: string) { delete this.props[key]; }

    /** Everything this node says, as one string with the whitespace squeezed. */
    get textContent(): string {
        if (this.kind === 'text') return this.text;
        if (this.kind === 'comment') return '';
        return this.children.map((c) => c.textContent).join(' ').replace(/\s+/g, ' ').trim();
    }

    get disabled(): boolean { return this.props.disabled === true || this.props.disabled === ''; }

    /** Focus, as far as a screen can tell: the document's active element becomes this one. */
    focus() { (globalThis as any).document.activeElement = this; }

    /** The first element under this one whose attribute matches `[name="value"]`, the one selector a screen asks for. */
    querySelector(selector: string): Node | null {
        const match = /^\[([\w-]+)="([^"]*)"\]$/.exec(selector);
        if (!match) throw new Error(`mountSfc: querySelector supports [attr="value"] only, not ${selector}`);
        const [, name, value] = match;
        const find = (n: Node): Node | null => {
            for (const child of n.children) {
                if (child.kind === 'el' && String(child.props[name]) === value) return child;
                const deeper = find(child);
                if (deeper) return deeper;
            }
            return null;
        };

        return find(this);
    }
}

const nodeOps = {
    insert(child: Node, parent: Node, anchor: Node | null) {
        if (child.parent) nodeOps.remove(child);
        const at = anchor ? parent.children.indexOf(anchor) : -1;
        if (at < 0) parent.children.push(child); else parent.children.splice(at, 0, child);
        child.parent = parent;
    },
    remove(child: Node) {
        const p = child.parent;
        if (p) { p.children = p.children.filter((c) => c !== child); child.parent = null; }
    },
    createElement(tag: string) { const n = new Node('el', tag); if (tag === 'input') n.type = 'text'; return n; },
    createText(text: string) { return new Node('text', '', text); },
    createComment(text: string) { return new Node('comment', '', text); },
    setText(node: Node, text: string) { node.text = text; },
    setElementText(el: Node, text: string) {
        el.children.forEach((c) => { c.parent = null; });
        el.children = text === '' ? [] : [new Node('text', '', text)];
        el.children.forEach((c) => { c.parent = el; });
    },
    parentNode(node: Node) { return node.parent; },
    nextSibling(node: Node) {
        const p = node.parent;
        if (!p) return null;
        return p.children[p.children.indexOf(node) + 1] ?? null;
    },
    querySelector() { return null; },
    setScopeId() {},
};

function patchProp(el: Node, key: string, _prev: any, next: any) {
    if (next === null || next === undefined || next === false && key !== 'value') {
        delete el.props[key];
    } else {
        el.props[key] = next;
    }
    if (key === 'value') el.value = next ?? '';
    if (key === 'type') el.type = String(next ?? '');
}

const { createApp } = vue.createRenderer({ ...nodeOps, patchProp });

/** An ES `import` clause turned into `const` lines that read from the modules the test supplied. */
function rewriteImports(code: string): string {
    return code.replace(/^\s*import\s+(type\s+)?([\s\S]*?)\s+from\s+['"]([^'"]+)['"];?[ \t]*$/gm, (_all, isType, clause: string, spec: string) => {
        if (isType) return '';
        const mod = `__sfcModule(${JSON.stringify(spec)})`;
        const lines: string[] = [];
        let rest = clause.trim();

        const ns = /^\*\s+as\s+(\w+)$/.exec(rest);
        if (ns) return `const ${ns[1]} = ${mod};`;

        const named = /\{([\s\S]*)\}/.exec(rest);
        if (named) {
            const parts = named[1].split(',').map((s) => s.trim()).filter((s) => s && !s.startsWith('type '));
            if (parts.length) lines.push(`const { ${parts.map((p) => p.replace(/\s+as\s+/, ': ')).join(', ')} } = ${mod};`);
            rest = rest.replace(named[0], '').replace(/,\s*$/, '').trim();
        }
        const def = rest.replace(/,$/, '').trim();
        if (def) lines.push(`const ${def} = ${mod}.default;`);

        return lines.join('\n');
    });
}

export interface Mounted {
    root: Node;
    /** Every element under the root that passes `test`. */
    all(test: (n: Node) => boolean): Node[];
    /** The one button whose words include `label` (fails the test if there is none, or two). */
    button(label: string): Node;
    /** The screen's words, as one string. */
    text(): string;
    unmount(): void;
}

let seq = 0;
const componentErrors: any[] = [];

/**
 * Compile and mount `relPath` (from resources/vue-app/) with these props, reading each of its
 * imports from `modules` (keyed by the specifier exactly as the component writes it). 'vue' is
 * supplied here; anything else the component imports and the test did not supply fails loudly.
 */
export async function mountSfc(relPath: string, props: Record<string, any>, modules: Record<string, any>): Promise<Mounted> {
    const file = new URL(`../../${relPath}`, import.meta.url);
    const source = readFileSync(file, 'utf8');
    const { descriptor, errors } = sfc.parse(source, { filename: file.pathname });
    if (errors.length) throw errors[0];

    const id = `sfc${++seq}`;
    const script = sfc.compileScript(descriptor, {
        id,
        inlineTemplate: true,
        // No static hoisting: a hoisted block would need a DOM's innerHTML to insert.
        templateOptions: { compilerOptions: { hoistStatic: false } },
    });

    const code = rewriteImports(script.content);
    const dir = mkdtempSync(join(tmpdir(), 'mount-sfc-'));
    const out = join(dir, `${id}.ts`);
    writeFileSync(out, code);

    const supplied: Record<string, any> = { vue, ...modules };
    (globalThis as any).__sfcModule = (spec: string) => {
        if (!(spec in supplied)) throw new Error(`mountSfc: ${relPath} imports '${spec}', which the test did not supply`);
        return supplied[spec];
    };

    let component: any;
    try {
        component = (await import(pathToFileURL(out).href)).default;
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }

    const root = new Node('el', 'root');
    const app = createApp(component, props);
    app.config.warnHandler = () => {};
    // An error inside the component (a render, a handler, a hook) fails the test at the next flush()
    // instead of vanishing into a console the runner does not show.
    app.config.errorHandler = (e: any) => { componentErrors.push(e); };
    app.mount(root);
    mountedRoots.add(root);

    const walk = (n: Node, test: (n: Node) => boolean, acc: Node[]) => {
        if (n.kind === 'el' && n !== root && test(n)) acc.push(n);
        n.children.forEach((c) => walk(c, test, acc));
        return acc;
    };

    return {
        root,
        all: (test) => walk(root, test, []),
        button(label: string) {
            const found = walk(root, (n) => n.tag === 'button' && n.textContent.includes(label), []);
            if (found.length !== 1) throw new Error(`mountSfc: ${found.length} buttons say "${label}" (screen: ${root.textContent})`);
            return found[0];
        },
        text: () => root.textContent,
        unmount: () => { mountedRoots.delete(root); app.unmount(); },
    };
}

function fakeEvent(target: Node) {
    return { target, currentTarget: target, preventDefault() {}, stopPropagation() {} };
}

function run(el: Node, name: string) {
    const key = `on${name[0].toUpperCase()}${name.slice(1)}`;
    const handler = el.props[key];
    const handlers = Array.isArray(handler) ? handler : handler ? [handler] : [];
    handlers.forEach((h: any) => h(fakeEvent(el)));
    (el.listeners[name] ?? []).forEach((h) => h(fakeEvent(el)));
    return handlers.length + (el.listeners[name] ?? []).length;
}

/** A click as a browser gives it: nothing at all on a disabled element. Returns whether it ran. */
export function click(el: Node): boolean {
    if (el.disabled) return false;
    return run(el, 'click') > 0;
}

/** The element's click handler, run whether or not the element is disabled (a tap the re-render missed). */
export function press(el: Node): void {
    run(el, 'click');
}

/** Submit a form (its `@submit.prevent` handler). */
export function submit(form: Node): void {
    run(form, 'submit');
}

/** Type into a field bound with v-model. */
export function type(el: Node, value: string): void {
    el.value = value;
    run(el, 'input');
}

/** Let every settled promise and Vue's scheduler run; throw what the component threw meanwhile. */
export async function flush(times = 6): Promise<void> {
    for (let i = 0; i < times; i++) {
        await new Promise((r) => setTimeout(r, 0));
        await vue.nextTick();
    }
    if (componentErrors.length) throw componentErrors.splice(0)[0];
}

/** A promise the test resolves or rejects when it chooses: an API answer that has not arrived yet. */
export function deferred<T = any>() {
    let resolve!: (v: T) => void;
    let reject!: (e: any) => void;
    const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

/** An axios-shaped failure with an HTTP status and this body. */
export function httpError(status: number, data: Record<string, any>) {
    const e: any = new Error(`Request failed with status code ${status}`);
    e.isAxiosError = true;
    e.response = { status, data };
    return e;
}
