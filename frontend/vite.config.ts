import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { readFileSync } from 'node:fs';

// Read the same version PHP uses, without requiring PHP during a frontend build.
const version = readFileSync(new URL('../src/lib/version.php', import.meta.url), 'utf8')
  .match(/^const APP_VERSION = '((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))';$/m)?.[1];
if (!version) throw new Error('Set APP_VERSION in src/lib/version.php to a valid MAJOR.MINOR.PATCH version.');

export default defineConfig({
  define: { __APP_VERSION__: JSON.stringify(version) },
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
      '/scripts': {
        target: process.env.PHP_ORIGIN || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/static': {
        target: process.env.PHP_ORIGIN || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
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
