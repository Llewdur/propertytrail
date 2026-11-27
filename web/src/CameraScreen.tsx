import { useEffect, useRef, useState } from 'react';

type Props = {
    instruction: string;
    onPhoto: (file: File) => void;
    onCancel: () => void;
    /** The front camera for a selfie, the back one for the rooms. */
    facing?: 'user' | 'environment';
};

const PRIMARY = 'block w-full rounded-lg bg-slate-900 px-4 py-4 text-center text-lg font-semibold text-white';

/** The in-app camera, with the phone's own camera when the browser will not give us a live one. */
export default function CameraScreen({ instruction, onPhoto, onCancel, facing = 'environment' }: Props) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const [live, setLive] = useState(() => navigator.mediaDevices?.getUserMedia !== undefined);

    useEffect(() => {
        if (!live) {
            return;
        }

        let stream: MediaStream | null = null;
        let closed = false;

        navigator.mediaDevices
            .getUserMedia({
                video: { facingMode: { ideal: facing }, width: { ideal: 2560 }, height: { ideal: 1920 } },
                audio: false,
            })
            .then((granted) => {
                if (closed) {
                    granted.getTracks().forEach((track) => track.stop());
                    return;
                }

                stream = granted;

                if (videoRef.current !== null) {
                    videoRef.current.srcObject = granted;
                    void videoRef.current.play();
                }
            })
            .catch(() => setLive(false));

        return () => {
            closed = true;
            stream?.getTracks().forEach((track) => track.stop());
        };
    }, [live, facing]);

    function snap(): void {
        const video = videoRef.current;

        if (video === null || video.videoWidth === 0) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d')?.drawImage(video, 0, 0);
        canvas.toBlob((blob) => {
            if (blob !== null) {
                onPhoto(new File([blob], `capture-${Date.now()}.jpg`, { type: 'image/jpeg' }));
            }
        }, 'image/jpeg', 0.92);
    }

    return (
        <main className="flex h-[100dvh] flex-col gap-2 bg-slate-950 p-3 text-white">
            <p className="text-center">{instruction}</p>
            {live ? (
                <video ref={videoRef} playsInline muted className="min-h-0 w-full flex-1 object-contain" />
            ) : (
                <div className="min-h-0 flex-1" />
            )}
            {live ? (
                <button type="button" className={`${PRIMARY} bg-white !text-slate-900`} onClick={snap}>
                    Take photo
                </button>
            ) : (
                <label className={`${PRIMARY} cursor-pointer bg-white !text-slate-900`}>
                    Open phone camera
                    <input
                        type="file"
                        accept="image/*"
                        capture={facing}
                        className="sr-only"
                        aria-label="Open phone camera"
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';

                            if (file !== undefined) {
                                onPhoto(file);
                            }
                        }}
                    />
                </label>
            )}
            <button type="button" className="py-1 text-sm underline" onClick={onCancel}>
                Back
            </button>
        </main>
    );
}
