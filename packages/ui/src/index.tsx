import type { ButtonHTMLAttributes, ReactNode } from 'react';

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: 'primary' | 'secondary';
    children: ReactNode;
};

export function Button({ variant = 'primary', className = '', children, ...props }: ButtonProps) {
    const base = 'inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-semibold transition';
    const styles = variant === 'primary'
        ? 'bg-slate-900 text-white hover:bg-slate-800'
        : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50';

    return (
        <button className={`${base} ${styles} ${className}`.trim()} {...props}>
            {children}
        </button>
    );
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
    return <div className={`rounded-lg border border-slate-200 bg-white p-5 shadow-sm ${className}`.trim()}>{children}</div>;
}
