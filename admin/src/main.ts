import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import '@lumee/tokens/tokens.css'
import './styles/base.css'

import { t, locale } from './i18n'

document.documentElement.lang = locale
document.title = locale === 'en' ? 'Lumée Admin' : 'Lumée 商家后台'
const app = createApp(App)
app.config.globalProperties.t = t   // templates call t('…') directly
app.use(createPinia()).use(router).mount('#app')
