import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import OrganisationSwitcher from './OrganisationSwitcher';

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('OrganisationSwitcher', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('loads organisations and posts the chosen organisation', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url.endsWith('/organisations') && init?.method !== 'POST') {
                return Promise.resolve(respond({
                    data: [{ id: 3, name: 'Acme' }, { id: 9, name: 'Other' }],
                    current_organisation_id: 3,
                }));
            }

            if (url.endsWith('/organisations/switch')) {
                return Promise.resolve(respond({ ok: true }));
            }

            return Promise.resolve(respond({}, 404));
        });
        vi.stubGlobal('fetch', fetchMock);
        const onSwitched = vi.fn();

        render(<OrganisationSwitcher apiBase="/api/v1" csrfToken="csrf-1" organisationId={3} onSwitched={onSwitched} />);

        const select = (await screen.findByLabelText(/Organisation/)) as HTMLSelectElement;
        expect(select.value).toBe('3');

        fireEvent.change(select, { target: { value: '9' } });

        await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
            '/api/v1/organisations/switch',
            expect.objectContaining({ method: 'POST' }),
        ));

        const switchCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/organisations/switch'));
        expect(JSON.parse(String(switchCall?.[1]?.body))).toEqual({ organisation_id: 9 });
        expect(onSwitched).toHaveBeenCalledOnce();
    });
});
