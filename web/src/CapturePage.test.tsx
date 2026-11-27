import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushCaptures, queueCapture, queuedCaptures, resetOfflineStore } from './offline';

const queue = (): Promise<void> =>
    queueCapture({ captureKey: '7:incoming', areaId: 3, file: new File(['photo'], 'wall.jpg', { type: 'image/jpeg' }), fileName: 'wall.jpg' });

describe('tenant capture queue', () => {
    beforeEach(async () => {
        await resetOfflineStore();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('keeps a photo while offline and uploads it later with the token in a header', async () => {
        await queue();

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
        await flushCaptures('/api/v1', 'secret-token', '7:incoming');
        expect(await queuedCaptures('7:incoming')).toHaveLength(1);

        const online = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }));
        vi.stubGlobal('fetch', online);
        await flushCaptures('/api/v1', 'secret-token', '7:incoming');

        const [target, init] = online.mock.calls[0] as [string, RequestInit];
        expect(target).toBe('/api/v1/capture/photos');
        expect((init.headers as Record<string, string>)['X-Capture-Token']).toBe('secret-token');
        expect(await queuedCaptures('7:incoming')).toHaveLength(0);
    });

    it.each([
        [410, 1],
        [429, 1],
        [500, 1],
        [422, 0],
    ])('after a %i response keeps %i photo', async (status, remaining) => {
        await queue();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status })));

        await flushCaptures('/api/v1', 'secret-token', '7:incoming');

        expect(await queuedCaptures('7:incoming')).toHaveLength(remaining);
    });
});
