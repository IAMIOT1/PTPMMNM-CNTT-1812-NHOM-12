import { Bell, ChevronDown, Command, Menu, Moon, Sun, Monitor, Search, Sparkles } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Avatar } from '../common/Avatar'
import { useAppStore } from '../../store/appStore'
import { accountRoleLabels, projectRoleLabels } from '../../lib/utils'
import { applyThemePreference } from '../../lib/theme'
import { translateText } from '../../lib/i18n'

const routeTitles: Record<string, { title: string; subtitle: string }> = {
  '/dashboard': { title: 'Overview', subtitle: 'A clear view of your localization workflow.' },
  '/projects': { title: 'Projects', subtitle: 'Plan work, manage language coverage, and assign your team.' },
  '/translation': { title: 'Translation workspace', subtitle: 'Translate one string at a time with live progress.' },
  '/review': { title: 'Review queue', subtitle: 'Give translators clear, actionable feedback.' },
  '/users': { title: 'Team & access', subtitle: 'Manage the people and roles in your workspace.' },
  '/support-requests': { title: 'Support requests', subtitle: 'Review user-reported issues and track them through resolution.' },
  '/logs': { title: 'Audit logs', subtitle: 'A durable timeline of changes across your workspace.' },
  '/analytics': { title: 'Analytics', subtitle: 'Understand delivery pace and quality by language.' },
  '/settings': { title: 'Settings', subtitle: 'Tune your translation provider and workspace preferences.' },
}

export function Header({ onMenu, mobileMenuOpen }: { onMenu: () => void; mobileMenuOpen: boolean }) {
  const location = useLocation()
  const navigate = useNavigate()
  const path = location.pathname.startsWith('/project') ? '/projects' : location.pathname
  const page = routeTitles[path] ?? routeTitles['/dashboard']
  const users = useAppStore((state) => state.users)
  const projects = useAppStore((state) => state.projects)
  const language = useAppStore((state) => state.language)
  const setLanguage = useAppStore((state) => state.setLanguage)
  const addToast = useAppStore((state) => state.addToast)
  const t = (text: string) => translateText(language, text)
  const currentUserId = useAppStore((state) => state.currentUserId)
  const currentUser = users.find((user) => user.id === currentUserId) ?? users[0]
  const userName = currentUser?.name ?? t('Account')
  const isSystemAdmin = currentUser?.accountRole === 'admin'
  const projectRoleSummary = [...new Set(currentUser?.projectRoles.map(({ role }) => role) ?? [])]
    .map((role) => t(projectRoleLabels[role]))
    .join(' · ')
  const currentUserProjectRoles = (currentUser?.projectRoles ?? []).map((assignment) => ({
    ...assignment,
    projectName: projects.find((project) => project.id === assignment.projectId)?.name ?? assignment.projectId,
  }))
  const userAriaLabel = isSystemAdmin
    ? `Current user ${userName}, ${t(accountRoleLabels.admin)}`
    : `Current user ${userName}, project roles: ${projectRoleSummary || 'none'}`
  const theme = useAppStore((state) => state.theme)
  const setTheme = useAppStore((state) => state.setTheme)
  const logs = useAppStore((state) => state.logs)
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [quickSearch, setQuickSearch] = useState('')

  const filteredRoutes = useMemo(() => {
    const routes = Object.entries(routeTitles).filter(([route]) => route !== '/dashboard')
    return quickSearch.trim()
      ? routes.filter(([, content]) => translateText(language, content.title).toLowerCase().includes(quickSearch.toLowerCase()))
      : []
  }, [language, quickSearch])

  const cycleTheme = () => {
    const next = theme === 'system' ? 'dark' : theme === 'dark' ? 'light' : 'system'
    setTheme(next)
    applyThemePreference(next)
  }
  const chooseLanguage = (nextLanguage: 'vi' | 'en') => {
    try {
      setLanguage(nextLanguage)
    } catch (error) {
      console.error('Unable to save the shared TMS language preference.', error)
      addToast({
        title: 'Could not save language preference',
        message: 'Check browser storage permissions and try again.',
        tone: 'error',
      })
    }
  }
  const ThemeIcon = theme === 'dark' ? Moon : theme === 'light' ? Sun : Monitor

  return (
    <header className="sticky top-0 z-30 border-b border-[var(--line)] bg-[var(--surface)]/95 backdrop-blur-xl">
      <div className="flex min-h-[76px] items-center justify-between gap-3 px-4 sm:px-6 xl:px-8">
        <div className="flex min-w-0 items-center gap-3">
          <button type="button" onClick={onMenu} className="grid size-11 shrink-0 place-items-center rounded-xl border border-[var(--line)] text-[var(--ink)] hover:bg-[var(--surface-soft)] lg:hidden" aria-label="Open navigation menu" aria-controls="mobile-navigation" aria-expanded={mobileMenuOpen}>
            <Menu size={19} aria-hidden="true" />
          </button>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <p className="truncate text-[17px] font-bold tracking-tight text-[var(--ink)]">{t(page.title)}</p>
              <span className="hidden items-center gap-1 rounded-full bg-violet-50 px-2 py-1 text-[9px] font-bold uppercase tracking-wide text-violet-700 sm:inline-flex">
                <Sparkles size={10} aria-hidden="true" /> {t('Workspace')}
              </span>
            </div>
            <p className="mt-0.5 hidden truncate text-xs text-[var(--muted)] sm:block">{t(page.subtitle)}</p>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
          <div className="relative hidden xl:block">
            <label htmlFor="global-search" className="sr-only">{t('Quick page search')}</label>
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
            <input
              id="global-search"
              type="search"
              value={quickSearch}
              onChange={(event) => setQuickSearch(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && filteredRoutes[0]) {
                  navigate(filteredRoutes[0][0])
                  setQuickSearch('')
                }
              }}
              placeholder={t('Jump to a page...')}
              className="h-10 w-48 rounded-xl border border-[var(--line)] bg-[var(--canvas)] pl-9 pr-10 text-xs text-[var(--ink)] outline-none transition focus:border-violet-400 focus:ring-4 focus:ring-violet-500/10"
            />
            <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 inline-flex items-center gap-0.5 rounded border border-[var(--line)] bg-[var(--surface)] px-1.5 py-1 text-[9px] text-[var(--muted)]">
              <Command size={10} aria-hidden="true" /> K
            </span>
            {filteredRoutes.length > 0 && (
              <div className="absolute inset-x-0 top-12 z-50 overflow-hidden rounded-xl border border-[var(--line)] bg-[var(--surface)] p-1.5 shadow-xl">
                {filteredRoutes.slice(0, 4).map(([route, content]) => (
                  <button key={route} type="button" onClick={() => { navigate(route); setQuickSearch('') }} className="block w-full rounded-lg px-3 py-2 text-left text-xs font-medium text-[var(--ink)] hover:bg-[var(--surface-soft)]">
                    {t(content.title)}
                  </button>
                ))}
              </div>
            )}
          </div>

          <button type="button" onClick={cycleTheme} className="grid size-11 place-items-center rounded-xl text-[var(--muted)] transition hover:bg-[var(--surface-soft)] hover:text-[var(--ink)]" aria-label={t(`Current theme: ${theme}. Change theme`)}>
            <ThemeIcon size={18} aria-hidden="true" />
          </button>

          <div className="relative">
            <button type="button" onClick={() => setNotificationsOpen(!notificationsOpen)} className="relative grid size-11 place-items-center rounded-xl text-[var(--muted)] transition hover:bg-[var(--surface-soft)] hover:text-[var(--ink)]" aria-label={t('Notifications')} aria-expanded={notificationsOpen}>
              <Bell size={18} aria-hidden="true" />
              <span className="absolute right-2 top-2 size-2 rounded-full border-2 border-[var(--surface)] bg-rose-500" aria-label={t('Unread notifications')} />
            </button>
            {notificationsOpen && (
              <div className="absolute right-0 top-12 z-50 w-[min(340px,calc(100vw-24px))] rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3 shadow-xl">
                <div className="flex items-center justify-between px-1 pb-2">
                  <h2 className="text-sm font-bold text-[var(--ink)]">{t('Recent activity')}</h2>
                  <span className="text-[10px] font-semibold text-violet-600">{t('Latest 3')}</span>
                </div>
                {logs.slice(0, 3).map((log) => (
                  <div className="border-t border-[var(--line)] px-1 py-2.5" key={log.id}>
                    <p className="text-xs font-semibold text-[var(--ink)]">{t(log.action)}</p>
                    <p className="mt-0.5 truncate text-[11px] text-[var(--muted)]">{log.target} · {new Date(log.createdAt).toLocaleTimeString(language === 'vi' ? 'vi-VN' : 'en-US', { hour: '2-digit', minute: '2-digit' })}</p>
                  </div>
                ))}
                <button type="button" onClick={() => { setNotificationsOpen(false); navigate('/logs') }} className="mt-1 w-full rounded-lg bg-[var(--surface-soft)] py-2 text-xs font-semibold text-violet-700 hover:bg-violet-50">
                  {t('View audit log')}
                </button>
              </div>
            )}
          </div>

          <span className="mx-1 hidden h-8 w-px bg-[var(--line)] sm:block" aria-hidden="true" />
          <div className="relative">
            <button type="button" onClick={() => setUserMenuOpen(!userMenuOpen)} className="flex min-h-11 min-w-11 items-center gap-2 rounded-xl p-1.5 pr-2 transition hover:bg-[var(--surface-soft)]" aria-expanded={userMenuOpen} aria-label={t(userAriaLabel)}>
              {currentUser ? <Avatar user={currentUser} /> : <span className="grid size-9 place-items-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">?</span>}
              <span className="hidden min-w-0 text-left lg:block">
                <span className="block max-w-28 truncate text-xs font-bold text-[var(--ink)]">{userName}</span>
                <span className="mt-0.5 block max-w-40 truncate text-[10px] text-[var(--muted)]">{isSystemAdmin ? t(accountRoleLabels.admin) : projectRoleSummary ? t(`Project roles · ${projectRoleSummary}`) : t('No project role assigned')}</span>
              </span>
              <ChevronDown size={14} className="hidden text-[var(--muted)] sm:block" aria-hidden="true" />
            </button>
            {userMenuOpen && (
              <div className="absolute right-0 top-12 z-50 w-64 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-2 shadow-xl">
                <div className="flex items-center gap-2.5 rounded-xl px-2 py-2">
                  {currentUser && <Avatar user={currentUser} size="sm" />}
                  <span className="min-w-0">
                    <span className="block truncate text-xs font-bold text-[var(--ink)]">{userName}</span>
                    <span className="block truncate text-[10px] text-[var(--muted)]">{currentUser?.email ?? ''}</span>
                    <span className="mt-1 block text-[10px] font-semibold text-[var(--ink)]">{currentUser ? t(accountRoleLabels[currentUser.accountRole]) : t('Account role unavailable')}</span>
                  </span>
                </div>
                <div className="mt-2 border-t border-[var(--line)] px-2 pt-2">
                  <p className="text-[9px] font-bold uppercase tracking-wider text-[var(--muted)]">{t('Project roles')}</p>
                  {currentUserProjectRoles.length > 0
                    ? currentUserProjectRoles.map(({ projectId, projectName, role }) => (
                      <p key={`${projectId}:${role}`} className="mt-1 truncate text-[10px] text-[var(--ink)]">
                        {projectName} · {t(projectRoleLabels[role])}
                      </p>
                    ))
                    : <p className="mt-1 text-[10px] text-[var(--muted)]">{t('No project assignments')}</p>}
                </div>
                <div className="mt-2 border-t border-[var(--line)] px-2 pt-2">
                  <p className="mb-1.5 text-[9px] font-bold uppercase tracking-wider text-[var(--muted)]">{t('Language')}</p>
                  <div className="grid grid-cols-2 gap-1" role="group" aria-label={t('Language')}>
                    <button
                      type="button"
                      onClick={() => chooseLanguage('vi')}
                      aria-pressed={language === 'vi'}
                      aria-label={t('Use Vietnamese')}
                      className={`rounded-lg px-2 py-2 text-[10px] font-semibold transition ${language === 'vi' ? 'bg-violet-50 text-violet-700' : 'text-[var(--muted)] hover:bg-[var(--surface-soft)]'}`}
                    >
                      🇻🇳 Tiếng Việt
                    </button>
                    <button
                      type="button"
                      onClick={() => chooseLanguage('en')}
                      aria-pressed={language === 'en'}
                      aria-label={t('Use English')}
                      className={`rounded-lg px-2 py-2 text-[10px] font-semibold transition ${language === 'en' ? 'bg-violet-50 text-violet-700' : 'text-[var(--muted)] hover:bg-[var(--surface-soft)]'}`}
                    >
                      🇬🇧 English
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  )
}
