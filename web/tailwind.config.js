/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './index.html',
        './src/**/*.{ts,tsx}',
        '../packages/ui/src/**/*.{ts,tsx}',
        '../vendor/zekini/auth/js/src/**/*.{ts,tsx}',
    ],
    theme: {
        extend: {},
    },
    plugins: [],
};
