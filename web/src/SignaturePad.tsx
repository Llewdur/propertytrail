import { Button } from '@zekini/ui';
import { useEffect, useRef, useState } from 'react';

type Props = {
    onChange: (dataUrl: string | null) => void;
};

/** Finger/mouse signature canvas; emits a PNG data URL, or null when blank. */
export default function SignaturePad({ onChange }: Props) {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const drawing = useRef(false);
    const inked = useRef(false);
    const [hasInk, setHasInk] = useState(false);

    useEffect(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (canvas === null || !context) {
            return;
        }

        context.lineWidth = 2.5;
        context.lineCap = 'round';
        context.strokeStyle = '#0f172a';
    }, []);

    function point(event: React.PointerEvent<HTMLCanvasElement>): { x: number; y: number } {
        const canvas = event.currentTarget;
        const rect = canvas.getBoundingClientRect();

        return {
            x: ((event.clientX - rect.left) / rect.width) * canvas.width,
            y: ((event.clientY - rect.top) / rect.height) * canvas.height,
        };
    }

    function start(event: React.PointerEvent<HTMLCanvasElement>): void {
        const context = event.currentTarget.getContext('2d');

        if (!context) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);
        drawing.current = true;
        const { x, y } = point(event);
        context.beginPath();
        context.moveTo(x, y);
    }

    function move(event: React.PointerEvent<HTMLCanvasElement>): void {
        const context = event.currentTarget.getContext('2d');

        if (!drawing.current || !context) {
            return;
        }

        const { x, y } = point(event);
        context.lineTo(x, y);
        context.stroke();
        inked.current = true;
    }

    function end(event: React.PointerEvent<HTMLCanvasElement>): void {
        if (!drawing.current) {
            return;
        }

        drawing.current = false;

        if (!inked.current) {
            return;
        }

        setHasInk(true);
        onChange(event.currentTarget.toDataURL('image/png'));
    }

    function clear(): void {
        const canvas = canvasRef.current;
        canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
        inked.current = false;
        setHasInk(false);
        onChange(null);
    }

    return (
        <div className="space-y-2">
            <canvas
                ref={canvasRef}
                width={600}
                height={200}
                aria-label="Signature pad"
                className="w-full touch-none rounded border border-slate-300 bg-white"
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerCancel={end}
            />
            <Button variant="secondary" onClick={clear} disabled={!hasInk}>Clear</Button>
        </div>
    );
}
