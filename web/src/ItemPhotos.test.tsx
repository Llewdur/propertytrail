import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ItemPhotos, { type ItemPhoto } from './ItemPhotos';

const photo = (overrides: Partial<ItemPhoto>): ItemPhoto => ({
    link_id: 1,
    evidence_id: 10,
    caption: 'Cupboard door',
    file_name: 'cupboard.jpg',
    mime_type: 'image/jpeg',
    taken_at: null,
    replaces_link_id: null,
    superseded: false,
    ...overrides,
});

const props = {
    apiBase: '/api/v1',
    headers: () => ({ Accept: 'application/json' }),
    itemId: 5,
    onChanged: () => undefined,
};

describe('ItemPhotos', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('x', { status: 404 }))));
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('shows current photos with a caption and hides superseded originals', () => {
        render(
            <ItemPhotos
                {...props}
                editable
                photos={[photo({ link_id: 1, caption: 'Old', superseded: true }), photo({ link_id: 2, evidence_id: 11, caption: 'Retaken' })]}
            />,
        );

        expect(screen.getByText('Retaken')).toBeTruthy();
        expect(screen.queryByText('Old')).toBeNull();
        expect(screen.getAllByText('Retake')).toHaveLength(1);
    });

    it('offers camera and file capture while editable', () => {
        render(<ItemPhotos {...props} editable photos={[]} />);

        expect(screen.getByText('Open camera')).toBeTruthy();
        expect(screen.getByText('Take photo')).toBeTruthy();
    });

    it('offers no capture controls once the inspection is locked', () => {
        render(<ItemPhotos {...props} editable={false} photos={[photo({})]} />);

        expect(screen.queryByText('Open camera')).toBeNull();
        expect(screen.queryByText('Take photo')).toBeNull();
        expect(screen.queryByText('Retake')).toBeNull();
    });
});
