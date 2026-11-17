import type { ThemePreference } from '../types'

export function applyThemePreference(theme: ThemePreference) {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="color-scheme"]')
  if (meta) meta.content = theme === 'system' ? 'light dark' : theme
  const dark = theme === 'dark' || (
    theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches
  )
  document.documentElement.dataset.theme = dark ? 'dark' : 'light'
}
