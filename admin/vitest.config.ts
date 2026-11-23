// Unit tests for the admin (npm test). Tests live in tests/, outside the app's tsconfig, so `npm run build` is unaffected.
import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

export default mergeConfig(viteConfig, defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
  },
}))
