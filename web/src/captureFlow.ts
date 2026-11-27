/** Where the tenant is in the guided capture, kept on the phone so they can stop at any step. */
export type Shot = 'corner' | 'nearest' | 'extra';

/** A room that exists on the server (id), or one the tenant named that its first photo creates (key). */
export type RoomRef = {
    id: number | null;
    key: string | null;
    name: string;
    outdoor: boolean;
    adjoinsId: number | null;
    adjoinsKey: string | null;
};

export type Step =
    | 'intro'
    | 'name'
    | 'walked'
    | 'corner'
    | 'nearest'
    | 'camera'
    | 'taken'
    | 'more'
    | 'room-check'
    | 'next-room'
    | 'last-check'
    | 'done';

export type Flow = {
    step: Step;
    room: RoomRef | null;
    previous: RoomRef | null;
    corner: number;
    shot: Shot;
    finished: string[];
};

export const NEW_FLOW: Flow = {
    step: 'intro',
    room: null,
    previous: null,
    corner: 1,
    shot: 'corner',
    finished: [],
};

const storageKey = (captureKey: string): string => `propertytrail-capture:${captureKey}`;

export function loadFlow(captureKey: string): Flow | null {
    try {
        const stored = window.localStorage.getItem(storageKey(captureKey));

        return stored === null ? null : { ...NEW_FLOW, ...(JSON.parse(stored) as Partial<Flow>) };
    } catch {
        return null;
    }
}

export function saveFlow(captureKey: string, flow: Flow): void {
    try {
        window.localStorage.setItem(storageKey(captureKey), JSON.stringify(flow));
    } catch {
        // A private window may refuse storage; the capture still works until the tab closes.
    }
}

/** The step to come back to. The camera does not reopen by itself. */
export function resumeStep(step: Step): Step {
    if (step === 'camera') {
        return 'corner';
    }

    return step === 'more' ? 'taken' : step;
}

export function newRoomKey(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
