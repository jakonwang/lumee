import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@lumee/tokens': fileURLToPath(new URL('../packages/ui-tokens', import.meta.url)),
    },
  },
  server: {
    // Fixed port (5173 is used by another local project). strictPort: fail instead of silently
    // moving to another port, which would break links pointing at the admin.
    port: 5280,
    strictPort: true,
    fs: { allow: ['..'] },
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
})
