export type DraftItem = {
    id: number | null;
    label: string;
    condition: string | null;
    note: string | null;
};

export type RoomDraft = {
    inspectionId: number;
    sectionId: number;
    items: DraftItem[];
    updatedAt: string;
};

export type PendingPhoto = {
    id: string;
    inspectionId: number;
    itemId: number;
    file: Blob;
    fileName: string;
    label: string | null;
    replacesLinkId: number | null;
    attempts: number;
    lastError: string | null;
    createdAt: string;
};

export type PendingSubmission = {
    inspectionId: number;
    token: string;
    attempts: number;
    lastError: string | null;
    createdAt: string;
};

export type SyncSummary = {
    photos: number;
    submissions: number;
    drafts: number;
};

export type SyncResult = {
    photosUploaded: number;
    submitted: number[];
    errors: string[];
};

type StoreName = 'photos' | 'submissions' | 'drafts' | 'captures';

type OfflineBackend = {
    put(store: StoreName, key: string, value: unknown): Promise<void>;
    get<T>(store: StoreName, key: string): Promise<T | undefined>;
    all<T>(store: StoreName): Promise<T[]>;
    remove(store: StoreName, key: string): Promise<void>;
};

const DATABASE_NAME = 'propertytrail-offline';
const DATABASE_VERSION = 2;
const STORE_NAMES: StoreName[] = ['photos', 'submissions', 'drafts', 'captures'];

const memory = new Map<StoreName, Map<string, unknown>>();

const memoryBackend: OfflineBackend = {
    async put(store, key, value) {
        const bucket = memory.get(store) ?? new Map<string, unknown>();
        bucket.set(key, value);
        memory.set(store, bucket);
    },
    async get<T>(store: StoreName, key: string): Promise<T | undefined> {
        return memory.get(store)?.get(key) as T | undefined;
    },
    async all<T>(store: StoreName): Promise<T[]> {
        return Array.from(memory.get(store)?.values() ?? []) as T[];
    },
    async remove(store, key) {
        memory.get(store)?.delete(key);
    },
};

let databasePromise: Promise<IDBDatabase> | null = null;

function openDatabase(): Promise<IDBDatabase> {
    if (databasePromise === null) {
        databasePromise = new Promise<IDBDatabase>((resolve, reject) => {
            const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION);

            request.onupgradeneeded = () => {
                const database = request.result;

                for (const name of STORE_NAMES) {
                    if (!database.objectStoreNames.contains(name)) {
                        database.createObjectStore(name);
                    }
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        }).catch((error: unknown) => {
            databasePromise = null;
            throw error;
        });
    }

    return databasePromise;
}

function runTransaction<T>(
    store: StoreName,
    mode: IDBTransactionMode,
    operation: (objectStore: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    return openDatabase().then(
        (database) =>
            new Promise<T>((resolve, reject) => {
                const transaction = database.transaction(store, mode);
                const request = operation(transaction.objectStore(store));

                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error);
            }),
    );
}

const indexedDbBackend: OfflineBackend = {
    async put(store, key, value) {
        await runTransaction(store, 'readwrite', (objectStore) => objectStore.put(value, key));
    },
    async get<T>(store: StoreName, key: string): Promise<T | undefined> {
        return await runTransaction<T | undefined>(store, 'readonly', (objectStore) => objectStore.get(key));
    },
    async all<T>(store: StoreName): Promise<T[]> {
        return await runTransaction<T[]>(store, 'readonly', (objectStore) => objectStore.getAll());
    },
    async remove(store, key) {
        await runTransaction(store, 'readwrite', (objectStore) => objectStore.delete(key));
    },
};

const backend: OfflineBackend = typeof indexedDB === 'undefined' ? memoryBackend : indexedDbBackend;

/** A tenant photo from a capture link. The link token is deliberately not stored with it. */
export type PendingCapture = {
    id: string;
    captureKey: string;
    /** Null for a room the tenant named that the server does not know yet. */
    areaId: number | null;
    roomKey?: string | null;
    roomName?: string;
    roomOutdoor?: boolean;
    adjoinsId?: number | null;
    adjoinsKey?: string | null;
    corner?: number | null;
    shot?: string;
    /** True while the tenant may still retake it; a held photo is not uploaded. */
    held?: boolean;
    /** Set on a close-up of a reported problem. */
    issueKey?: string | null;
    /** A rating or a reported problem. An entry has either this or a photo. */
    note?: CaptureNote;
    file?: Blob;
    fileName?: string;
    createdAt: string;
};

export type CaptureNote =
    | { condition: string }
    | { issueKey: string; issueKind: string; issueNote: string };

export async function queueCapture(input: Omit<PendingCapture, 'id' | 'createdAt'>): Promise<string> {
    const capture: PendingCapture = { ...input, id: newId(), createdAt: new Date().toISOString() };

    await backend.put('captures', capture.id, capture);

    return capture.id;
}

/** Lets held photos upload: one of them, or all left over from a closed tab. */
export async function releaseCaptures(captureKey: string, id?: string): Promise<void> {
    for (const capture of await queuedCaptures(captureKey)) {
        if (capture.held === true && (id === undefined || capture.id === id)) {
            await backend.put('captures', capture.id, { ...capture, held: false });
        }
    }
}

export async function dropCapture(id: string): Promise<void> {
    await backend.remove('captures', id);
}

export async function queuedCaptures(captureKey: string): Promise<PendingCapture[]> {
    const captures = await backend.all<PendingCapture>('captures');

    return captures
        .filter((capture) => capture.captureKey === captureKey)
        .sort((left, right) => left.createdAt.localeCompare(right.createdAt));
}

/**
 * Uploads what is waiting. A photo the server will never accept is dropped with
 * its reason; one that is too large for the server stays, so it is not lost;
 * anything else (offline, busy, link expired) stays for a retry.
 */
export async function flushCaptures(apiBase: string, token: string, captureKey: string): Promise<{ uploaded: number; errors: string[] }> {
    const result = { uploaded: 0, errors: [] as string[] };

    for (const capture of await queuedCaptures(captureKey)) {
        if (capture.held === true) {
            continue;
        }

        const form = captureForm(capture);

        let response: Response;

        try {
            response = await fetch(`${apiBase}/capture/${capture.note === undefined ? 'photos' : 'notes'}`, {
                method: 'POST',
                credentials: 'omit',
                headers: { Accept: 'application/json', 'X-Capture-Token': token },
                body: form,
            });
        } catch {
            break;
        }

        if (response.ok) {
            await backend.remove('captures', capture.id);
            result.uploaded += 1;
            continue;
        }

        if (response.status === 413) {
            result.errors.push('A photo is too large to upload. It is still saved on this phone.');
            continue;
        }

        const fallback = capture.note === undefined ? 'A photo could not be uploaded.' : 'An answer could not be saved.';
        result.errors.push(await responseFailure(response, fallback));

        if (response.status === 422) {
            await backend.remove('captures', capture.id);
            continue;
        }

        break;
    }

    return result;
}

function captureForm(capture: PendingCapture): FormData {
    const form = new FormData();

    if (capture.file !== undefined) {
        form.append('file', capture.file, capture.fileName);
    }

    form.append('taken_at', capture.createdAt);

    const room: Record<string, string | number | null | undefined> =
        capture.areaId !== null
            ? { area_room_id: capture.areaId }
            : {
                  room_key: capture.roomKey,
                  room_name: capture.roomName,
                  room_outdoor: capture.roomOutdoor === true ? 1 : 0,
              };
    const fields = {
        ...room,
        adjoins_area_room_id: capture.adjoinsId,
        adjoins_room_key: capture.adjoinsKey,
        corner: capture.corner,
        shot: capture.shot,
        ...noteFields(capture),
    };

    for (const [name, value] of Object.entries(fields)) {
        if (value !== null && value !== undefined) {
            form.append(name, String(value));
        }
    }

    return form;
}

function noteFields(capture: PendingCapture): Record<string, string | null | undefined> {
    const note = capture.note;

    if (note === undefined) {
        return { issue_key: capture.issueKey };
    }

    return 'condition' in note
        ? { condition: note.condition }
        : { issue_key: note.issueKey, issue_kind: note.issueKind, issue_note: note.issueNote };
}

function newId(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export async function resetOfflineStore(): Promise<void> {
    memory.clear();

    if (backend === indexedDbBackend) {
        const database = await openDatabase();
        await Promise.all(
            STORE_NAMES.map(
                (name) =>
                    new Promise<void>((resolve, reject) => {
                        const transaction = database.transaction(name, 'readwrite');
                        transaction.objectStore(name).clear();
                        transaction.oncomplete = () => resolve();
                        transaction.onerror = () => reject(transaction.error);
                    }),
            ),
        );
    }
}

export async function queuePhoto(input: {
    inspectionId: number;
    itemId: number;
    file: Blob;
    fileName: string;
    label: string | null;
    replacesLinkId: number | null;
}): Promise<PendingPhoto> {
    const photo: PendingPhoto = {
        id: newId(),
        inspectionId: input.inspectionId,
        itemId: input.itemId,
        file: input.file,
        fileName: input.fileName,
        label: input.label,
        replacesLinkId: input.replacesLinkId,
        attempts: 0,
        lastError: null,
        createdAt: new Date().toISOString(),
    };

    await backend.put('photos', photo.id, photo);

    return photo;
}

export async function queuedPhotos(inspectionId?: number): Promise<PendingPhoto[]> {
    const photos = await backend.all<PendingPhoto>('photos');

    return photos
        .filter((photo) => inspectionId === undefined || photo.inspectionId === inspectionId)
        .sort((left, right) => left.createdAt.localeCompare(right.createdAt));
}

export async function dropPhoto(id: string): Promise<void> {
    await backend.remove('photos', id);
}

async function patchPhoto(id: string, patch: Partial<PendingPhoto>): Promise<void> {
    const photo = await backend.get<PendingPhoto>('photos', id);

    if (photo !== undefined) {
        await backend.put('photos', id, { ...photo, ...patch, attempts: photo.attempts + 1 });
    }
}

export async function notePhotoFailure(id: string, message: string): Promise<void> {
    await patchPhoto(id, { lastError: message });
}

const submissionLocks = new Map<number, Promise<PendingSubmission>>();

export function queueSubmission(inspectionId: number): Promise<PendingSubmission> {
    const inFlight = submissionLocks.get(inspectionId);

    if (inFlight !== undefined) {
        return inFlight;
    }

    const task = (async (): Promise<PendingSubmission> => {
        const key = String(inspectionId);
        const existing = await backend.get<PendingSubmission>('submissions', key);

        if (existing !== undefined) {
            return existing;
        }

        const submission: PendingSubmission = {
            inspectionId,
            token: newId(),
            attempts: 0,
            lastError: null,
            createdAt: new Date().toISOString(),
        };
        await backend.put('submissions', key, submission);

        return submission;
    })();

    submissionLocks.set(inspectionId, task);
    void task.then(
        () => submissionLocks.delete(inspectionId),
        () => submissionLocks.delete(inspectionId),
    );

    return task;
}

export async function queuedSubmissions(): Promise<PendingSubmission[]> {
    const submissions = await backend.all<PendingSubmission>('submissions');

    return submissions.sort((left, right) => left.createdAt.localeCompare(right.createdAt));
}

export async function dropSubmission(inspectionId: number): Promise<void> {
    await backend.remove('submissions', String(inspectionId));
}

async function noteSubmissionFailure(inspectionId: number, message: string): Promise<void> {
    const submission = await backend.get<PendingSubmission>('submissions', String(inspectionId));

    if (submission !== undefined) {
        await backend.put('submissions', String(inspectionId), {
            ...submission,
            lastError: message,
            attempts: submission.attempts + 1,
        });
    }
}

export async function saveRoomDraft(draft: RoomDraft): Promise<void> {
    await backend.put('drafts', `${draft.inspectionId}:${draft.sectionId}`, draft);
}

export async function roomDraft(inspectionId: number, sectionId: number): Promise<RoomDraft | undefined> {
    return await backend.get<RoomDraft>('drafts', `${inspectionId}:${sectionId}`);
}

export async function dropRoomDraft(inspectionId: number, sectionId: number): Promise<void> {
    await backend.remove('drafts', `${inspectionId}:${sectionId}`);
}

export async function syncSummary(): Promise<SyncSummary> {
    const [photos, submissions, drafts] = await Promise.all([
        backend.all<PendingPhoto>('photos'),
        backend.all<PendingSubmission>('submissions'),
        backend.all<RoomDraft>('drafts'),
    ]);

    return { photos: photos.length, submissions: submissions.length, drafts: drafts.length };
}

function failureMessage(payload: unknown, fallback: string): string {
    if (typeof payload === 'object' && payload !== null) {
        const record = payload as { message?: unknown; errors?: unknown };

        if (Array.isArray(record.errors) && record.errors.length > 0) {
            return record.errors.filter((item): item is string => typeof item === 'string').join(' ');
        }

        if (typeof record.message === 'string') {
            return record.message;
        }
    }

    return fallback;
}

async function responseFailure(response: Response, fallback: string): Promise<string> {
    const payload = await response.json().catch(() => null);

    return failureMessage(payload, fallback);
}

export async function flushSync(apiBase: string, headers: () => Record<string, string>): Promise<SyncResult> {
    const result: SyncResult = { photosUploaded: 0, submitted: [], errors: [] };

    for (const photo of await queuedPhotos()) {
        const form = new FormData();
        form.append('file', photo.file, photo.fileName);
        form.append('attachable_type', 'inspection_item');
        form.append('attachable_id', String(photo.itemId));

        if (photo.label !== null) {
            form.append('label', photo.label);
        }

        if (photo.replacesLinkId !== null) {
            form.append('replaces_link_id', String(photo.replacesLinkId));
        }

        try {
            const response = await fetch(`${apiBase}/evidence/upload`, { method: 'POST', headers: headers(), body: form });

            if (response.ok) {
                await dropPhoto(photo.id);
                result.photosUploaded += 1;
            } else {
                const message = await responseFailure(response, `Photo ${photo.fileName} could not be uploaded.`);
                await notePhotoFailure(photo.id, message);
                result.errors.push(message);
            }
        } catch {
            await notePhotoFailure(photo.id, 'No connection.');
            result.errors.push(`Photo ${photo.fileName} is still waiting to sync.`);
        }
    }

    const outstandingPhotos = await queuedPhotos();

    for (const submission of await queuedSubmissions()) {
        if (outstandingPhotos.some((photo) => photo.inspectionId === submission.inspectionId)) {
            result.errors.push('Upload the outstanding photos before submitting this inspection.');
            continue;
        }

        try {
            const response = await fetch(`${apiBase}/inspections/${submission.inspectionId}/submit`, {
                method: 'POST',
                headers: { ...headers(), 'Content-Type': 'application/json' },
            });

            if (response.ok) {
                await dropSubmission(submission.inspectionId);
                result.submitted.push(submission.inspectionId);
                continue;
            }

            const message = await responseFailure(response, 'The inspection could not be submitted.');
            await dropSubmission(submission.inspectionId);
            result.errors.push(message);
        } catch {
            await noteSubmissionFailure(submission.inspectionId, 'No connection.');
            result.errors.push('The submission is still waiting to sync.');
        }
    }

    return result;
}
