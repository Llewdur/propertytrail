import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import PlanPanel from './PlanPanel';

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('shows the current plan, usage and catalogue', async () => {
    const fetchMock = vi.fn(() => Promise.resolve(respond({
        data: {
            plan: { code: 'starter', name: 'Starter' },
            status: 'active',
            limits: { inspections: null, properties: 3, signing_links_per_month: 50, watermark: false },
            usage: { inspections: 1, properties: 2, signing_links_this_month: 4 },
            plans: [
                { code: 'free', name: 'Free', price_monthly_cents: 0, property_limit: 1, inspection_allowance: 1, signing_link_limit: 2, watermark: true },
                { code: 'starter', name: 'Starter', price_monthly_cents: 14900, property_limit: 3, inspection_allowance: null, signing_link_limit: 50, watermark: false },
            ],
        },
    })));
    vi.stubGlobal('fetch', fetchMock);

    render(<PlanPanel apiBase="/api/v1" organisationId={3} />);

    expect((await screen.findByText('Starter', { selector: 'strong' })).textContent).toBe('Starter');
    expect(screen.getByText(/2 \/ 3/)).toBeDefined();
    expect(screen.getByText(/R149\/month/)).toBeDefined();
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/billing/entitlement', expect.objectContaining({
        headers: expect.objectContaining({ 'X-Organisation-Id': '3' }),
    }));
});
