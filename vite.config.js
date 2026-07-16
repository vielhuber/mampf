import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        outDir: '_public',
        emptyOutDir: false,
        rollupOptions: {
            input: '_assets/app.js',
            output: {
                entryFileNames: 'app.js',
                assetFileNames: 'app.[ext]'
            }
        }
    }
});
