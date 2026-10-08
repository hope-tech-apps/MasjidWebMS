import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import * as vue from 'vue';
import { compileSfc, loadTs } from './mountSfc.ts';

// Keep Teleport's actual patching; disabling it renders the dialog in this test root.
const inlineTeleport = { ...vue.Teleport, process(before: any, after: any, ...args: any[]) {
    after.props = { ...after.props, disabled: true };
    (vue.Teleport as any).process(before, after, ...args);
} };
const root = new URL('../../', import.meta.url).pathname;
export const empty = { render: () => null };
export const page = { setup(_: any, { slots }: any) { return () => vue.h('div', [slots.headerButtons?.(), slots.default?.()]); } };

// Run the screens' actual pure helpers. Only transport, stores and unrelated widgets
// are replaced. Tests supply the real child when the interaction crosses that child.
export async function modulesFor(file: string, overrides: Record<string, any>, trail: string[] = []): Promise<Record<string, any>> {
    if (trail.includes(file)) return {};
    const modules: Record<string, any> = { vue: { ...vue, Teleport: inlineTeleport } };
    const source = readFileSync(path.join(root, file), 'utf8');
    for (const match of source.matchAll(/^import\s+(type\s+)?[\s\S]*?\s+from\s+['"]([^'"]+)['"]/gm)) {
        const spec = match[2];
        if (spec in overrides) { modules[spec] = overrides[spec]; continue; }
        if (spec === 'vue') continue;
        if (match[1]) { modules[spec] = {}; continue; }
        const rel = spec.startsWith('@/') ? spec.slice(2) : path.join(path.dirname(file), spec);
        // The class shell passes legacy content through its slot while OFF. An empty
        // stub would erase the existing teacher panels from every mounted regression.
        if (rel === 'components/classes/ClassNavigation.vue') {
            modules[spec] = { default: await compileSfc(rel, await modulesFor(rel, overrides, [...trail, file])) };
            continue;
        }
        if (rel.endsWith('.vue')) { modules[spec] = { default: empty }; continue; }
        if (trail.includes(`${rel}.ts`)) { modules[spec] = {}; continue; }
        if (existsSync(path.join(root, `${rel}.ts`))) {
            modules[spec] = await loadTs(`${rel}.ts`, await modulesFor(`${rel}.ts`, overrides, [...trail, file]));
        } else if (spec === 'sweetalert2') {
            modules[spec] = { default: { fire: async () => ({ isConfirmed: false }) } };
        } else if (spec === 'axios') {
            modules[spec] = { AxiosError: class extends Error {} };
        } else {
            throw new Error(`Supply ${spec} for ${file}`);
        }
    }
    return { ...modules, ...overrides };
}
