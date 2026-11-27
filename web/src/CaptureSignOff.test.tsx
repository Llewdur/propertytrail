import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CaptureSignOff from './CaptureSignOff';

const review = (status: string, signed: object | null = null) => ({
    data: {
        status,
        content_hash: 'a'.repeat(64),
        declaration_version: 'draft',
        declarations: [
            { key: 'identity', text: 'I am Thandi Tenant.' },
            { key: 'accuracy', text: 'This record is accurate.' },
        ],
        tenant_name: 'Thandi Tenant',
        rooms: [{ name: 'Hall', condition: 'good', photo_count: 4, issues: [{ kind: 'damaged', note: 'Cracked tile' }] }],
        signed,
    },
});

const props = { apiBase: '/api/v1', token: 'secret', unlock: null, passkeyRegistered: false, onUnlock: vi.fn(), onFix: vi.fn(), onBack: vi.fn() };

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('tenant sign-off', () => {
    it('walks the summary one room per screen, then each declaration', async () => {
        const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify(review('unsigned')), { status: 200 }));
        vi.stubGlobal('fetch', fetcher);
        render(<CaptureSignOff {...props} />);

        expect(await screen.findByText('Hall')).toBeTruthy();
        expect(screen.getByText('damaged: Cracked tile')).toBeTruthy();
        expect((fetcher.mock.calls[0][1] as RequestInit & { headers: Record<string, string> }).headers['X-Capture-Token']).toBe('secret');

        fireEvent.click(screen.getByText('This is right'));
        expect(screen.getByText('I am Thandi Tenant.')).toBeTruthy();
        fireEvent.click(screen.getByText('I confirm'));
        expect(screen.getByText('This record is accurate.')).toBeTruthy();
    });

    it('sends the tenant back to the capture when something is wrong', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify(review('changed')), { status: 200 })));
        const onFix = vi.fn();
        render(<CaptureSignOff {...props} onFix={onFix} />);

        expect(await screen.findByText('Your record changed after you signed. Check it and sign again.')).toBeTruthy();
        fireEvent.click(screen.getByText('Something is wrong or missing'));
        expect(onFix).toHaveBeenCalled();
    });

    it('offers the copy once the current record is signed', async () => {
        const signed = { version: 1, reference: 'SO-7-incoming-v1-aaaaaaaa', signed_at: '2026-11-27T08:00:00Z' };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify(review('signed', signed)), { status: 200 })));
        render(<CaptureSignOff {...props} />);

        expect(await screen.findByText('Signed. Thank you.')).toBeTruthy();
        expect(screen.getByText('Reference SO-7-incoming-v1-aaaaaaaa')).toBeTruthy();
        expect(screen.getByText('Download your copy')).toBeTruthy();
    });
});
