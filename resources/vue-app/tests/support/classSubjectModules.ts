import { readFileSync } from 'node:fs';
import path from 'node:path';
import * as vue from 'vue';
import * as pinia from 'pinia';
import { compileSfc } from './mountSfc.ts';
import { modulesFor } from './batch3Modules.ts';

/** Compile every actual child, including PageDataContainer and Pagination; replace transport/stores only. */
export async function realClassModules(file: string, overrides: Record<string, any>, cache = new Map<string, any>()): Promise<Record<string, any>> {
    const modules = await modulesFor(file, { pinia, ...overrides });
    const source = readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
    for (const match of source.matchAll(/^import\s+(?:type\s+)?[\s\S]*?\s+from\s+['"]([^'"]+\.vue)['"]/gm)) {
        const spec = match[1];
        const child = spec.startsWith('@/') ? spec.slice(2) : path.join(path.dirname(file), spec);
        if (!cache.has(child)) cache.set(child, await compileSfc(child, await realClassModules(child, overrides, cache)));
        modules[spec] = { default: cache.get(child) };
    }
    // RouterLink is a navigation primitive rather than a screen child.
    modules.vue = { ...modules.vue, resolveComponent: (name: string) => name === 'router-link'
        ? vue.defineComponent({ props: ['to'], setup: (_p: any, { slots }: any) => () => vue.h('a', slots.default?.()) })
        : vue.resolveComponent(name) };
    return modules;
}
