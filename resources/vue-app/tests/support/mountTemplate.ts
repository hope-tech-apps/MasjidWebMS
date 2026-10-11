/** Mount an exact template fragment from a large SFC with the same Vue renderer as mounted SFC tests. */
import * as vue from 'vue';
import { Node } from './mountSfc.ts';

export function mountTemplate(template: string, context: Record<string, any>, components: Record<string, any>) {
    const ops: any = {
        insert(child: Node, parent: Node, anchor: Node | null) {
            if (child.parent) ops.remove(child);
            const at = anchor ? parent.children.indexOf(anchor) : -1;
            if (at < 0) parent.children.push(child); else parent.children.splice(at, 0, child);
            child.parent = parent;
        },
        remove(child: Node) {
            if (child.parent) child.parent.children = child.parent.children.filter(n => n !== child);
            child.parent = null;
        },
        createElement: (tag: string) => new Node('el', tag),
        createText: (text: string) => new Node('text', '', text),
        createComment: (text: string) => new Node('comment', '', text),
        setText: (n: Node, text: string) => { n.text = text; },
        setElementText: (n: Node, text: string) => { n.children = [new Node('text', '', text)]; n.children[0].parent = n; },
        parentNode: (n: Node) => n.parent,
        nextSibling: (n: Node) => n.parent?.children[n.parent.children.indexOf(n) + 1] ?? null,
        querySelector: () => null, setScopeId() {},
        patchProp: (n: Node, key: string, _old: any, value: any) => {
            if (value === null || value === undefined || value === false) delete n.props[key]; else n.props[key] = value;
        },
    };
    const root = new Node('el', 'root');
    const app = vue.createRenderer(ops).createApp({ components, setup: () => context, render: vue.compile(template) });
    app.mount(root);
    const all = (predicate: (node: Node) => boolean) => {
        const out: Node[] = [];
        const walk = (node: Node) => { if (predicate(node)) out.push(node); node.children.forEach(walk); };
        walk(root); return out;
    };
    return { root, all, text: () => root.textContent, unmount: () => app.unmount() };
}
