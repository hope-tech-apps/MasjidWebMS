/**
 * Get a photo ready to send: shrink it and strip its metadata.
 *
 * Every photo is redrawn onto a canvas and re-encoded as a JPEG no larger than
 * MAX_EDGE pixels on its long side. Two reasons, both about the people in it:
 *
 *  - **Location.** A phone photo carries EXIF, often including where it was
 *    taken. Redrawing keeps the pixels and drops everything else, so a picture
 *    of a child never tells a family (or anyone they forward it to) where the
 *    teacher was standing.
 *  - **Size.** A camera original can be 5–12MB. The server refuses a request
 *    over 25MB outright (nginx) and a photo over 8MB by rule, so several
 *    originals at once would fail. At 2048px a photo is typically well under
 *    1MB and still sharp on any screen.
 *
 * The browser applies the photo's EXIF orientation while decoding, so a portrait
 * shot stays upright even though the orientation tag itself is dropped.
 *
 * If the browser cannot decode the file (for example a HEIC photo in Chrome),
 * the original is returned untouched and the server's own type check decides —
 * this is an improvement on the way out, never a second gatekeeper.
 */

const MAX_EDGE = 2048;
const QUALITY = 0.85;

async function decode(file: File): Promise<ImageBitmap> {
    try {
        return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        // Older engines reject the options bag; their default is the same.
        return await createImageBitmap(file);
    }
}

export async function preparePhoto(file: File): Promise<File> {
    if (typeof createImageBitmap !== 'function' || !file.type.startsWith('image/')) {
        return file;
    }

    let bitmap: ImageBitmap;
    try {
        bitmap = await decode(file);
    } catch {
        return file;
    }

    try {
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d');
        if (!context) return file;

        // JPEG has no transparency: a PNG with a clear background would turn
        // black without this.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
        if (!blob) return file;

        const stem = file.name.replace(/\.[^.]+$/, '') || 'photo';
        return new File([blob], `${stem}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
    } catch {
        return file;
    } finally {
        bitmap.close();
    }
}
