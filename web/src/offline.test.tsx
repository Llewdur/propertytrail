import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    dropSubmission,
    flushSync,
    queuePhoto,
    queueSubmission,
    queuedPhotos,
    queuedSubmissions,
    resetOfflineStore,
    roomDraft,
    saveRoomDraft,
    syncSummary,
} from './offline';

const headers = (): Record<string, string> => ({ Accept: 'application/json' });

describe('offline sync', () => {
    beforeEach(async () => {
        await resetOfflineStore();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('queues a photo locally and uploads it once on sync', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const file = new File(['photo'], 'photo.jpg', { type: 'image/jpeg' });
        const queued = await queuePhoto({
            inspectionId: 3,
            itemId: 5,
            file,
            fileName: 'photo.jpg',
            label: 'Door hinge',
            replacesLinkId: null,
        });

        expect(queued.fileName).toBe('photo.jpg');
        expect(await queuedPhotos(3)).toHaveLength(1);

        const result = await flushSync('/api/v1', headers);

        expect(result.photosUploaded).toBe(1);
        expect(await queuedPhotos()).toHaveLength(0);
    });

    it('keeps a submission queued while its photos are still pending', async () => {
        const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
        vi.stubGlobal('fetch', fetchMock);

        await queuePhoto({
            inspectionId: 3,
            itemId: 5,
            file: new File(['photo'], 'photo.jpg', { type: 'image/jpeg' }),
            fileName: 'photo.jpg',
            label: null,
            replacesLinkId: null,
        });
        await queueSubmission(3);

        const result = await flushSync('/api/v1', headers);

        expect(result.submitted).toEqual([]);
        expect(result.errors.some((message) => message.includes('outstanding photos'))).toBe(true);
        expect(await queuedSubmissions()).toHaveLength(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('dedupes submissions and only clears them after the server confirms', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        await Promise.all([queueSubmission(9), queueSubmission(9), queueSubmission(9)]);
        expect(await queuedSubmissions()).toHaveLength(1);

        const result = await flushSync('/api/v1', headers);

        expect(result.submitted).toEqual([9]);
        expect(await queuedSubmissions()).toHaveLength(0);
    });

    it('does not report a rejected submission as submitted', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ message: 'Rooms are not complete' }), {
                status: 422,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        await queueSubmission(11);
        const result = await flushSync('/api/v1', headers);

        expect(result.submitted).toEqual([]);
        expect(result.errors).toContain('Rooms are not complete');
        expect(await queuedSubmissions()).toHaveLength(0);
    });

    it('restores room drafts and counts them in the sync summary', async () => {
        await saveRoomDraft({
            inspectionId: 3,
            sectionId: 4,
            items: [{ id: null, label: 'Door', condition: 'poor', note: 'Hinge loose' }],
            updatedAt: '2026-11-24T10:00:00.000Z',
        });

        const draft = await roomDraft(3, 4);

        expect(draft?.items[0].label).toBe('Door');
        expect(await syncSummary()).toEqual({ photos: 0, submissions: 0, drafts: 1 });

        await dropSubmission(3);
        expect(await queuedSubmissions()).toHaveLength(0);
    });
});
