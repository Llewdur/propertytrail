import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SetupPanel from './SetupPanel';

const property = {
    id: 7,
    organisation_id: 3,
    name: 'Maple House',
    reference_code: null,
    property_type: 'residential_long',
    address_line_1: '1 Maple Ave',
    address_line_2: null,
    city: 'Cape Town',
    province: 'WC',
    postal_code: '8001',
    status: 'active',
    rooms: [],
};

const room = {
    id: 11,
    organisation_id: 3,
    property_id: 7,
    name: 'Main bedroom',
    room_type: 'bedroom',
    sort_order: 0,
    notes: null,
};

const lease = {
    id: 13,
    organisation_id: 3,
    property_id: 7,
    lease_reference: 'L-100',
    tenant_name: 'A. Tenant',
    start_date: '2026-01-01',
    end_date: null,
    status: 'active',
};

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function mockFetch(handler: (url: string, init?: RequestInit) => Response) {
    const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) =>
        Promise.resolve(handler(String(input), init)),
    );
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

describe('SetupPanel', () => {
    beforeEach(() => {
        mockFetch((url, init) => {
            if (init?.method === 'POST' && url.endsWith('/properties')) {
                const body = JSON.parse(String(init.body)) as { name?: string };
                if (body.name === undefined || body.name === '') {
                    return respond({ message: 'Invalid', errors: { name: ['The name field is required.'] } }, 422);
                }

                return respond({ data: { ...property, id: 8, name: body.name } }, 201);
            }

            if (init?.method === 'POST' && url.endsWith('/rooms')) {
                const body = JSON.parse(String(init.body)) as { name: string; room_type: string };
                return respond({ data: { ...room, id: 12, name: body.name, room_type: body.room_type } }, 201);
            }

            if (init?.method === 'POST' && url.endsWith('/leases')) {
                const body = JSON.parse(String(init.body)) as { lease_reference: string; tenant_name: string; start_date: string };
                return respond({
                    data: {
                        ...lease,
                        id: 14,
                        lease_reference: body.lease_reference,
                        tenant_name: body.tenant_name,
                        start_date: body.start_date,
                    },
                }, 201);
            }

            if (url.endsWith('/properties')) {
                return respond({ data: [property] });
            }

            if (url.endsWith('/properties/7/rooms')) {
                return respond({ data: [room] });
            }

            if (url.endsWith('/properties/7/leases')) {
                return respond({ data: [lease] });
            }

            return respond({ data: [] });
        });
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('shows properties, rooms and leases with their organisation', async () => {
        render(<SetupPanel apiBase="/api/v1" organisationId={3} csrfToken="csrf-123" />);

        expect((await screen.findAllByText(/Maple House/)).length).toBeGreaterThan(0);
        expect(await screen.findByText(/Main bedroom/)).toBeTruthy();
        expect(await screen.findByText(/L-100/)).toBeTruthy();
        expect(screen.getAllByText(/Org #3/).length).toBeGreaterThan(0);
    });

    it('surfaces API validation errors and posts a property with tenant headers', async () => {
        const fetchMock = vi.mocked(fetch);
        render(<SetupPanel apiBase="/api/v1" organisationId={3} csrfToken="csrf-123" />);
        await screen.findAllByText(/Maple House/);

        fireEvent.click(screen.getByRole('button', { name: 'Create property' }));
        expect(await screen.findByText('The name field is required.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText(/^Name$/), { target: { value: 'New House' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create property' }));

        await waitFor(() => expect(screen.getAllByText(/New House/).length).toBeGreaterThan(0));

        const call = fetchMock.mock.calls.filter(([url, init]) =>
            String(url).endsWith('/properties') && init?.method === 'POST',
        ).pop();
        expect(call).toBeDefined();

        const init = call?.[1];
        expect(init?.headers).toMatchObject({ 'X-Organisation-Id': '3', 'X-CSRF-TOKEN': 'csrf-123' });
        expect(JSON.parse(String(init?.body))).toMatchObject({
            name: 'New House',
            property_type: 'residential_long',
        });
    });

    it('creates rooms and leases against the selected property', async () => {
        render(<SetupPanel apiBase="/api/v1" organisationId={3} csrfToken="csrf-123" />);
        await screen.findAllByText(/Maple House/);

        fireEvent.change(screen.getByLabelText(/^Property$/), { target: { value: '7' } });
        fireEvent.change(screen.getByLabelText('Room name'), { target: { value: 'Kitchen' } });
        fireEvent.change(screen.getByLabelText('Room type'), { target: { value: 'kitchen' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add room' }));
        expect(await screen.findByText(/Kitchen/)).toBeTruthy();

        const referenceFields = screen.getAllByLabelText(/^Reference$/);
        fireEvent.change(referenceFields[1]!, { target: { value: 'L-200' } });
        fireEvent.change(screen.getByLabelText('Tenant'), { target: { value: 'B. Tenant' } });
        fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-02-01' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add lease' }));
        expect(await screen.findByText(/L-200/)).toBeTruthy();

        const fetchMock = vi.mocked(fetch);
        const roomCall = fetchMock.mock.calls.find(([url, init]) =>
            String(url).endsWith('/properties/7/rooms') && init?.method === 'POST',
        );
        const leaseCall = fetchMock.mock.calls.find(([url, init]) =>
            String(url).endsWith('/properties/7/leases') && init?.method === 'POST',
        );

        expect(JSON.parse(String(roomCall?.[1]?.body))).toMatchObject({ name: 'Kitchen', room_type: 'kitchen' });
        expect(JSON.parse(String(leaseCall?.[1]?.body))).toMatchObject({
            lease_reference: 'L-200',
            tenant_name: 'B. Tenant',
            start_date: '2026-02-01',
        });
    });
});
