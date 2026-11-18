import type { t } from './index'

declare module 'vue' {
  interface ComponentCustomProperties {
    /** Translate admin UI text (see src/i18n). */
    t: typeof t
  }
}

export {}
