import {
  BarChart3,
  BookOpenCheck,
  ChevronLeft,
  CircleHelp,
  ClipboardCheck,
  FolderKanban,
  LayoutDashboard,
  LifeBuoy,
  ListChecks,
  PanelLeftClose,
  PanelLeftOpen,
  Settings2,
  ShieldCheck,
  Users,
  X,
} from 'lucide-react'
import { NavLink } from 'react-router-dom'
import type { LucideIcon } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useAppStore } from '../../store/appStore'
import type { ProjectRole } from '../../types'
import { translateText } from '../../lib/i18n'

interface NavigationItem {
  label: string
  to: string
  icon: LucideIcon
  roles: ProjectRole[]
  section: 'Workspace' | 'Manage'
  adminOnly?: boolean
}

const navigation: NavigationItem[] = [
  { label: 'Overview', to: '/dashboard', icon: LayoutDashboard, roles: ['Manager', 'Reviewer', 'Translator'], section: 'Workspace' },
  { label: 'Projects', to: '/projects', icon: FolderKanban, roles: ['Manager'], section: 'Workspace' },
  { label: 'Translation', to: '/translation', icon: BookOpenCheck, roles: ['Manager', 'Reviewer', 'Translator'], section: 'Workspace' },
  { label: 'Review queue', to: '/review', icon: ClipboardCheck, roles: ['Manager', 'Reviewer'], section: 'Workspace' },
  { label: 'Users & accounts', to: '/users', icon: Users, roles: [], section: 'Manage', adminOnly: true },
  { label: 'Support requests', to: '/support-requests', icon: LifeBuoy, roles: [], section: 'Manage', adminOnly: true },
  { label: 'Audit logs', to: '/logs', icon: ListChecks, roles: ['Manager', 'Reviewer', 'Translator'], section: 'Manage' },
  { label: 'Analytics', to: '/analytics', icon: BarChart3, roles: ['Manager', 'Reviewer', 'Translator'], section: 'Manage' },
  { label: 'Settings', to: '/settings', icon: Settings2, roles: [], section: 'Manage', adminOnly: true },
]

function Logo({ compact = false }: { compact?: boolean }) {
  const language = useAppStore((state) => state.language)
  const t = (text: string) => translateText(language, text)

  return (
    <div className={`flex items-center gap-3 ${compact ? 'px-5 py-5' : 'px-5 py-6'}`}>
      <span className="relative grid size-10 shrink-0 place-items-center rounded-[14px] bg-gradient-to-br from-violet-500 to-indigo-600 text-white shadow-lg shadow-indigo-950/25">
        <BookOpenCheck size={21} strokeWidth={2.2} aria-hidden="true" />
        <span className="absolute -right-1 -top-1 size-2.5 rounded-full border-2 border-[#111a31] bg-emerald-400" aria-hidden="true" />
      </span>
      {!compact && (
        <span className="min-w-0">
          <span className="block text-[15px] font-bold tracking-tight text-white">IAM<span className="text-violet-300">IOT</span></span>
          <span className="mt-0.5 block text-[10px] font-medium tracking-wide text-slate-400">{t('TRANSLATION STUDIO')}</span>
        </span>
      )}
    </div>
  )
}

function NavigationContent({ collapsed, onNavigate }: { collapsed: boolean; onNavigate?: () => void }) {
  const currentUser = useAppStore((state) => state.users.find((user) => user.id === state.currentUserId))
  const canManageUsers = useAppStore((state) => state.canManageUsers)
  const language = useAppStore((state) => state.language)
  const t = (text: string) => translateText(language, text)
  const projectRoles = new Set(currentUser?.projectRoles.map((assignment) => assignment.role))
  const isSystemAdmin = currentUser?.accountRole === 'admin' || canManageUsers
  const sections = ['Workspace', 'Manage'] as const

  return (
    <nav className="flex-1 overflow-y-auto px-3 pb-4" aria-label={t('Main navigation')}>
      {sections.map((section) => {
        const items = navigation.filter((item) => item.section === section
          && (item.to === '/dashboard'
            || (item.adminOnly ? isSystemAdmin : isSystemAdmin || item.roles.some((role) => projectRoles.has(role)))))
        if (items.length === 0) return null
        return (
          <div className="mb-6" key={section}>
            {!collapsed && <p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">{t(section)}</p>}
            <ul className="space-y-1">
              {items.map(({ label, to, icon: Icon }) => (
                <li key={to}>
                  <NavLink
                    to={to}
                    onClick={onNavigate}
                    title={collapsed ? t(label) : undefined}
                    className={({ isActive }) => `nav-item group ${collapsed ? 'justify-center px-0' : ''} ${isActive ? 'nav-item-active' : ''}`}
                    aria-label={collapsed ? t(label) : undefined}
                  >
                    <Icon size={18} strokeWidth={1.9} aria-hidden="true" />
                    {!collapsed && <span className="truncate">{t(label)}</span>}
                    {!collapsed && label === 'Review queue' && (
                      <span className="ml-auto rounded-full bg-amber-400/15 px-2 py-0.5 text-[10px] font-bold text-amber-300">12</span>
                    )}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
      <div className={`mt-auto rounded-2xl border border-white/8 bg-white/[0.045] p-3.5 ${collapsed ? 'hidden' : ''}`}>
        <div className="mb-3 flex items-center justify-between">
          <span className="flex items-center gap-2 text-xs font-semibold text-slate-200"><ShieldCheck size={15} className="text-emerald-400" aria-hidden="true" /> {t('Translation ops')}</span>
          <span className="size-2 rounded-full bg-emerald-400 shadow-[0_0_10px_#34d399]" aria-label={t('All services operational')} />
        </div>
        <p className="text-[11px] leading-5 text-slate-400">{t('Workspace data is served by the authenticated PHP API.')}</p>
        <div className="mt-3 flex items-center gap-1.5 text-[10px] text-slate-500">
          <CircleHelp size={13} aria-hidden="true" /> {t('Database-backed workspace')}
        </div>
      </div>
    </nav>
  )
}

export function Sidebar({ collapsed, onToggle }: { collapsed: boolean; onToggle: () => void }) {
  const language = useAppStore((state) => state.language)
  const t = (text: string) => translateText(language, text)

  return (
    <aside className={`sidebar-shell hidden shrink-0 flex-col border-r border-white/5 bg-[#111a31] transition-[width] duration-200 lg:flex ${collapsed ? 'w-[84px]' : 'w-[252px]'}`}>
      <Logo compact={collapsed} />
      <NavigationContent collapsed={collapsed} />
      <div className="border-t border-white/8 p-3">
        <button type="button" className={`nav-item w-full text-slate-400 hover:text-white ${collapsed ? 'justify-center px-0' : ''}`} onClick={onToggle} aria-label={t(collapsed ? 'Expand sidebar' : 'Collapse sidebar')}>
          {collapsed ? <PanelLeftOpen size={18} aria-hidden="true" /> : <><PanelLeftClose size={18} aria-hidden="true" /><span>{t('Collapse sidebar')}</span><ChevronLeft size={14} className="ml-auto" aria-hidden="true" /></>}
        </button>
      </div>
    </aside>
  )
}

export function MobileSidebar({ open, onClose }: { open: boolean; onClose: () => void }) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const language = useAppStore((state) => state.language)
  const t = (text: string) => translateText(language, text)

  useEffect(() => {
    const dialog = dialogRef.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={dialogRef}
      id="mobile-navigation"
      className="mobile-drawer m-0 h-dvh max-h-none w-[min(84vw,300px)] max-w-none border-0 bg-[#111a31] p-0 text-white shadow-2xl backdrop:bg-slate-950/55"
      aria-label={t('Mobile main navigation')}
      onClose={onClose}
      onCancel={(event) => {
        event.preventDefault()
        dialogRef.current?.close()
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.preventDefault()
          dialogRef.current?.close()
        }
      }}
      onClick={(event) => {
        if (event.target === event.currentTarget) dialogRef.current?.close()
      }}
    >
      <div className="flex h-full flex-col">
        <div className="flex items-center justify-between pr-3">
          <Logo />
          <button type="button" autoFocus onClick={() => dialogRef.current?.close()} className="grid size-9 place-items-center rounded-xl text-slate-300 hover:bg-white/10" aria-label={t('Close navigation')}>
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <NavigationContent collapsed={false} onNavigate={() => dialogRef.current?.close()} />
        <p className="px-5 py-4 text-[10px] text-slate-500">{t('IAMIOT · Translation workspace')}</p>
      </div>
    </dialog>
  )
}

