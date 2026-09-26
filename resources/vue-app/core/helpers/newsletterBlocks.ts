/**
 * The newsletter layout editor's rules, as pure functions tests/newsletter-blocks.test.ts
 * proves. NewsletterEditor.vue and BroadcastComposerView.vue only wire them up.
 *
 * The SERVER owns the layout: it validates it (NewsletterBlocks::errors), sanitises the
 * rich text, stores it and renders the email — including the live preview, which is the
 * real BroadcastMail rendered by POST /broadcasts/preview. Nothing here re-implements
 * the email's markup; that is what keeps the preview from drifting from the inbox.
 * What lives here is only what the browser alone can know: the order the admin is
 * arranging, which local file each picture is, and how to put those files into the
 * preview and the send.
 *
 *   newBlock / moveBlock / removeBlock   the editing operations, never mutating in place
 *   toPayload                            the layout as the server accepts it (no client ids)
 *   imageKeysInUse                       which uploaded files the send must carry
 *   appendNewsletter                     the multipart fields: `blocks` JSON + block_images[key]
 *   withLocalImages                      the server's preview HTML with each reserved
 *                                        preview.invalid address swapped for the admin's
 *                                        own copy of that picture
 *   previewSize / previewCopy            that copy, scaled down: the preview never carries
 *                                        the full upload
 *   isLinkAddress                        what the rich-text link dialog accepts
 */

export type NewsletterBlockType = 'heading' | 'text' | 'image' | 'button' | 'divider' | 'image_row' | 'spacer'

export type Align = 'left' | 'center'

export type SpacerSize = 'small' | 'medium' | 'large'

export interface NewsletterImage {
    /** Upload key: the file travels as block_images[key]. */
    image: string;
    alt: string;
    link: string;
}

export type NewsletterBlock =
    | { type: 'heading'; text: string; align: Align }
    | { type: 'text'; html: string }
    | ({ type: 'image' } & NewsletterImage)
    | { type: 'button'; label: string; url: string; align: Align }
    | { type: 'divider' }
    | { type: 'image_row'; images: [NewsletterImage, NewsletterImage] }
    | { type: 'spacer'; size: SpacerSize }

/** A block in the editor: the stored shape plus a stable id for Vue's :key. */
export type EditorBlock = NewsletterBlock & { uid: string }

/** Mirrors NewsletterPreviewMail::ORIGIN. RFC 2606 reserved: it never resolves. */
export const PREVIEW_ORIGIN = 'https://preview.invalid'

/** Mirrors NewsletterBlocks::MAX_BLOCKS / MAX_IMAGES / MAX_IMAGE_KB. The server enforces them. */
export const MAX_BLOCKS = 40
export const MAX_IMAGES = 10
export const MAX_IMAGE_BYTES = 8192 * 1024

export const BLOCK_LABELS: Record<NewsletterBlockType, string> = {
    heading: 'Heading',
    text: 'Text',
    image: 'Picture',
    button: 'Button',
    divider: 'Divider',
    image_row: 'Two pictures',
    spacer: 'Space',
}

/**
 * A fresh block. `nextKey` mints upload keys, so every picture slot has its own key
 * from the moment it exists — a key is what ties a local file to its block, and two
 * slots sharing one would send the same file twice.
 */
export const newBlock = (type: NewsletterBlockType, uid: string, nextKey: () => string): EditorBlock => {
    const image = (): NewsletterImage => ({ image: nextKey(), alt: '', link: '' })

    switch (type) {
        case 'heading': return { uid, type, text: '', align: 'left' }
        case 'text': return { uid, type, html: '' }
        case 'image': return { uid, type, ...image() }
        case 'button': return { uid, type, label: '', url: '', align: 'center' }
        case 'divider': return { uid, type }
        case 'image_row': return { uid, type, images: [image(), image()] }
        case 'spacer': return { uid, type, size: 'medium' }
    }
}

/** The list with the block at `from` moved to `to`; out-of-range moves return the list unchanged. */
export const moveBlock = <T>(blocks: readonly T[], from: number, to: number): T[] => {
    if (from < 0 || from >= blocks.length || to < 0 || to >= blocks.length || from === to) {
        return [...blocks]
    }
    const next = [...blocks]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    return next
}

export const removeBlock = <T>(blocks: readonly T[], index: number): T[] =>
    blocks.filter((_, i) => i !== index)

/** The layout as the server accepts it: the stored shape, client ids stripped. */
export const toPayload = (blocks: readonly EditorBlock[]): NewsletterBlock[] =>
    blocks.map(({ uid: _uid, ...block }) => JSON.parse(JSON.stringify(block)) as NewsletterBlock)

/** Every upload key the layout references, in order, once each. */
export const imageKeysInUse = (blocks: readonly NewsletterBlock[]): string[] => {
    const keys: string[] = []
    for (const block of blocks) {
        const images = block.type === 'image' ? [block] : block.type === 'image_row' ? block.images : []
        for (const image of images) {
            if (image.image && !keys.includes(image.image)) keys.push(image.image)
        }
    }
    return keys
}

/** What FormData needs: an append. Typed loosely so the tests can pass a recorder. */
export interface AppendsFields {
    append(name: string, value: string | Blob, fileName?: string): void
}

/**
 * Add the layout to a send. Only files a block still references travel: a picture
 * chosen and then deleted from the layout is not uploaded. Nothing is added for an
 * empty layout, so a send without one is byte-for-byte the request it always was.
 */
export const appendNewsletter = (fd: AppendsFields, blocks: readonly EditorBlock[], files: Readonly<Record<string, File | Blob | undefined>>): void => {
    if (blocks.length === 0) return

    const payload = toPayload(blocks)
    fd.append('blocks', JSON.stringify(payload))

    for (const key of imageKeysInUse(payload)) {
        const file = files[key]
        if (file) fd.append(`block_images[${key}]`, file)
    }
}

/**
 * The server's preview HTML with each reserved preview address replaced by the
 * admin's local copy of that picture (a data: URL), and the composer image likewise.
 * An address with no local file is left pointing at `.invalid`, which renders as a
 * missing picture — exactly what the admin needs to see before sending.
 */
export const withLocalImages = (html: string, localByKey: Readonly<Record<string, string | undefined>>, composerImage?: string): string => {
    let out = html.split(`${PREVIEW_ORIGIN}/newsletter-image/`).map((part, i) => {
        if (i === 0) return part
        const match = /^([a-z0-9][a-z0-9_-]{0,39})"/.exec(part)
        const local = match ? localByKey[match[1]] : undefined
        return local && isDataImage(local)
            ? local + part.slice(match![1].length)
            : `${PREVIEW_ORIGIN}/newsletter-image/` + part
    }).join('')

    if (composerImage && isDataImage(composerImage)) {
        out = out.split(`${PREVIEW_ORIGIN}/composer-image"`).join(`${composerImage}"`)
    }

    return out
}

/**
 * Only an image data: URL is ever written into the preview document — never an
 * arbitrary string that could close the attribute. Base64 has no quote in its alphabet.
 */
const isDataImage = (value: string): boolean => /^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$/.test(value)

/** An upload key that cannot collide within one composer session. */
export const keyMinter = (prefix = 'img'): (() => string) => {
    let n = 0
    return () => `${prefix}-${++n}`
}

/**
 * The widest the admin's own preview copy of a picture is kept: twice the email's
 * 552px column, like NewsletterPicture::MAX_WIDTH on the server.
 */
export const PREVIEW_MAX_WIDTH = 1104

/**
 * A picture that cannot be scaled in the browser is shown from its original bytes
 * only up to this size; above it the preview shows it as missing rather than paste
 * megabytes of base64 into the frame on every refresh.
 */
export const PREVIEW_FALLBACK_MAX_BYTES = 1024 * 1024

/** The size the preview copy is drawn at: never wider than `max`, never enlarged. */
export const previewSize = (width: number, height: number, max = PREVIEW_MAX_WIDTH): { width: number; height: number } =>
    width <= max
        ? { width, height }
        : { width: max, height: Math.max(1, Math.round(height * max / width)) }

/**
 * The admin's own copy of a picture, for the thumbnail and the preview: drawn at
 * previewSize onto a white canvas (the email card's colour, since JPEG has no
 * transparency) and encoded as JPEG at 0.8. The preview frame's srcdoc carries this
 * copy on every refresh, so it must not be the 8 MB upload: ten of those would be
 * over 100 MB of base64, re-decoded after every keystroke. The upload itself still
 * sends the original file; the server makes its own copy for the email.
 */
export const previewCopy = async (file: Blob): Promise<string | undefined> => {
    try {
        const bitmap = await createImageBitmap(file)
        const { width, height } = previewSize(bitmap.width, bitmap.height)
        const canvas = document.createElement('canvas')
        canvas.width = width
        canvas.height = height
        const context = canvas.getContext('2d')
        if (!context) throw new Error('no 2d context')
        context.fillStyle = '#ffffff'
        context.fillRect(0, 0, width, height)
        context.drawImage(bitmap, 0, 0, width, height)
        bitmap.close()
        return canvas.toDataURL('image/jpeg', 0.8)
    } catch {
        return file.size <= PREVIEW_FALLBACK_MAX_BYTES ? readAsDataUrl(file) : undefined
    }
}

const readAsDataUrl = (file: Blob): Promise<string | undefined> => new Promise(resolve => {
    const reader = new FileReader()
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : undefined)
    reader.onerror = () => resolve(undefined)
    reader.readAsDataURL(file)
})

/**
 * What the rich-text box's link dialog accepts: a full web address or a mail
 * address. The server's RichText::safeHref is the boundary; this keeps the admin
 * from making a link the email would silently turn back into plain words.
 */
export const isLinkAddress = (value: string): boolean => /^(https?:\/\/\S+|mailto:\S+@\S+)$/i.test(value.trim())
