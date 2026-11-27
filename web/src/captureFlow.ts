/** Where the tenant is in the guided capture, kept on the phone so they can stop at any step. */
export type Shot = 'corner' | 'nearest' | 'extra' | 'closeup';

export type IssueKind = 'broken' | 'damaged' | 'missing';

/** The problem being reported. Its key ties the note to its close-up. */
export type IssueDraft = { key: string; kind: IssueKind };

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
    | 'rate'
    | 'any-issue'
    | 'issue-kind'
    | 'issue-note'
    | 'issue-photo'
    | 'issue-more'
    | 'anything-else'
    | 'issue-room'
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
    /** The rooms walked on this phone, to pick from when reporting a problem later. */
    rooms: RoomRef[];
    issue: IssueDraft | null;
    /** True while reporting a problem after the walk, not as part of finishing a room. */
    later: boolean;
};

export const NEW_FLOW: Flow = {
    step: 'intro',
    room: null,
    previous: null,
    corner: 1,
    shot: 'corner',
    finished: [],
    rooms: [],
    issue: null,
    later: false,
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
export function resumeStep(step: Step, shot: Shot): Step {
    if (step === 'camera') {
        return shot === 'closeup' ? 'issue-photo' : 'corner';
    }

    return step === 'more' ? 'taken' : step;
}

/** A new key for a room or a reported problem. */
export function newRoomKey(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
