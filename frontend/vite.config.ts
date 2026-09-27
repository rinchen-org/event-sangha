import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: '../src/static/app',
    emptyOutDir: true,
    manifest: true,
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/templates': {
        target: process.env.PHP_ORIGIN || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    restoreMocks: true,
  },
});
