<template>
    <div class="flyer-viewport" ref="viewportRef" :style="{ height: `${canvas.height * scale}px` }">
        <div class="flyer-stage" :style="{ transform: `scale(${scale})` }">
            <!-- The template's own markup is written in here imperatively. Everything
                 below this node is the design's, untouched: the preview scales the
                 STAGE, never the flyer, so the export can serialise the very nodes the
                 admin was looking at at their true 1200x1600 size. -->
            <div ref="hostRef"></div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { PropType, nextTick, onBeforeUnmount, onMounted, ref, toRefs, watch } from 'vue';
import {
    FlyerContent,
    FlyerExportSize,
    FlyerListItem,
    FlyerSlot,
    FlyerTemplateManifest,
    FLYER_CANVAS
} from '@/core/types/data/masjid-related/Flyer';

// Props
const props = defineProps({
    manifest: {
        type: Object as PropType<FlyerTemplateManifest>,
        required: true
    },
    html: {
        type: String,
        required: true
    },
    /** Slot values with image slots already resolved to data URLs. */
    content: {
        type: Object as PropType<FlyerContent>,
        required: true
    },
    /** The resolved palette, as the custom properties every template reads. */
    cssVars: {
        type: Object as PropType<Record<string, string>>,
        required: true
    },
    /**
     * Editor affordances for slots that are still empty. Never true for an export —
     * they are marked and dropped on the way out.
     */
    placeholders: {
        type: Boolean,
        required: false,
        default: true
    }
});

// Emits
const emits = defineEmits(['overflow']);

const { manifest, html, content, cssVars, placeholders } = toRefs(props);

// State
const viewportRef = ref<HTMLElement | null>(null);
const hostRef = ref<HTMLElement | null>(null);
const scale = ref(0.4);

const canvas = FLYER_CANVAS;

/** The template, parsed once per design rather than per keystroke. */
let styleEl: HTMLStyleElement | null = null;
let prototypeEl: HTMLElement | null = null;
let rootEl: HTMLElement | null = null;
let observer: ResizeObserver | null = null;

/**
 * A long line steps down rather than wrapping into a line the design never had room
 * for — that is what the manifests' `size_var` is for. The floor is deliberate: past
 * roughly three quarters of the measured size the flyer stops looking like the
 * masjid's own, and the honest answer is less text, not smaller text.
 */
const FIT_STEPS = 8;
const FIT_RATIO = 0.96;
const FIT_FLOOR = 0.72;

/** How long a finished PNG's object URL is kept alive after the download is triggered. */
const OBJECT_URL_TTL_MS = 60_000;

/** The face every template names and none of them import. See exportFontCss(). */
const FLYER_FONT_FAMILY = 'Montserrat';

/** Built at most once: the embedded face is around a megabyte of base64. */
let fontCssCache: string | null = null;

// Lifecycle
onMounted(() => {
    parseTemplate();
    render();

    observer = new ResizeObserver(() => measure());
    if (viewportRef.value) observer.observe(viewportRef.value);
    measure();
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

watch([html, manifest], () => {
    parseTemplate();
    render();
});

watch([content, cssVars, placeholders], () => render());

// Methods

/** Fit the 1200px canvas to whatever column the Studio gave us. */
function measure(): void {
    const width = viewportRef.value?.clientWidth ?? 0;
    if (width > 0) scale.value = Math.min(1, width / canvas.width);
}

/**
 * Split the template into its stylesheet and its markup prototype.
 *
 * The style is injected once and left alone; the markup is cloned and refilled on
 * every edit. The <style> block is document-global while it is mounted, which is safe
 * because every template scopes itself under its own root class — that scoping is the
 * contract the templates are written to.
 */
function parseTemplate(): void {
    const host = hostRef.value;
    if (!host) return;

    host.innerHTML = '';
    styleEl = null;
    prototypeEl = null;
    rootEl = null;

    // The template is a build-time asset, not user input — this is the only innerHTML
    // in the renderer, and no slot value ever reaches it.
    const parsed = document.createElement('template');
    parsed.innerHTML = html.value;

    const css = Array.from(parsed.content.querySelectorAll('style'))
        .map((node) => node.textContent ?? '')
        .join('\n');

    styleEl = document.createElement('style');
    styleEl.textContent = css;
    host.appendChild(styleEl);

    prototypeEl = parsed.content.querySelector<HTMLElement>('.flyer');
}

/**
 * Substitute the content into a fresh clone of the design, in the order the template
 * contract sets out: repeats first, then slots, then anything left unfilled.
 */
function render(): void {
    const host = hostRef.value;
    if (!host || !prototypeEl) return;

    const root = prototypeEl.cloneNode(true) as HTMLElement;

    applyRepeats(root);
    applySlots(root, null, content.value as Record<string, unknown>);
    stripUnfilled(root);

    Object.entries(cssVars.value).forEach(([name, value]) => root.style.setProperty(name, value));

    rootEl?.remove();
    host.appendChild(root);
    rootEl = root;

    nextTick(() => {
        fitText(root);
        emits('overflow', root.scrollHeight > root.clientHeight + 2);
    });
}

/**
 * `data-repeat="name"` marks a prototype row: clone it once per item, fill the
 * `{{ name.field }}` placeholders inside each clone, then drop the prototype. Only
 * event-bulletin uses this today.
 */
function applyRepeats(root: HTMLElement): void {
    root.querySelectorAll<HTMLElement>('[data-repeat]').forEach((proto) => {
        const name = proto.dataset.repeat;
        const parent = proto.parentElement;
        if (!name || !parent) return;

        const raw = content.value[name];
        const items = Array.isArray(raw) ? raw.filter(hasAnyValue) : [];

        items.forEach((item) => {
            const clone = proto.cloneNode(true) as HTMLElement;
            clone.removeAttribute('data-repeat');
            applySlots(clone, name, item);
            parent.insertBefore(clone, proto);
        });

        proto.remove();
    });
}

function hasAnyValue(item: FlyerListItem): boolean {
    return Object.values(item ?? {}).some((value) => !!String(value ?? '').trim());
}

/**
 * Fill every `data-slot` in scope. Inside a repeat clone the slots are namespaced
 * (`sessions.time`), so a prefixed pass takes those and the top-level pass skips them.
 */
function applySlots(scope: HTMLElement, prefix: string | null, values: Record<string, unknown>): void {
    scope.querySelectorAll<HTMLElement>('[data-slot]').forEach((el) => {
        const declared = el.dataset.slot ?? '';
        let key = declared;

        if (prefix) {
            if (!declared.startsWith(`${prefix}.`)) return;
            key = declared.slice(prefix.length + 1);
        } else if (declared.includes('.')) {
            return;
        }

        fillSlot(el, key, values[key], prefix);
    });
}

function slotByName(name: string): FlyerSlot | undefined {
    return manifest.value.slots.find((slot) => slot.name === name);
}

function fillSlot(el: HTMLElement, key: string, value: unknown, prefix: string | null): void {
    const slot = prefix ? undefined : slotByName(key);
    const optional = el.dataset.optional === 'true';
    const text = typeof value === 'string' ? value.trim() : '';
    const image = imageTarget(el);

    if (image) {
        if (text) {
            image.setAttribute('src', text);
            return;
        }

        // Without this an empty optional slot leaves its margins behind.
        if (optional || !placeholders.value) {
            el.remove();
            return;
        }

        el.replaceWith(imagePlaceholder(el, slot));
        return;
    }

    if (!text) {
        if (optional) {
            el.remove();
            return;
        }

        if (placeholders.value && slot) {
            el.textContent = slot.label;
            el.setAttribute('data-flyer-placeholder', 'text');
            el.style.opacity = '0.35';
        } else {
            el.textContent = '';
        }

        return;
    }

    setText(el, text);
}

/** An image slot is either the <img> itself or a wrapper around one (event-banner's art). */
function imageTarget(el: HTMLElement): HTMLImageElement | null {
    if (el instanceof HTMLImageElement) return el;
    return el.querySelector('img');
}

/**
 * Values go in as TEXT NODES, never as markup: the templates are trusted, the content
 * is not. Newlines become real <br> elements because none of the templates set
 * `white-space: pre-line` — a textarea's line breaks would otherwise vanish.
 */
function setText(el: HTMLElement, value: string): void {
    el.textContent = '';

    value.split(/\r?\n/).forEach((line, index) => {
        if (index > 0) el.appendChild(document.createElement('br'));
        el.appendChild(document.createTextNode(line));
    });
}

/**
 * A dashed stand-in for a photo that has not been chosen yet. It keeps the replaced
 * element's classes so the design still decides where and how big the box is, and it
 * is marked so the exporter can drop it.
 */
function imagePlaceholder(el: HTMLElement, slot: FlyerSlot | undefined): HTMLElement {
    const box = document.createElement('div');
    box.className = el.className;
    box.setAttribute('data-flyer-placeholder', 'image');
    box.style.cssText = [
        'width:100%',
        'height:100%',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        'border:6px dashed currentColor',
        'border-radius:16px',
        'opacity:0.3',
        'font-size:36px',
        'font-weight:500',
        'text-align:center'
    ].join(';');
    box.textContent = slot ? slot.label : 'Image';

    return box;
}

/** Belt and braces: no `{{ placeholder }}` ever reaches a preview, let alone an export. */
function stripUnfilled(root: HTMLElement): void {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

    while (walker.nextNode()) {
        const node = walker.currentNode;
        const value = node.nodeValue ?? '';
        if (value.includes('{{')) node.nodeValue = value.replace(/\{\{\s*[\w.]+\s*\}\}/g, '').trim();
    }

    root.querySelectorAll('img[src*="{{"]').forEach((img) => img.remove());
}

/**
 * Step a long line down through its `size_var` until it fits.
 *
 * The bands of the measured food layout are ink extents, not hard boxes — text centres
 * in its band and is allowed to bleed into the gap rather than reflow — so height
 * alone is not a failure. A line too wide for its band always is.
 */
function fitText(root: HTMLElement): void {
    manifest.value.slots
        .filter((slot) => !!slot.size_var)
        .forEach((slot) => {
            const variable = slot.size_var as string;
            root.style.removeProperty(variable);

            const el = root.querySelector<HTMLElement>(`[data-slot="${slot.name}"]`);
            const container = el?.closest<HTMLElement>('.zone') ?? el?.parentElement ?? null;
            if (!el || !container) return;

            const base = parseFloat(window.getComputedStyle(el).fontSize);
            if (!base || Number.isNaN(base)) return;

            let size = base;

            for (let step = 0; step < FIT_STEPS; step += 1) {
                if (!overflows(el, container)) break;

                size = Math.max(size * FIT_RATIO, base * FIT_FLOOR);
                root.style.setProperty(variable, `${size}px`);

                if (size <= base * FIT_FLOOR) break;
            }
        });
}

function overflows(el: HTMLElement, container: HTMLElement): boolean {
    if (el.scrollWidth > container.clientWidth + 1) return true;

    return el.scrollHeight > container.clientHeight * 1.15;
}

// ---------------------------------------------------------------------- export

/**
 * Rasterise the flyer at the requested size.
 *
 * The live DOM is wrapped in an SVG <foreignObject> and handed back to the browser as
 * an image, so the BROWSER paints it: `-webkit-text-stroke` and `paint-order` are
 * engine features, and an exporter that re-implements CSS painting drops the sticker
 * outline — the single most recognisable thing about these flyers.
 *
 * The SVG is emitted at the EXPORT size with the canvas scaled into it, so the
 * rasteriser works at full resolution. Rendering at 1200x1600 and letting the canvas
 * resample would post a soft flyer, which is the whole failure this avoids.
 */
async function toBlob(size: FlyerExportSize): Promise<Blob> {
    const fontCss = await exportFontCss();
    const markup = exportMarkup();
    const ratio = Math.min(size.width / canvas.width, size.height / canvas.height);
    const dx = (size.width - canvas.width * ratio) / 2;
    const dy = (size.height - canvas.height * ratio) / 2;

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size.width}" height="${size.height}" `
        + `viewBox="0 0 ${size.width} ${size.height}">`
        + `<g transform="translate(${dx} ${dy}) scale(${ratio})">`
        + `<foreignObject x="0" y="0" width="${canvas.width}" height="${canvas.height}">`
        + `<div xmlns="http://www.w3.org/1999/xhtml">${fontCss}${markup}</div>`
        + `</foreignObject></g></svg>`;

    const image = await loadImage(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`);

    const target = document.createElement('canvas');
    target.width = size.width;
    target.height = size.height;

    const ctx = target.getContext('2d');
    if (!ctx) throw new Error('This browser cannot export images.');

    ctx.imageSmoothingQuality = 'high';
    paintBleed(ctx, size);
    ctx.drawImage(image, 0, 0, size.width, size.height);

    return await new Promise<Blob>((resolve, reject) => {
        target.toBlob(
            (blob) => blob ? resolve(blob) : reject(new Error('The flyer could not be turned into a PNG.')),
            'image/png'
        );
    });
}

/**
 * The stylesheet and the filled markup, serialised as XML.
 *
 * Serialised from the live nodes rather than re-rendered, so the export is exactly
 * what was on screen — including whatever the fit pass decided. XMLSerializer escapes
 * as it goes, which is what makes the result well-formed enough to parse back as XHTML.
 */
function exportMarkup(): string {
    const host = hostRef.value;
    if (!host) throw new Error('There is nothing to export yet.');

    const clone = host.cloneNode(true) as HTMLElement;
    clone.querySelectorAll('[data-flyer-placeholder]').forEach((el) => el.remove());

    const serializer = new XMLSerializer();

    return Array.from(clone.childNodes)
        .map((node) => serializer.serializeToString(node))
        .join('');
}

/**
 * The flyer face, carried into the export as data.
 *
 * An SVG handed to the browser as an <img> src is rasterised in an ISOLATED document:
 * it cannot see this page's stylesheets and is not allowed to fetch anything of its
 * own. A self-hosted @font-face is therefore invisible to it, and the flyer comes out
 * in Helvetica however perfectly the font loaded on the page — which is the failure the
 * Studio's readiness guard would otherwise be unable to see.
 *
 * So the rules are read back out of the CSSOM and their files embedded as data URIs.
 * Read from the document rather than hardcoded: where the font lives, and whether it is
 * one variable file or four static ones, belongs to the app and not to the renderer.
 *
 * Nothing found is a legitimate answer, not a failure — a page whose Montserrat comes
 * from the operating system needs no help, because the rasteriser can resolve an
 * installed font by name on its own.
 */
async function exportFontCss(): Promise<string> {
    if (fontCssCache !== null) return fontCssCache;

    const rules = flyerFontFaceRules();

    if (!rules.length) {
        fontCssCache = '';
        return fontCssCache;
    }

    const embedded: string[] = [];
    for (const rule of rules) {
        embedded.push(await embedFontSources(rule));
    }

    fontCssCache = `<style>${xmlSafe(embedded.join(''))}</style>`;

    return fontCssCache;
}

/** Every @font-face the flyer face is declared by, wherever the app put it. */
function flyerFontFaceRules(): CSSFontFaceRule[] {
    const found: CSSFontFaceRule[] = [];

    Array.from(document.styleSheets).forEach((sheet) => {
        let rules: CSSRuleList;

        try {
            rules = sheet.cssRules;
        } catch {
            // A cross-origin stylesheet cannot be read at all. Nothing to do about it.
            return;
        }

        Array.from(rules).forEach((rule) => {
            if (!(rule instanceof CSSFontFaceRule)) return;
            if (!rule.style.getPropertyValue('font-family').includes(FLYER_FONT_FAMILY)) return;

            found.push(rule);
        });
    });

    return found;
}

/**
 * One rule with every url() it points at turned into a data: URI. A source that cannot
 * be embedded is fatal to the export rather than quietly skipped: the PNG that came out
 * of it would be in the wrong face, and look almost right.
 */
async function embedFontSources(rule: CSSFontFaceRule): Promise<string> {
    let css = rule.cssText;

    for (const match of Array.from(rule.cssText.matchAll(/url\((["']?)([^"')]+)\1\)/g))) {
        const href = match[2];
        if (href.startsWith('data:')) continue;

        css = css.replace(match[0], `url("${await fileAsDataUrl(href)}")`);
    }

    return css;
}

async function fileAsDataUrl(href: string): Promise<string> {
    const failed = new Error('The flyer font could not be added to the export. Reload the page and try again.');

    const res = await fetch(href).catch(() => { throw failed; });
    if (!res.ok) throw failed;

    const blob = await res.blob();

    return await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(failed);
        reader.readAsDataURL(blob);
    });
}

/** The SVG is parsed as XML, so a stylesheet goes in as text and not as markup. */
function xmlSafe(css: string): string {
    return css.replace(/&/g, '&amp;').replace(/</g, '&lt;');
}

/**
 * Fill the canvas before the flyer lands on it. At 4:5 the 3:4 design is scaled to fit
 * and leaves a strip down each side; filling it from the palette continues the ground
 * rather than framing the flyer in white. For the gradient templates the stops line up
 * exactly, because the flyer's height still spans the whole canvas.
 */
function paintBleed(ctx: CanvasRenderingContext2D, size: FlyerExportSize): void {
    const vars = cssVars.value;
    const ground = manifest.value.palette?.ground;

    if (ground === 'gradient') {
        const gradient = ctx.createLinearGradient(0, 0, 0, size.height);
        gradient.addColorStop(0, vars['--flyer-grad-0']);
        gradient.addColorStop(0.5, vars['--flyer-grad-1']);
        gradient.addColorStop(0.8, vars['--flyer-grad-2']);
        gradient.addColorStop(1, vars['--flyer-grad-3']);
        ctx.fillStyle = gradient;
    } else if (ground === 'field') {
        ctx.fillStyle = vars['--flyer-field'];
    } else if (ground === 'solid') {
        ctx.fillStyle = vars['--flyer-ground'];
    } else {
        // event-photo: the ground is a user-supplied picture that cannot be sampled
        // ahead of time, and its scrim is nearly black at the edges anyway.
        ctx.fillStyle = '#000000';
    }

    ctx.fillRect(0, 0, size.width, size.height);
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('The flyer could not be rendered for export.'));
        image.src = src;
    });
}

/** Download only — nothing here posts anywhere. */
async function download(size: FlyerExportSize, filename: string): Promise<void> {
    const blob = await toBlob(size);
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    // Revoked on a later task, never in line with the click: a browser that starts the
    // download asynchronously is still holding this URL when the synchronous revoke
    // lands, and cancels a flyer that looked like it was on its way to the Downloads
    // folder. Holding a few megabytes for a moment is the cheaper mistake.
    setTimeout(() => URL.revokeObjectURL(url), OBJECT_URL_TTL_MS);
}

defineExpose({ toBlob, download });
</script>

<style scoped>
.flyer-viewport {
    position: relative;
    width: 100%;
    overflow: hidden;
    border-radius: 10px;
    background: #f4f5f7;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
}

/* The stage carries the preview scale so the flyer itself keeps its true size. */
.flyer-stage {
    position: absolute;
    top: 0;
    left: 0;
    transform-origin: top left;
}
</style>
