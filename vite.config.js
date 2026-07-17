import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss()],
    publicDir: false,
    build: {
        outDir: 'public',
        emptyOutDir: false,
        rollupOptions: {
            input: 'assets/app.js',
            output: {
                entryFileNames: 'app.js',
                assetFileNames: 'app.[ext]'
            }
        }
    }
});
