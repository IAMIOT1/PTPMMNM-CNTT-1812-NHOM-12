import { useEffect, useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAppStore } from '../../store/appStore'
import { applyThemePreference } from '../../lib/theme'
import { ToastStack } from '../common/ToastStack'
import { Button } from '../common/Button'
import { LocalizedContent } from '../common/LocalizedContent'
import { Header } from './Header'
import { MobileSidebar, Sidebar } from './Sidebar'
import { UI_LANGUAGE_STORAGE_KEY, translateText } from '../../lib/i18n'

export function AppLayout() {
  const [collapsed, setCollapsed] = useState(false)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const currentUserId = useAppStore((state) => state.currentUserId)
  const currentUser = useAppStore((state) => state.users.find((user) => user.id === currentUserId))
  const canManageUsers = useAppStore((state) => state.canManageUsers)
  const hasLoaded = useAppStore((state) => state.hasLoaded)
  const isLoading = useAppStore((state) => state.isLoading)
  const error = useAppStore((state) => state.error)
  const loadWorkspace = useAppStore((state) => state.loadWorkspace)
  const theme = useAppStore((state) => state.theme)
  const language = useAppStore((state) => state.language)
  const syncLanguage = useAppStore((state) => state.syncLanguage)
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    applyThemePreference(theme)
  }, [theme])

  useEffect(() => {
    document.documentElement.lang = language
    document.title = translateText(language, 'LinguaDesk · Translation workspace')
  }, [language])

  useEffect(() => {
    const handleLanguageChange = (event: Event) => {
      const selectedLanguage = (event as CustomEvent<{ language?: unknown }>).detail?.language
      if (selectedLanguage === 'vi' || selectedLanguage === 'en') syncLanguage(selectedLanguage)
    }
    const handleStorageChange = (event: StorageEvent) => {
      if (event.key !== UI_LANGUAGE_STORAGE_KEY) return
      syncLanguage(event.newValue === 'en' || event.newValue === 'vi' ? event.newValue : 'vi')
    }
    document.addEventListener('tms:language-changed', handleLanguageChange)
    window.addEventListener('storage', handleStorageChange)
    return () => {
      document.removeEventListener('tms:language-changed', handleLanguageChange)
      window.removeEventListener('storage', handleStorageChange)
    }
  }, [syncLanguage])

  useEffect(() => {
    if (!hasLoaded) void loadWorkspace().catch(() => undefined)
  }, [hasLoaded, loadWorkspace])

  useEffect(() => {
    const handleSystemChange = () => {
      if (theme === 'system') applyThemePreference('system')
    }
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    media.addEventListener('change', handleSystemChange)
    return () => media.removeEventListener('change', handleSystemChange)
  }, [theme])

  useEffect(() => {
    if (!currentUser) return
    const projectRoles = new Set(currentUser.projectRoles.map((assignment) => assignment.role))
    const isSystemAdmin = currentUser.accountRole === 'admin' || canManageUsers
    const canAccessRoute: Record<string, boolean> = {
      '/projects': isSystemAdmin || projectRoles.has('Manager'),
      '/users': isSystemAdmin,
      '/settings': isSystemAdmin,
      '/translation': isSystemAdmin || projectRoles.size > 0,
      '/review': isSystemAdmin || projectRoles.has('Manager') || projectRoles.has('Reviewer'),
    }
    if (canAccessRoute[location.pathname] === false) {
      const home = isSystemAdmin ? '/users'
        : projectRoles.has('Manager') ? '/projects'
          : projectRoles.has('Reviewer') ? '/review'
            : projectRoles.has('Translator') ? '/translation'
              : '/dashboard'
      navigate(home, { replace: true })
      useAppStore.getState().addToast({
        title: 'Page not available',
        message: 'Access is determined by your system account and project assignments.',
        tone: 'info',
      })
    }
  }, [canManageUsers, currentUser, location.pathname, navigate])

  if (isLoading && !hasLoaded) {
    return (
      <LocalizedContent>
      <main className="grid min-h-dvh place-items-center bg-[var(--canvas)] px-4" aria-busy="true">
        <div className="flex flex-col items-center gap-3 text-center">
          <progress aria-label="Loading workspace data" className="h-1.5 w-48 accent-violet-600" />
          <p className="text-sm text-[var(--muted)]">Loading your workspace…</p>
        </div>
      </main>
      </LocalizedContent>
    )
  }

  if (!hasLoaded) {
    return (
      <LocalizedContent>
      <main className="grid min-h-dvh place-items-center bg-[var(--canvas)] px-4">
        <section className="w-full max-w-md rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-6 text-center shadow-sm" aria-labelledby="workspace-error">
          <h1 id="workspace-error" className="text-lg font-bold text-[var(--ink)]">Could not load the workspace</h1>
          <p className="mt-2 text-sm text-[var(--muted)]" role="alert">{error ?? 'The PHP API could not be reached.'}</p>
          <div className="mt-5 flex flex-wrap justify-center gap-2">
            <Button variant="primary" onClick={() => { void loadWorkspace().catch(() => undefined) }}>Try again</Button>
            <a href="/index.php?action=login" className="inline-flex min-h-10 items-center rounded-xl border border-[var(--line)] px-4 text-sm font-semibold text-[var(--ink)] hover:bg-[var(--surface-soft)]">Sign in</a>
          </div>
        </section>
      </main>
      </LocalizedContent>
    )
  }

  return (
    <div className="flex min-h-dvh bg-[var(--canvas)] text-[var(--ink)]">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed(!collapsed)} />
      <div className="min-w-0 flex-1">
        <Header onMenu={() => setMobileMenuOpen(true)} mobileMenuOpen={mobileMenuOpen} />
        <main className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 sm:py-7 xl:px-8">
          <Outlet />
        </main>
      </div>
      <MobileSidebar open={mobileMenuOpen} onClose={() => setMobileMenuOpen(false)} />
      <ToastStack />
    </div>
  )
}
