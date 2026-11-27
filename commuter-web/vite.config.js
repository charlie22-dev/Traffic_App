import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [react(), VitePWA({
    registerType: 'prompt',
    includeAssets: ['icon.svg', 'icon-192.png', 'icon-512.png'],
    manifest: {
      name: 'CommuteLive', short_name: 'CommuteLive', description: 'Your local jeepney and bus commute assistant',
      theme_color: '#123c35', background_color: '#f2f6f5', display: 'standalone', start_url: '/', scope: '/',
      icons: [
        { src: '/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any maskable' },
        { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
      ],
    },
    workbox: { globPatterns: ['**/*.{js,css,html,png,svg,webmanifest}'], navigateFallbackDenylist: [/^\/api(?:\/|$)/], cleanupOutdatedCaches: true },
  })],
  server: {
    proxy: {
      '/api': {
        target: process.env.COMMUTELIVE_API_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test-setup.js',
    restoreMocks: true,
    exclude: ['e2e/**', 'node_modules/**'],
  },
});
