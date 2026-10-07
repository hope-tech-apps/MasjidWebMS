import type { GuideItem, GuideTask } from './guidePaths';

export interface GuidePicture { src: string; alt: string; opener: HTMLElement }
interface Options {
    tasks: GuideTask[];
    allowed: string[];
    path: (book: string, task?: string, faq?: string) => string;
    picture: (path: string, signal: AbortSignal) => Promise<Blob>;
    navigate: (path: string) => void;
    view: (picture: GuidePicture) => void;
    contents: (items: GuideItem[]) => void;
}

/** One inert HTML container; all behavior is delegated and all resources belong to this read. */
export function attachGuide(container: HTMLElement, options: Options) {
    const root = container.querySelector<HTMLElement>('.mg');
    if (!root) throw new Error('Guide unavailable');
    const controller = new AbortController();
    let disposed = false;
    const urls = new Set<string>();
    const tasks = new Map(options.tasks.map(t => [t.id, t]));
    const sections = [...root.querySelectorAll<HTMLElement>('section[data-task]')];
    const items: GuideItem[] = [];
    const targets = new Map<string, HTMLElement>();
    const questions = new Map<string, HTMLElement>();
    const chapterIds = new Map<HTMLElement, string>();
    const reserved = new Set(options.tasks.map(task => task.id));
    const contentsId = (candidate: string) => { while (reserved.has(candidate)) candidate = '~' + candidate; reserved.add(candidate); return candidate; };
    let faq = 0;
    for (const el of root.querySelectorAll<HTMLElement>('section[data-chapter], section[data-task], details[data-faq]')) {
        const kind = el.hasAttribute('data-chapter') ? 'chapter' : el.hasAttribute('data-faq') ? 'faq' : 'task';
        const id = kind === 'chapter' ? contentsId(`~chapter~${el.dataset.chapter}`) : kind === 'faq' ? contentsId(`~faq~${++faq}`) : el.dataset.task!;
        const title = kind === 'task' ? tasks.get(id)?.title : el.querySelector('h1,h2,h3,h4,h5,h6,summary')?.textContent;
        const item: GuideItem = { kind, id, title: title?.trim() || id, text: `${el.textContent || ''} ${el.dataset.words || ''}` };
        if (kind === 'chapter') chapterIds.set(el, id);
        if (kind === 'task') item.chapter = chapterIds.get(el.parentElement?.closest<HTMLElement>('section[data-chapter]')!);
        if (kind === 'faq') { item.faq = el.getAttribute('id') || id; questions.set(item.faq, el); }
        items.push(item);
        targets.set(id, el);
        el.tabIndex = -1;
    }
    const searchText = new Map(items.map(item => [item.id, `${item.title} ${item.text}`.toLocaleLowerCase()]));
    options.contents(items);

    for (const el of root.querySelectorAll<HTMLElement>('[data-guide], [data-guide-hint]')) {
        const book = el.dataset.guide || el.dataset.guideHint!;
        if (!options.allowed.includes(book)) {
            const text = document.createElement('span');
            text.textContent = el.textContent;
            el.replaceWith(text);
        } else {
            const link = document.createElement('a');
            link.textContent = el.textContent;
            link.href = options.path(book, el.dataset.task, el.dataset.faq);
            link.dataset.guideLink = link.getAttribute('href')!;
            el.replaceWith(link);
        }
    }

    const queue: HTMLImageElement[] = [];
    const queued = new Set<HTMLImageElement>();
    const buttons = new Map<HTMLImageElement, HTMLButtonElement>();
    const loaded = new Map<HTMLElement, GuidePicture>();
    let active = 0;
    const unavailable = (img: HTMLImageElement) => {
        const button = buttons.get(img)!;
        button.disabled = true;
        button.setAttribute('aria-label', 'Picture unavailable: ' + img.alt);
        button.replaceChildren();
        const box = document.createElement('span');
        box.textContent = 'Picture unavailable';
        box.setAttribute('role', 'img');
        box.setAttribute('aria-label', 'Picture unavailable: ' + img.alt);
        button.append(box);
        button.classList.add('mg-picture-unavailable');
    };
    const pump = () => {
        while (!disposed && active < 4 && queue.length) {
            const img = queue.shift()!;
            active++;
            const path = img.dataset.src!;
            options.picture(path, controller.signal).then(blob => {
                if (disposed) return;
                const url = URL.createObjectURL(blob);
                urls.add(url);
                const button = buttons.get(img)!;
                img.onload = () => { if (!disposed) { button.disabled = false; button.classList.remove('mg-picture-loading'); button.setAttribute('aria-label', 'Enlarge picture: ' + img.alt); } };
                img.onerror = () => {
                    if (!disposed) { loaded.delete(button); unavailable(img); URL.revokeObjectURL(url); urls.delete(url); }
                };
                loaded.set(button, { src: url, alt: img.alt, opener: button });
                img.src = url;
            }).catch(() => { if (!disposed) unavailable(img); }).finally(() => { active--; pump(); });
        }
    };
    const enqueue = (img: HTMLImageElement) => {
        if (disposed || queued.has(img)) return;
        queued.add(img); queue.push(img); pump();
    };
    const observer = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(entries => {
        for (const entry of entries) if (entry.isIntersecting) { observer!.unobserve(entry.target); enqueue(entry.target as HTMLImageElement); }
    }, { rootMargin: '300px' });
    for (const img of root.querySelectorAll<HTMLImageElement>('img[data-src]')) {
        const button = document.createElement('button');
        button.type = 'button'; button.disabled = true;
        button.className = 'mg-picture mg-picture-loading';
        button.dataset.picture = '';
        button.setAttribute('aria-label', 'Loading picture: ' + img.alt);
        button.style.width = `${img.width}px`;
        button.style.aspectRatio = `${img.width} / ${img.height}`;
        img.replaceWith(button); button.append(img); buttons.set(img, button);
        const affordance = document.createElement('span');
        affordance.className = 'mg-picture-caption'; affordance.textContent = 'Enlarge picture'; affordance.setAttribute('aria-hidden', 'true');
        button.append(affordance);
        if (observer) observer.observe(img); else enqueue(img);
    }
    const clicked = (event: MouseEvent) => {
        const target = event.target as Element | null;
        const link = target?.closest<HTMLElement>('[data-guide-link]');
        if (link && root.contains(link)) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault(); options.navigate(link.dataset.guideLink!); return;
        }
        const button = target?.closest<HTMLElement>('[data-picture]');
        if (button && root.contains(button) && loaded.has(button)) options.view(loaded.get(button)!);
    };
    root.addEventListener('click', clicked);
    const focus = (el?: HTMLElement) => {
        if (!el) return false;
        let parent: HTMLElement | null = el;
        while (parent) { parent.hidden = false; if (parent.tagName === 'DETAILS') parent.setAttribute('open', ''); if (parent === root) break; parent = parent.parentElement; }
        el.scrollIntoView({ block: 'start', behavior: 'smooth' }); el.focus(); return true;
    };
    root.tabIndex = -1;
    return {
        theme(value: string) { root.dataset.theme = value; },
        search(query: string) {
            const needle = query.trim().toLocaleLowerCase();
            for (const el of sections) {
                el.hidden = !!needle && !searchText.get(el.dataset.task!)?.includes(needle);
            }
            for (const chapter of root.querySelectorAll<HTMLElement>('section[data-chapter]')) {
                chapter.hidden = !!needle && ![...chapter.querySelectorAll<HTMLElement>('section[data-task]')].some(el => !el.hidden);
            }
            for (const item of items.filter(item => item.kind === 'faq')) {
                targets.get(item.id)!.hidden = !!needle && !searchText.get(item.id)?.includes(needle);
            }
        },
        focusTask(id: string) { return focus(targets.get(id)); },
        focusQuestion(id: string) { return focus(questions.get(id)); },
        focusTop() { return focus(root); },
        dispose() {
            disposed = true; controller.abort(); observer?.disconnect(); queue.length = 0;
            root.removeEventListener('click', clicked);
            for (const url of urls) URL.revokeObjectURL(url);
            urls.clear();
            for (const img of buttons.keys()) { img.onload = null; img.onerror = null; }
            loaded.clear();
        },
    };
}
