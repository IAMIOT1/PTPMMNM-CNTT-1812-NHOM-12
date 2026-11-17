import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { readUiLanguage } from './lib/i18n'

document.documentElement.lang = readUiLanguage()

if (import.meta.env.PROD) {
  const pwaScript = document.createElement('script')
  pwaScript.src = '/pwa.js'
  pwaScript.defer = true
  pwaScript.addEventListener('error', () => {
    console.error('Unable to load the IAMIOT site-wide PWA script.')
  }, { once: true })
  document.head.append(pwaScript)
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
