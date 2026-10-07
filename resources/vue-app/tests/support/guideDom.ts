/** Minimal DOM adapter for the real guide runtime in the existing Vue custom renderer.
 * Parses only our test fixture's passive HTML. No layout or browser behavior is claimed.
 */
import { Node } from './mountSfc.ts';

const proto: any = Node.prototype;
// Real DOM nodes are not Vue reactive objects. Keep the adapter identical in that respect.
proto.__v_skip = true;
const oldText = Object.getOwnPropertyDescriptor(proto, 'textContent')!;
const oldDisabled = Object.getOwnPropertyDescriptor(proto, 'disabled')!;
Object.defineProperty(proto, 'textContent', { get: oldText.get, set(value: string) { this.replaceChildren(new Node('text', '', value || '')); } });
Object.defineProperty(proto, 'disabled', { get: oldDisabled.get, set(value: boolean) { this.props.disabled = value; } });
Object.defineProperties(proto, {
    dataset: { get() { const node = this; return new Proxy({}, { get: (_, key: string) => typeof key === 'string' ? node.props['data-' + key.replace(/[A-Z]/g, c => '-' + c.toLowerCase())] : undefined, set: (_, key: string, value) => { node.props['data-' + key.replace(/[A-Z]/g, c => '-' + c.toLowerCase())] = value; return true; } }); } },
    className: { get() { return this.props.class || ''; }, set(value) { this.props.class = value; } },
    classList: { get() { const node = this; return { add: (name: string) => { node.className += ' ' + name; }, remove: (name: string) => { node.className = node.className.split(/\s+/).filter((n: string) => n !== name).join(' '); }, contains: (name: string) => node.className.split(/\s+/).includes(name) }; } },
    style: { get() { return this._style ||= {}; } },
    width: { get() { return Number(this.props.width); } },
    height: { get() { return Number(this.props.height); } },
    alt: { get() { return this.props.alt || ''; } },
    href: { get() { return this.props.href; }, set(value) { this.props.href = value; } },
    src: { get() { return this.props.src; }, set(value) { this.props.src = value; this.onload?.(); } },
    parentElement: { get() { return this.parent; } },
    tagName: { get() { return this.tag.toUpperCase(); } },
    innerHTML: { set(html: string) {
        this.replaceChildren();
        const stack = [this];
        for (const token of html.match(/<[^>]*>|[^<]+/g) || []) {
            if (token.startsWith('</')) { stack.pop(); continue; }
            if (!token.startsWith('<')) { stack.at(-1).append(new Node('text', '', token)); continue; }
            const tag = /^<([\w-]+)/.exec(token)![1];
            const el: any = new Node('el', tag);
            for (const attr of token.slice(tag.length + 1, -1).matchAll(/([\w-]+)(?:="([^"]*)")?/g)) el.props[attr[1]] = attr[2] ?? '';
            stack.at(-1).append(el);
            if (!['img', 'br', 'hr'].includes(tag)) stack.push(el);
        }
    } },
});
proto.getAttribute = function (name: string) { return this.props[name] ?? null; };
proto.hasAttribute = function (name: string) { return name in this.props; };
proto.append = function (...nodes: any[]) { for (const node of nodes) { if (node.parent) node.parent.children = node.parent.children.filter((n: any) => n !== node); node.parent = this; this.children.push(node); } };
proto.replaceChildren = function (...nodes: any[]) { for (const node of this.children) node.parent = null; this.children = []; this.append(...nodes); };
proto.replaceWith = function (node: any) { const parent = this.parent; const index = parent.children.indexOf(this); parent.children[index] = node; node.parent = parent; this.parent = null; };
proto.contains = function (node: any) { return node === this || this.children.some((n: any) => n.contains(node)); };
proto.scrollIntoView = function () { this.scrolled = true; };
const matches = (node: any, selector: string) => selector.split(',').some(part => {
    part = part.trim();
    if (part.startsWith('.')) return node.classList.contains(part.slice(1));
    const tag = /^([\w-]+)/.exec(part)?.[1];
    const attr = /\[([\w-]+)(?:="([^"]*)")?\]/.exec(part);
    return (!tag || node.tag === tag) && (!attr || node.hasAttribute(attr[1]) && (attr[2] === undefined || node.getAttribute(attr[1]) === attr[2]));
});
proto.querySelectorAll = function (selector: string) { const found: any[] = []; const walk = (node: any) => { for (const child of node.children) { if (child.kind === 'el' && matches(child, selector)) found.push(child); walk(child); } }; walk(this); return found; };
proto.querySelector = function (selector: string) { return this.querySelectorAll(selector)[0] || null; };
proto.closest = function (selector: string) { let current = this; while (current) { if (matches(current, selector)) return current; current = current.parent; } return null; };
(globalThis as any).document.createElement = (tag: string) => new Node('el', tag);

export function delegatedClick(node: any) {
    const event = { target: node, button: 0, preventDefault() {}, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false };
    for (let current = node; current; current = current.parent) {
        for (const listener of current.listeners.click || []) listener(event);
    }
}
