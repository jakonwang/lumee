import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@lumee/tokens/tokens.css'
import './styles/base.css'

import { t, locale, loadLocale } from './i18n'
import axios from 'axios'
import { createDiscreteApi } from 'naive-ui'
import { errorMessage } from './api/http'

document.documentElement.lang = locale
document.title = locale === 'en' ? 'Lumée Admin' : 'Lumée 商家后台'
// Safety net: a request that failed and that no page handled (a list that didn't load…) still tells the user,
// instead of leaving a blank page. Pages that catch their own errors aren't affected.
const { message } = createDiscreteApi(['message'])
let lastError = { text: '', at: 0 }
/** Tell the user about a failed request nobody handled; true when it was one (so it isn't logged as a crash). */
function reportRequestError(err: unknown): boolean {
  if (!axios.isAxiosError(err) || axios.isCancel(err)) return false
  const status = err.response?.status
  if (status !== 401 && status !== 402) {   // 401 / 402 are handled by the http client (sign-in / billing page)
    const text = !err.response ? t('网络连接失败，请检查网络后重试') : status! >= 500 ? t('服务器暂时出错，请稍后再试') : errorMessage(err)
    // A page often makes several requests at once: say it once, not once per request
    if (text !== lastError.text || Date.now() - lastError.at > 4000) message.error(text)
    lastError = { text, at: Date.now() }
  }
  return true
}
window.addEventListener('unhandledrejection', (ev) => { if (reportRequestError(ev.reason)) ev.preventDefault() })

// The app (router, menus, page titles) is imported only once the language is ready
loadLocale().then(async () => {
  const [{ default: App }, { router }] = await Promise.all([import('./App.vue'), import('./router')])
  const app = createApp(App)
  // Errors thrown in components (a page's onMounted(load) that failed…) come here, not to unhandledrejection
  app.config.errorHandler = (err) => { if (!reportRequestError(err)) console.error(err) }
  app.config.globalProperties.t = t   // templates call t('…') directly
  app.use(createPinia()).use(router).mount('#app')
})
