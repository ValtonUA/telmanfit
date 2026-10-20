import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
  plugins: [react()],
  // The production build is served by Laravel from public/app under "/app".
  // During local development the SPA stays at the root of the Vite server.
  base: command === 'build' ? '/app/' : '/',
  server: {
    // Forward API calls made by the SPA to the local Laravel server, so the
    // browser keeps talking to a single origin (no CORS needed in development).
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
  },
}))
