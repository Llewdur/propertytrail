/**
 * The phone's own unlock (a WebAuthn passkey) for a capture link. The browser asks
 * for the face, fingerprint or PIN itself; none of it ever reaches this page.
 */

type Json = Record<string, unknown>;

const toBytes = (value: string): ArrayBuffer => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes.buffer;
};

const toText = (buffer: ArrayBuffer | null | undefined): string | undefined => {
    if (buffer === null || buffer === undefined) {
        return undefined;
    }

    let binary = '';

    new Uint8Array(buffer).forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const descriptors = (list: unknown): PublicKeyCredentialDescriptor[] | undefined =>
    Array.isArray(list)
        ? list.map((entry: Json) => ({ ...(entry as object), id: toBytes(String(entry.id)) }) as PublicKeyCredentialDescriptor)
        : undefined;

export function passkeysSupported(): boolean {
    return typeof window !== 'undefined' && typeof window.PublicKeyCredential === 'function' && navigator.credentials !== undefined;
}

/** The credential in the JSON form the server reads. */
function serialise(credential: PublicKeyCredential): Json {
    const response = credential.response as AuthenticatorAttestationResponse & AuthenticatorAssertionResponse;

    return {
        id: credential.id,
        rawId: toText(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: toText(response.clientDataJSON),
            ...('attestationObject' in response ? { attestationObject: toText(response.attestationObject) } : {}),
            ...('authenticatorData' in response ? { authenticatorData: toText(response.authenticatorData) } : {}),
            ...('signature' in response ? { signature: toText(response.signature) } : {}),
            ...('userHandle' in response && response.userHandle !== null ? { userHandle: toText(response.userHandle) } : {}),
            ...(typeof response.getTransports === 'function' ? { transports: response.getTransports() } : {}),
        },
    };
}

async function call(apiBase: string, token: string, path: string, unlock: string | null, body?: Json): Promise<{ ok: boolean; data?: Json; message?: string }> {
    const response = await fetch(`${apiBase}/capture/${path}`, {
        method: 'POST',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Capture-Token': token,
            ...(unlock === null ? {} : { 'X-Capture-Unlock': unlock }),
        },
        body: JSON.stringify(body ?? {}),
    });
    const payload = (await response.json().catch(() => null)) as { data?: Json; message?: string } | null;

    return { ok: response.ok, data: payload?.data, message: payload?.message };
}

/** Sets up the passkey and returns this visit's unlock, or throws the reason to show. */
export async function registerPasskey(apiBase: string, token: string, unlock: string | null): Promise<string> {
    const issued = await call(apiBase, token, 'passkey/registration-options', unlock);
    const options = issued.data?.options as Json | undefined;

    if (!issued.ok || options === undefined) {
        throw new Error(issued.message ?? 'The phone unlock could not be set up.');
    }

    const user = options.user as Json;
    const credential = (await navigator.credentials.create({
        publicKey: {
            ...(options as object),
            challenge: toBytes(String(options.challenge)),
            user: { ...user, id: toBytes(String(user.id)) },
            excludeCredentials: descriptors(options.excludeCredentials),
        } as PublicKeyCredentialCreationOptions,
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new Error('The phone unlock was not set up.');
    }

    const saved = await call(apiBase, token, 'passkey', unlock, { credential: serialise(credential) });

    if (!saved.ok || typeof saved.data?.unlock !== 'string') {
        throw new Error(saved.message ?? 'The phone unlock could not be set up.');
    }

    return saved.data.unlock;
}

/** Unlocks the link with the phone and returns this visit's unlock, or throws the reason to show. */
export async function unlockWithPasskey(apiBase: string, token: string): Promise<string> {
    const issued = await call(apiBase, token, 'unlock/options', null);
    const options = issued.data?.options as Json | undefined;

    if (!issued.ok || options === undefined) {
        throw new Error(issued.message ?? 'The link could not be unlocked.');
    }

    const credential = (await navigator.credentials.get({
        publicKey: {
            ...(options as object),
            challenge: toBytes(String(options.challenge)),
            allowCredentials: descriptors(options.allowCredentials),
        } as PublicKeyCredentialRequestOptions,
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new Error('The link was not unlocked.');
    }

    const checked = await call(apiBase, token, 'unlock', null, { credential: serialise(credential) });

    if (!checked.ok || typeof checked.data?.unlock !== 'string') {
        throw new Error(checked.message ?? 'The link could not be unlocked.');
    }

    return checked.data.unlock;
}
