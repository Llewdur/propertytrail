/** Enough to see cracks and damp, at a fraction of a phone camera's file size. */
export const MAX_EDGE = 2560;
export const JPEG_QUALITY = 0.8;

const ORIENTATION_TAG = 0x0112;

/**
 * Shrinks a photo on the phone before it is queued or uploaded. The result is
 * what gets stored and hashed. A file the browser cannot decode goes unchanged.
 */
export async function compressImage(file: File): Promise<File> {
    if (typeof createImageBitmap !== 'function') {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const context = canvas.getContext('2d');

        if (context === null) {
            return file;
        }

        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));

        if (blob === null || (scale === 1 && blob.size >= file.size)) {
            return file;
        }

        const exif = exifSegment(new Uint8Array(await file.arrayBuffer()));
        // Redrawing drops EXIF, so the capture time, location and direction are carried across.
        const parts: BlobPart[] = exif === null ? [blob] : [blob.slice(0, 2), exif, blob.slice(2)];

        return new File(parts, `${file.name.replace(/\.[^.]*$/, '') || 'photo'}.jpg`, {
            type: 'image/jpeg',
            lastModified: file.lastModified,
        });
    } catch {
        return file;
    }
}

/** The EXIF block of a JPEG, with its orientation reset because the pixels are now upright. */
export function exifSegment(bytes: Uint8Array<ArrayBuffer>): Uint8Array<ArrayBuffer> | null {
    if (bytes.length < 4 || bytes[0] !== 0xff || bytes[1] !== 0xd8) {
        return null;
    }

    let offset = 2;

    while (offset + 4 <= bytes.length && bytes[offset] === 0xff && bytes[offset + 1] !== 0xda) {
        const end = offset + 2 + ((bytes[offset + 2] << 8) | bytes[offset + 3]);

        if (end > bytes.length) {
            return null;
        }

        if (bytes[offset + 1] === 0xe1 && String.fromCharCode(...bytes.slice(offset + 4, offset + 8)) === 'Exif') {
            const segment = bytes.slice(offset, end);
            resetOrientation(segment);

            return segment;
        }

        offset = end;
    }

    return null;
}

function resetOrientation(segment: Uint8Array<ArrayBuffer>): void {
    // Marker (2), length (2) and "Exif\0\0" (6) come before the TIFF header.
    const tiff = 10;

    if (segment.length < tiff + 8) {
        return;
    }

    const view = new DataView(segment.buffer, segment.byteOffset, segment.byteLength);
    const little = view.getUint16(tiff) === 0x4949;
    const directory = tiff + view.getUint32(tiff + 4, little);

    if (directory + 2 > segment.length) {
        return;
    }

    const entries = view.getUint16(directory, little);

    for (let index = 0; index < entries; index += 1) {
        const entry = directory + 2 + index * 12;

        if (entry + 12 > segment.length) {
            return;
        }

        if (view.getUint16(entry, little) === ORIENTATION_TAG) {
            view.setUint16(entry + 8, 1, little);
        }
    }
}
