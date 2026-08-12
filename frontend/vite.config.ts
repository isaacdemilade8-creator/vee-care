import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'vee-care HealthTech service',
        short_name: 'vee-care',
        theme_color: '#0f766e',
        background_color: '#f5fbfa',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: '/pwa-192.svg', sizes: '192x192', type: 'image/svg+xml' },
          { src: '/pwa-512.svg', sizes: '512x512', type: 'image/svg+xml' },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/images\.unsplash\.com\/.*/i,
            handler: 'CacheFirst',
            options: { cacheName: 'remote-images' },
          },
        ],
      },
    }),
  ],
  server: {
    // Local development only; does not affect the production build.
    // Binds all interfaces so tenant hosts like
    // http://hospital-one.vee-care.test:5173 resolve without extra CLI flags.
    host: '0.0.0.0',
    // Accepts the local dev host plus vee-care.test subdomains so tenant and
    // platform pages can be opened at e.g. http://hospital-one.vee-care.test:5173.
    // Vite's host validation only matches exact names or leading-dot entries
    // ("this domain and all of its subdomains"); a literal `*.vee-care.test`
    // glob is not matched. localhost and loopback IPs are always allowed and
    // are listed here only to make the accepted set explicit.
    allowedHosts: ['localhost', '127.0.0.1', '.vee-care.test'],
  },
})
