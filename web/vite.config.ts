import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
    base: '/spa/',
    plugins: [react()],
    build: {
        outDir: '../public/spa',
        emptyOutDir: false,
    },
    server: {
        host: true,
        port: 5193,
        strictPort: true,
        proxy: {
            '/api': 'http://localhost:80',
            '/sanctum': 'http://localhost:80',
            '/up': 'http://localhost:80',
            '/version': 'http://localhost:80',
        },
    },
});
