import {
  ArrowDownRight,
  ArrowRight,
  CheckCheck,
  Clock3,
  FileText,
  Languages,
  ListChecks,
  Plus,
  RefreshCw,
  Sparkles,
  Target,
  Users,
  Text,
} from 'lucide-react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Link, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { Avatar, AvatarStack } from '../components/common/Avatar'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { formatNumber, timeAgo } from '../lib/utils'
import { useAppStore } from '../store/appStore'

const chartTooltipStyle = {
  background: 'var(--surface)',
  border: '1px solid var(--line)',
  borderRadius: 12,
  boxShadow: '0 12px 30px rgba(20, 32, 60, .12)',
  color: 'var(--ink)',
  fontSize: 12,
}

function MetricCard({
  label,
  value,
  tone,
  icon: Icon,
  hint,
}: {
  label: string
  value: string
  tone: 'violet' | 'emerald' | 'blue' | 'amber'
  icon: typeof Text
  hint: string
}) {
  const tones = {
    violet: 'bg-violet-50 text-violet-600',
    emerald: 'bg-emerald-50 text-emerald-600',
    blue: 'bg-blue-50 text-blue-600',
    amber: 'bg-amber-50 text-amber-600',
  }
  return (
    <Panel className="relative overflow-hidden p-4 sm:p-5">
      <div className={`mb-4 grid size-10 place-items-center rounded-xl ${tones[tone]}`}>
        <Icon size={19} strokeWidth={2} aria-hidden="true" />
      </div>
      <p className="text-xs font-medium text-[var(--muted)]">{label}</p>
      <div className="mt-1 flex flex-wrap items-baseline gap-2">
        <strong className="text-[26px] font-bold tracking-tight text-[var(--ink)]">{value}</strong>
      </div>
      <p className="mt-1 text-[10px] text-[var(--muted)]">{hint}</p>
      <div className={`pointer-events-none absolute -right-7 -top-8 size-24 rounded-full opacity-30 blur-2xl ${tones[tone].split(' ')[0]}`} aria-hidden="true" />
    </Panel>
  )
}

function ProgressBar({ value, color = 'bg-violet-500' }: { value: number; color?: string }) {
  return (
    <div className="h-1.5 overflow-hidden rounded-full bg-[var(--surface-soft)]" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100} aria-label={`${value}% complete`}>
      <span className={`block h-full rounded-full transition-[width] duration-700 ${color}`} style={{ width: `${value}%` }} />
    </div>
  )
}

export function DashboardPage() {
  const navigate = useNavigate()
  const [now] = useState(() => new Date())
  const projects = useAppStore((state) => state.projects)
  const language = useAppStore((state) => state.language)
  const users = useAppStore((state) => state.users)
  const logs = useAppStore((state) => state.logs)
  const translations = useAppStore((state) => state.translations)
  const locales = useAppStore((state) => state.locales)
  const addToast = useAppStore((state) => state.addToast)
  const loadWorkspace = useAppStore((state) => state.loadWorkspace)
  const isRefreshing = useAppStore((state) => state.isRefreshing)
  const currentUserId = useAppStore((state) => state.currentUserId)
  const currentUser = users.find((user) => user.id === currentUserId)
  const translatedCount = translations.filter((row) => row.translated.trim()).length
  const reviewCount = translations.filter((row) => row.status === 'In review').length
  const averageProgress = projects.length
    ? Math.round(projects.reduce((sum, project) => sum + project.progress, 0) / projects.length)
    : 0
  const recentProjects = [...projects].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)).slice(0, 4)
  const userById = Object.fromEntries(users.map((user) => [user.id, user]))
  const projectRoles = new Set(currentUser?.projectRoles.map((assignment) => assignment.role) ?? [])
  const isSystemAdmin = currentUser?.accountRole === 'admin'
  const workspacePath = isSystemAdmin ? '/users'
    : projectRoles.has('Manager') ? '/projects'
      : projectRoles.has('Reviewer') ? '/review'
        : projectRoles.has('Translator') ? '/translation'
          : '/dashboard'
  const greeting = now.getHours() < 12 ? 'morning' : now.getHours() < 18 ? 'afternoon' : 'evening'
  const locale = language === 'vi' ? 'vi-VN' : 'en-US'
  const dateLabel = new Intl.DateTimeFormat(locale, { weekday: 'long', month: 'long', day: 'numeric' }).format(now)
  const languageCount = new Set(projects.flatMap((project) => project.targetLocales)).size
  const weeklyProgress = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(now)
    date.setUTCHours(0, 0, 0, 0)
    date.setUTCDate(date.getUTCDate() - (6 - index))
    const dateKey = date.toISOString().slice(0, 10)
    const dayLogs = logs.filter((log) => log.createdAt.slice(0, 10) === dateKey)
    return {
      day: new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(date),
      translated: dayLogs.filter((log) => log.action === 'Translation saved').length,
      reviewed: dayLogs.filter((log) => ['Translation approved', 'Revision requested'].includes(log.action)).length,
    }
  })
  const languageProgress = [...new Set(projects.flatMap((project) => project.targetLocales))].map((code) => {
    const rows = translations.filter((row) => row.targetLocale === code)
    const completed = rows.filter((row) => ['In review', 'Approved'].includes(row.status)).length
    return {
      code: code.toUpperCase(),
      locale: locales.find((locale) => locale.code === code)?.name ?? code.toUpperCase(),
      progress: rows.length ? Math.round((completed / rows.length) * 100) : 0,
      completed,
      color: '#6558f5',
    }
  })
  const peakDay = weeklyProgress.reduce((peak, day) => day.translated > peak.translated ? day : peak, weeklyProgress[0])

  return (
    <LocalizedContent>
    <div className="space-y-6">
      <PageHeading
        eyebrow={dateLabel}
        title={`Good ${greeting}, ${currentUser?.name.split(/\s+/)[0] ?? 'there'} 👋`}
        description="Here’s what’s happening across your localization projects today."
        action={(
          <div className="flex items-center gap-2">
            <Button icon={<RefreshCw size={16} />} disabled={isRefreshing} onClick={() => {
              void loadWorkspace(true).then(() => addToast({ title: 'Workspace refreshed', tone: 'success' })).catch((error: unknown) => addToast({ title: 'Could not refresh workspace', message: error instanceof Error ? error.message : undefined, tone: 'error' }))
            }}>{isRefreshing ? 'Refreshing…' : 'Refresh data'}</Button>
            <Link to={workspacePath} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-violet-600 px-4 text-sm font-semibold text-white shadow-sm shadow-violet-600/20 transition hover:bg-violet-700">
              {isSystemAdmin
                ? <><Users size={16} aria-hidden="true" /> Manage accounts</>
                : projectRoles.has('Manager')
                  ? <><Plus size={16} aria-hidden="true" /> Manage projects</>
                  : projectRoles.has('Reviewer')
                    ? <><ArrowRight size={16} aria-hidden="true" /> Open review queue</>
                    : projectRoles.has('Translator')
                      ? <><ArrowRight size={16} aria-hidden="true" /> Open translation queue</>
                      : <><ArrowRight size={16} aria-hidden="true" /> Workspace overview</>}
            </Link>
          </div>
        )}
      />

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Workspace key metrics">
        <MetricCard label="Active projects" value={String(projects.filter((project) => ['Active', 'Review'].includes(project.status)).length).padStart(2, '0')} hint="Current workspace" tone="violet" icon={Target} />
        <MetricCard label="Words in projects" value={formatNumber(projects.reduce((sum, project) => sum + project.words, 0), language)} hint={`${formatNumber(translatedCount, language)} strings submitted`} tone="emerald" icon={Text} />
        <MetricCard label="Awaiting review" value={String(reviewCount).padStart(2, '0')} hint="Across current workspace" tone="blue" icon={Clock3} />
        <MetricCard label="Avg. completion" value={`${averageProgress}%`} hint="Current project average" tone="amber" icon={CheckCheck} />
      </section>

      <section className="grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,.8fr)]">
        <Panel className="min-w-0 p-4 sm:p-5">
          <PanelHeading title="Translation activity" subtitle="Words completed across the workspace" action={<Badge tone="neutral">Last 7 days</Badge>} />
          <div className="mt-4 h-[250px] w-full" role="img" aria-label="Area chart of translated and reviewed words through the week">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={weeklyProgress} margin={{ left: -18, right: 4, top: 10, bottom: 0 }}>
                <defs>
                  <linearGradient id="translatedFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#6558f5" stopOpacity={0.19} />
                    <stop offset="95%" stopColor="#6558f5" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="reviewedFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#18a782" stopOpacity={0.13} />
                    <stop offset="95%" stopColor="#18a782" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid vertical={false} stroke="var(--line)" strokeDasharray="4 5" />
                <XAxis dataKey="day" axisLine={false} tickLine={false} tick={{ fill: 'var(--muted)', fontSize: 11 }} dy={10} />
                <YAxis axisLine={false} tickLine={false} tick={{ fill: 'var(--muted)', fontSize: 10 }} tickFormatter={(value: number) => `${value}`} />
                <Tooltip contentStyle={chartTooltipStyle} cursor={{ stroke: 'var(--line)' }} />
                <Area type="monotone" dataKey="translated" name="Translated" stroke="#6558f5" strokeWidth={2.5} fill="url(#translatedFill)" activeDot={{ r: 5 }} />
                <Area type="monotone" dataKey="reviewed" name="Reviewed" stroke="#18a782" strokeWidth={2.5} fill="url(#reviewedFill)" activeDot={{ r: 5 }} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
          <div className="mt-1 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--line)] pt-4">
            <div className="flex flex-wrap gap-4 text-xs text-[var(--muted)]">
              <span className="inline-flex items-center gap-2"><i className="size-2 rounded-full bg-violet-500" aria-hidden="true" /> Translated</span>
              <span className="inline-flex items-center gap-2"><i className="size-2 rounded-full bg-emerald-500" aria-hidden="true" /> Reviewed</span>
            </div>
            <p className="text-xs text-[var(--muted)]">Peak day <strong className="text-[var(--ink)]">{peakDay?.day ?? '—'}</strong> · {peakDay?.translated ?? 0} saved translations</p>
          </div>
        </Panel>

        <Panel className="p-4 sm:p-5">
          <PanelHeading title="Language coverage" subtitle="Progress by target locale" action={<Link to="/analytics" className="text-xs font-semibold text-violet-600 hover:text-violet-700">See all</Link>} />
          <div className="mt-5 space-y-5">
            {languageProgress.map((language) => (
              <div key={language.code}>
                <div className="mb-2 flex items-center justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-2.5">
                    <span className="grid size-8 shrink-0 place-items-center rounded-xl bg-[var(--surface-soft)] text-[9px] font-bold text-[var(--muted)]">{language.code}</span>
                    <span className="truncate text-xs font-semibold text-[var(--ink)]">{language.locale}</span>
                  </div>
                  <span className="text-xs font-bold text-[var(--ink)]">{language.progress}%</span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-[var(--surface-soft)]">
                  <span className="block h-full rounded-full transition-[width] duration-700" style={{ width: `${language.progress}%`, background: language.color }} />
                </div>
                <p className="mt-1.5 text-right text-[10px] text-[var(--muted)]">{language.completed} strings submitted</p>
              </div>
            ))}
          </div>
          <div className="mt-5 rounded-xl bg-violet-50/80 p-3.5">
            <p className="flex items-center gap-2 text-xs font-bold text-violet-800"><Languages size={15} aria-hidden="true" /> {languageCount} languages in progress</p>
            <p className="mt-1 text-[11px] leading-5 text-violet-700/80">Vietnamese is closest to the launch target.</p>
          </div>
        </Panel>
      </section>

      <section className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(300px,.9fr)]">
        <Panel className="min-w-0 p-4 sm:p-5">
          <PanelHeading title="Your projects" subtitle={`${projects.length} projects · recently updated`} action={(isSystemAdmin || projectRoles.has('Manager')) && <Link to="/projects" className="inline-flex items-center gap-1 text-xs font-semibold text-violet-600 hover:text-violet-700">All projects <ArrowRight size={13} aria-hidden="true" /></Link>} />
          <div className="mt-4 divide-y divide-[var(--line)]">
            {recentProjects.map((project) => {
              const team = [project.managerId, ...project.translatorIds, ...project.reviewerIds].map((id) => userById[id]).filter(Boolean)
              const color = project.status === 'Completed' ? 'bg-emerald-500' : project.status === 'On hold' ? 'bg-amber-500' : 'bg-violet-500'
              return (
                <article key={project.id} className="grid gap-3 py-3.5 sm:grid-cols-[minmax(0,1fr)_112px_92px] sm:items-center">
                  <div className="flex min-w-0 items-center gap-3">
                    <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${project.accent === 'blue' ? 'bg-blue-50 text-blue-600' : project.accent === 'emerald' ? 'bg-emerald-50 text-emerald-600' : project.accent === 'amber' ? 'bg-amber-50 text-amber-600' : project.accent === 'rose' ? 'bg-rose-50 text-rose-600' : project.accent === 'cyan' ? 'bg-cyan-50 text-cyan-600' : 'bg-violet-50 text-violet-600'}`}>
                      <Languages size={18} aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                      <h3 className="truncate text-xs font-bold text-[var(--ink)]">{project.name}</h3>
                      <p className="mt-1 truncate text-[10px] text-[var(--muted)]">{project.id} · {project.targetLocales.length} locales</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2.5">
                    <ProgressBar value={project.progress} color={color} />
                    <span className="w-8 text-right text-[11px] font-bold text-[var(--ink)]">{project.progress}%</span>
                  </div>
                  <div className="flex items-center justify-between sm:justify-end sm:gap-3">
                    <AvatarStack users={team} limit={2} />
                    <Badge tone={project.status === 'Completed' ? 'green' : project.status === 'Review' ? 'amber' : 'neutral'}>{project.status}</Badge>
                  </div>
                </article>
              )
            })}
          </div>
        </Panel>

        <Panel className="p-4 sm:p-5">
          <PanelHeading title="Recent activity" subtitle="Latest changes from your team" action={<Link to="/logs" className="text-xs font-semibold text-violet-600 hover:text-violet-700">View all</Link>} />
          <div className="mt-4 space-y-4">
            {logs.slice(0, 4).map((log) => {
              const actor = userById[log.actorId]
              const icon = log.action.includes('approved') ? <CheckCheck size={14} /> : log.action.includes('Project') ? <FileText size={14} /> : <ListChecks size={14} />
              return (
                <div key={log.id} className="flex gap-3">
                  <div className="relative">
                    {actor ? <Avatar user={actor} size="sm" /> : <span className="grid size-7 place-items-center rounded-full bg-[var(--surface-soft)] text-[10px] text-[var(--muted)]">?</span>}
                    <span className="absolute -bottom-1 -right-1 grid size-4 place-items-center rounded-full border border-[var(--surface)] bg-[var(--surface-soft)] text-[var(--muted)]">{icon}</span>
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-xs leading-5 text-[var(--ink)]"><strong>{actor?.name ?? 'Former member'}</strong> {log.action.toLowerCase()} <span className="font-semibold">{log.target}</span></p>
                    <p className="mt-0.5 text-[10px] text-[var(--muted)]">{timeAgo(log.createdAt, language)}</p>
                  </div>
                </div>
              )
            })}
          </div>
          <div className="mt-5 grid grid-cols-2 gap-2 border-t border-[var(--line)] pt-4">
            <div className="rounded-xl bg-[var(--surface-soft)] p-3">
              <p className="text-[10px] text-[var(--muted)]">Team members</p>
              <p className="mt-1 flex items-center gap-1.5 text-sm font-bold text-[var(--ink)]"><Users size={14} className="text-violet-600" aria-hidden="true" /> {users.filter((user) => user.active).length} active</p>
            </div>
            <div className="rounded-xl bg-[var(--surface-soft)] p-3">
              <p className="text-[10px] text-[var(--muted)]">Open reviews</p>
              <p className="mt-1 flex items-center gap-1.5 text-sm font-bold text-[var(--ink)]"><ListChecks size={14} className="text-amber-600" aria-hidden="true" /> {reviewCount} waiting</p>
            </div>
          </div>
        </Panel>
      </section>

      <Panel className="flex flex-col gap-3 bg-gradient-to-r from-[#211a55] to-[#352c79] p-5 text-white sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div className="flex items-start gap-3">
          <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-white/10"><Sparkles size={19} aria-hidden="true" /></span>
          <div>
            <h2 className="text-sm font-bold">Your localization workspace at a glance</h2>
            <p className="mt-1 text-xs text-violet-100/75">{translatedCount} strings submitted across {projects.length} projects.</p>
          </div>
        </div>
        <Button className="shrink-0 border-white/15 bg-white/10 text-white hover:bg-white/15" icon={<ArrowDownRight size={15} />} onClick={() => navigate('/analytics')}>Explore analytics</Button>
      </Panel>
    </div>
    </LocalizedContent>
  )
}

