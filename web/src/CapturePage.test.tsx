import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CapturePage from './CapturePage';
import { exifSegment } from './compressImage';
import { flushCaptures, queueCapture, queuedCaptures, releaseCaptures, resetOfflineStore } from './offline';

const queue = (extra: { held?: boolean } = {}): Promise<string> =>
    queueCapture({ captureKey: '7:incoming', areaId: 3, file: new File(['photo'], 'wall.jpg', { type: 'image/jpeg' }), fileName: 'wall.jpg', ...extra });

beforeEach(async () => {
    await resetOfflineStore();
    window.localStorage.clear();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('tenant capture queue', () => {
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
        [413, 1],
        [429, 1],
        [500, 1],
        [422, 0],
    ])('after a %i response keeps %i photo', async (status, remaining) => {
        await queue();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status })));

        await flushCaptures('/api/v1', 'secret-token', '7:incoming');

        expect(await queuedCaptures('7:incoming')).toHaveLength(remaining);
    });

    it('does not upload a photo the tenant may still retake', async () => {
        await queue({ held: true });
        const online = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }));
        vi.stubGlobal('fetch', online);

        await flushCaptures('/api/v1', 'secret-token', '7:incoming');
        expect(online).not.toHaveBeenCalled();

        await releaseCaptures('7:incoming');
        await flushCaptures('/api/v1', 'secret-token', '7:incoming');
        expect(online).toHaveBeenCalledTimes(1);
    });
});

describe('guided capture', () => {
    const capture = {
        capture_key: '7:incoming',
        property_name: 'Our House',
        tenant_name: 'T. Tenant',
        phase: 'incoming',
        expires_at: '2027-01-01T00:00:00Z',
        areas: [],
    };

    it('walks from a named room through its corners and uploads them with the new room details', async () => {
        const uploads: FormData[] = [];
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) => {
            if (init?.method === 'POST') {
                uploads.push(init.body as FormData);
                return new Response('{}', { status: 201 });
            }

            return new Response(JSON.stringify({ data: capture }), { status: 200 });
        }));
        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:photo', revokeObjectURL: () => undefined }));
        const click = (name: string): void => void fireEvent.click(screen.getByRole('button', { name }));

        render(<CapturePage apiBase="/api/v1" token="secret-token" />);
        fireEvent.click(await screen.findByRole('button', { name: 'Start' }));
        click('Garden');
        click('Next');
        click('Take photo');
        fireEvent.change(screen.getByLabelText('Open phone camera'), {
            target: { files: [new File(['photo'], 'a.jpg', { type: 'image/jpeg' })] },
        });
        await screen.findByRole('heading', { name: 'Thank you. Go to the next corner.' });
        expect(uploads).toHaveLength(0);

        click('No more corners');
        await waitFor(() => expect(uploads).toHaveLength(1));
        expect(Object.fromEntries(['room_name', 'room_outdoor', 'corner', 'shot'].map((key) => [key, uploads[0].get(key)]))).toEqual({
            room_name: 'Garden',
            room_outdoor: '1',
            corner: '1',
            shot: 'corner',
        });

        click('Yes, this room is done');
        click('Fair');
        await screen.findByRole('heading', { name: 'Is anything broken, damaged or missing?' });
        click('Yes');
        click('Broken');
        fireEvent.change(screen.getByLabelText('Describe the problem'), { target: { value: 'Gate latch' } });
        click('Next');
        await screen.findByRole('heading', { name: 'Take a close-up.' });
        expect(screen.queryByRole('button', { name: 'Skip' })).toBeNull();
        click('Take photo');
        fireEvent.change(screen.getByLabelText('Open phone camera'), {
            target: { files: [new File(['latch'], 'b.jpg', { type: 'image/jpeg' })] },
        });
        await screen.findByRole('heading', { name: 'Another one in Garden?' });
        click('No, that is all');
        await waitFor(() => expect(uploads).toHaveLength(4));
        const [, rating, reported, closeUp] = uploads;
        expect(rating.get('condition')).toBe('fair');
        expect(reported.get('issue_note')).toBe('Gate latch');
        expect([closeUp.get('shot'), closeUp.get('issue_key')]).toEqual(['closeup', reported.get('issue_key')]);

        await screen.findByRole('heading', { name: 'Please go to the very next room.' });
        expect(screen.getByRole('heading', { name: 'Please go to the very next room.' })).toBeTruthy();

        // A reload comes back to the same step.
        cleanup();
        render(<CapturePage apiBase="/api/v1" token="secret-token" />);
        fireEvent.click(await screen.findByRole('button', { name: 'Continue' }));
        click('I am in the next room');
        click('Kitchen');
        click('Next');
        expect(screen.getByRole('heading', { name: 'Did you walk straight here from Garden?' })).toBeTruthy();
    });
});

describe('compression metadata', () => {
    it('carries the EXIF block across with its orientation reset', () => {
        // SOI, then APP1 "Exif" holding a little-endian TIFF with one orientation entry set to 6.
        const jpeg = new Uint8Array([
            0xff, 0xd8, 0xff, 0xe1, 0x00, 0x22, 0x45, 0x78, 0x69, 0x66, 0x00, 0x00,
            0x49, 0x49, 0x2a, 0x00, 0x08, 0x00, 0x00, 0x00, 0x01, 0x00,
            0x12, 0x01, 0x03, 0x00, 0x01, 0x00, 0x00, 0x00, 0x06, 0x00, 0x00, 0x00,
            0x00, 0x00, 0x00, 0x00, 0xff, 0xda,
        ]);

        const segment = exifSegment(jpeg);

        expect(segment).toHaveLength(36);
        expect(segment?.[28]).toBe(1);
        expect(exifSegment(new Uint8Array([0x89, 0x50, 0x4e, 0x47]))).toBeNull();
    });
});
