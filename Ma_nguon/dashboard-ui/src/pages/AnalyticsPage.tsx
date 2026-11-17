import { Activity, CheckCheck, Languages, TrendingUp } from 'lucide-react'
import { useMemo } from 'react'
import { Area, AreaChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Badge } from '../components/common/Badge'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { formatNumber } from '../lib/utils'
import { useAppStore } from '../store/appStore'

const palette = ['#7566f6', '#1aa987', '#f0a04b', '#e77791', '#3897dc', '#8a6fd1']

export function AnalyticsPage() {
  const projects = useAppStore((state) => state.projects)
  const language = useAppStore((state) => state.language)
  const translations = useAppStore((state) => state.translations)
  const users = useAppStore((state) => state.users)
  const logs = useAppStore((state) => state.logs)
  const weeklyProgress = Array.from({ length: 7 }, (_, index) => {
    const date = new Date()
    date.setUTCHours(0, 0, 0, 0)
    date.setUTCDate(date.getUTCDate() - (6 - index))
    const dateKey = date.toISOString().slice(0, 10)
    const dayLogs = logs.filter((log) => log.createdAt.slice(0, 10) === dateKey)
    return {
      day: new Intl.DateTimeFormat(language === 'vi' ? 'vi-VN' : 'en-US', { weekday: 'short', timeZone: 'UTC' }).format(date),
      translated: dayLogs.filter((log) => log.action === 'Translation saved').length,
      reviewed: dayLogs.filter((log) => ['Translation approved', 'Revision requested'].includes(log.action)).length,
    }
  })
  const localizedStats = useMemo(() => {
    const locales = [...new Set(projects.flatMap((project) => project.targetLocales))]
    return locales.map((locale) => {
      const entries = translations.filter((row) => row.targetLocale === locale)
      const completed = entries.filter((row) => row.status === 'Approved' || row.status === 'In review').length
      return {
        locale: new Intl.DisplayNames(language === 'vi' ? 'vi-VN' : 'en-US', { type: 'language' }).of(locale) ?? locale.toUpperCase(),
        code: locale.toUpperCase(),
        progress: entries.length ? Math.round((completed / entries.length) * 100) : 0,
        completed,
        total: entries.length,
      }
    })
  }, [language, projects, translations])
  const statusStats = [
    { name: 'Approved', value: translations.filter((row) => row.status === 'Approved').length },
    { name: 'In review', value: translations.filter((row) => row.status === 'In review').length },
    { name: 'Draft', value: translations.filter((row) => row.status === 'Draft').length },
    { name: 'Needs revision', value: translations.filter((row) => row.status === 'Needs revision').length },
  ].filter((item) => item.value > 0)
  const totalWords = projects.reduce((total, project) => total + project.words, 0)
  const activeProjects = projects.filter((project) => project.status === 'Active' || project.status === 'Review').length
  const completedEntries = translations.filter((row) => row.translated.trim()).length

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading eyebrow="Performance insights" title="Analytics" description="Track delivery pace, translation quality, team capacity, and language coverage." action={<Badge tone="green" dot>Updated just now</Badge>} />
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {[
          { label: 'Words in projects', value: formatNumber(totalWords, language), change: 'Across active workspaces', icon: Languages, color: 'bg-violet-50 text-violet-600' },
          { label: 'Active projects', value: formatNumber(activeProjects, language), change: `${projects.length} total projects`, icon: Activity, color: 'bg-blue-50 text-blue-600' },
          { label: 'Submitted strings', value: formatNumber(completedEntries, language), change: `${translations.length} workspace strings`, icon: CheckCheck, color: 'bg-emerald-50 text-emerald-600' },
          { label: 'Team members', value: formatNumber(users.filter((user) => user.active).length, language), change: 'Across three roles', icon: TrendingUp, color: 'bg-amber-50 text-amber-600' },
        ].map((item) => <Panel key={item.label} className="p-4"><div className="flex items-start justify-between"><div><p className="text-[11px] text-[var(--muted)]">{item.label}</p><p className="mt-1.5 text-xl font-bold tracking-tight text-[var(--ink)]">{item.value}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{item.change}</p></div><span className={`grid size-10 place-items-center rounded-xl ${item.color}`}><item.icon size={18} aria-hidden="true" /></span></div></Panel>)}
      </div>
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(280px,.8fr)]">
        <Panel className="p-4 sm:p-5">
          <PanelHeading title="Weekly throughput" subtitle="Strings translated and reviewed each day" action={<div className="flex gap-3 text-[10px]"><span className="flex items-center gap-1.5 text-[var(--muted)]"><i className="size-2 rounded-full bg-violet-500" />Translated</span><span className="flex items-center gap-1.5 text-[var(--muted)]"><i className="size-2 rounded-full bg-emerald-500" />Reviewed</span></div>} />
          <div className="mt-5 h-72 w-full" role="img" aria-label="Area chart of translated and reviewed strings during the week">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={weeklyProgress} margin={{ top: 12, right: 12, left: -18, bottom: 0 }}>
                <defs><linearGradient id="translatedFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#7566f6" stopOpacity={0.22} /><stop offset="95%" stopColor="#7566f6" stopOpacity={0} /></linearGradient><linearGradient id="reviewedFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#1aa987" stopOpacity={0.17} /><stop offset="95%" stopColor="#1aa987" stopOpacity={0} /></linearGradient></defs>
                <CartesianGrid stroke="var(--chart-grid)" vertical={false} strokeDasharray="3 5" />
                <XAxis dataKey="day" axisLine={false} tickLine={false} tick={{ fontSize: 10, fill: 'var(--muted)' }} dy={8} />
                <YAxis axisLine={false} tickLine={false} tick={{ fontSize: 10, fill: 'var(--muted)' }} />
                <Tooltip contentStyle={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 12, color: 'var(--ink)', fontSize: 11 }} />
                <Area type="monotone" dataKey="translated" name="Translated" stroke="#7566f6" strokeWidth={2.5} fill="url(#translatedFill)" />
                <Area type="monotone" dataKey="reviewed" name="Reviewed" stroke="#1aa987" strokeWidth={2.5} fill="url(#reviewedFill)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Panel>
        <Panel className="p-4 sm:p-5">
          <PanelHeading title="Translation status" subtitle="Sample strings by workflow stage" />
          <div className="relative mt-3 h-56" role="img" aria-label="Donut chart showing translation status">
            <ResponsiveContainer width="100%" height="100%"><PieChart><Pie data={statusStats} dataKey="value" nameKey="name" innerRadius={62} outerRadius={88} paddingAngle={4} stroke="none">{statusStats.map((entry, index) => <Cell key={entry.name} fill={palette[index % palette.length]} />)}</Pie><Tooltip contentStyle={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 12, color: 'var(--ink)', fontSize: 11 }} /></PieChart></ResponsiveContainer>
            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center"><div><p className="text-xl font-bold text-[var(--ink)]">{translations.length}</p><p className="text-[9px] text-[var(--muted)]">strings</p></div></div>
          </div>
          <div className="space-y-2.5">
            {statusStats.map((item, index) => <div key={item.name} className="flex items-center justify-between text-[11px]"><span className="flex items-center gap-2 text-[var(--muted)]"><i className="size-2 rounded-full" style={{ backgroundColor: palette[index % palette.length] }} />{item.name}</span><strong className="text-[var(--ink)]">{item.value}</strong></div>)}
          </div>
        </Panel>
      </div>
      <Panel className="p-4 sm:p-5">
        <PanelHeading title="Progress by language" subtitle="Completion calculated from saved strings in the workspace" />
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {localizedStats.map((language, index) => <div key={language.code} className="rounded-xl border border-[var(--line)] p-4">
            <div className="flex items-center justify-between gap-2"><div className="flex items-center gap-2.5"><span className="grid size-9 place-items-center rounded-xl text-[10px] font-bold" style={{ backgroundColor: `${palette[index % palette.length]}18`, color: palette[index % palette.length] }}>{language.code}</span><div><p className="text-xs font-bold text-[var(--ink)]">{language.locale}</p><p className="text-[10px] text-[var(--muted)]">{language.completed} of {language.total} submitted</p></div></div><span className="text-xs font-bold text-[var(--ink)]">{language.progress}%</span></div>
            <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-[var(--surface-soft)]"><span className="block h-full rounded-full transition-[width]" style={{ width: `${language.progress}%`, backgroundColor: palette[index % palette.length] }} /></div>
          </div>)}
          {localizedStats.length === 0 && <p className="col-span-full py-8 text-center text-xs text-[var(--muted)]">Add target languages to a project to see language analytics.</p>}
        </div>
        <p className="mt-4 text-[10px] leading-5 text-[var(--muted)]">Throughput is calculated from audit events in the last seven days. Status and language completion use current database records.</p>
      </Panel>
    </div>
    </LocalizedContent>
  )
}
