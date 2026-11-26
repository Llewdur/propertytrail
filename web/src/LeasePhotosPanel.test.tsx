import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LeasePhotosPanel from './LeasePhotosPanel';

const photo = {
    id: 5,
    label: 'Incoming front',
    phase: 'incoming',
    taken_at: '2026-01-01 10:00:00',
    record: {
        id: 99,
        file_name: 'front.jpg',
        mime_type: 'image/jpeg',
        file_size_bytes: 10,
        captured_at: null,
    },
};

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('LeasePhotosPanel', () => {
    beforeEach(() => {
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:test') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
            const url = String(input);

            if (url.endsWith('/properties')) {
                return Promise.resolve(respond({ data: [{ id: 7, name: 'Maple House' }] }));
            }

            if (url.endsWith('/properties/7/rooms')) {
                return Promise.resolve(respond({ data: [] }));
            }

            if (url.endsWith('/properties/7/leases')) {
                return Promise.resolve(respond({
                    data: [{
                        id: 3,
                        lease_reference: 'L-100',
                        tenant_name: 'A. Tenant',
                        status: 'active',
                        start_date: '2026-01-01',
                        end_date: null,
                    }],
                }));
            }

            if (url.includes('/lease-photos')) {
                return Promise.resolve(respond({
                    data: { property_id: 7, groups: [{ room: null, photos: [photo] }] },
                }));
            }

            if (url.endsWith('/evidence/99/download')) {
                return Promise.resolve(new Response('image-bytes', {
                    status: 200,
                    headers: { 'Content-Type': 'image/jpeg' },
                }));
            }

            return Promise.resolve(respond({}, 404));
        }));
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('shows an error instead of crashing when the properties request is refused', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(respond({ message: 'Unauthenticated.' }, 401))));

        render(<LeasePhotosPanel apiBase="/api/v1" organisationId={3} />);

        expect(await screen.findByText('Could not load properties.')).toBeTruthy();
    });

    it('previews and offers download for attached lease photos', async () => {
        render(<LeasePhotosPanel apiBase="/api/v1" organisationId={3} />);

        expect(await screen.findByRole('img', { name: 'Incoming front' })).toBeTruthy();

        const download = screen.getByRole('link', { name: 'Download' });
        expect(download.getAttribute('href')).toBe('blob:test');
        expect(download.getAttribute('download')).toBe('front.jpg');
    });
});
