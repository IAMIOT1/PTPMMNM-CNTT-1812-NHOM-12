import { Download, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { DataTable, type TableColumn } from '../components/common/DataTable'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { Pagination } from '../components/common/Pagination'
import { useAppStore } from '../store/appStore'
import type { AuditEntry, LogAction } from '../types'

const actionTones: Record<LogAction, 'violet' | 'blue' | 'rose' | 'green' | 'amber' | 'neutral'> = {
  'Project created': 'green',
  'Project updated': 'blue',
  'Project deleted': 'rose',
  'Assignment changed': 'violet',
  'Translation saved': 'blue',
  'Translation approved': 'green',
  'Revision requested': 'amber',
  'Settings updated': 'neutral',
  'User created': 'green',
  'User updated': 'blue',
  'User deleted': 'rose',
  'Support request status updated': 'amber',
}

function csvCell(value: string) {
  const safe = /^[=+\-@]/.test(value) ? `'${value}` : value
  return `"${safe.replaceAll('"', '""')}"`
}

export function LogsPage() {
  const logs = useAppStore((state) => state.logs)
  const language = useAppStore((state) => state.language)
  const users = useAppStore((state) => state.users)
  const projects = useAppStore((state) => state.projects)
  const [query, setQuery] = useState('')
  const [actionFilter, setActionFilter] = useState('All actions')
  const [projectFilter, setProjectFilter] = useState('All projects')
  const [page, setPage] = useState(1)
  const pageSize = 10
  const filteredLogs = useMemo(() => logs.filter((log) => (
    `${log.action} ${log.target} ${log.details}`.toLowerCase().includes(query.toLowerCase())
    && (actionFilter === 'All actions' || log.action === actionFilter)
    && (projectFilter === 'All projects' || log.projectId === projectFilter)
  )), [actionFilter, logs, projectFilter, query])
  const userById = Object.fromEntries(users.map((user) => [user.id, user]))
  const projectById = Object.fromEntries(projects.map((project) => [project.id, project]))
  const columns: TableColumn<AuditEntry>[] = [
    { key: 'time', header: 'Date & time', sortValue: (log) => log.createdAt, render: (log) => <span className="whitespace-nowrap text-xs text-[var(--muted)]">{new Intl.DateTimeFormat(language === 'vi' ? 'vi-VN' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(log.createdAt))}</span> },
    { key: 'actor', header: 'Actor', sortValue: (log) => userById[log.actorId]?.name ?? 'Removed member', render: (log) => <span className="text-xs font-semibold text-[var(--ink)]">{userById[log.actorId]?.name ?? 'Removed member'}</span> },
    { key: 'action', header: 'Action', sortValue: (log) => log.action, render: (log) => <Badge tone={actionTones[log.action]}>{log.action}</Badge> },
    { key: 'target', header: 'Target & details', sortValue: (log) => log.target, render: (log) => <div className="max-w-[360px]"><p className="truncate text-xs font-semibold text-[var(--ink)]">{log.target}</p><p className="mt-1 line-clamp-2 text-[10px] leading-4 text-[var(--muted)]">{log.details}</p></div> },
    { key: 'project', header: 'Project', sortValue: (log) => projectById[log.projectId ?? '']?.name ?? '', render: (log) => <span className="text-xs text-[var(--muted)]">{projectById[log.projectId ?? '']?.name ?? 'Workspace'}</span> },
  ]

  const exportCsv = () => {
    const header = ['Timestamp', 'Actor', 'Action', 'Target', 'Details', 'Project']
    const lines = filteredLogs.map((log) => [
      log.createdAt,
      userById[log.actorId]?.name ?? 'Removed member',
      log.action,
      log.target,
      log.details,
      projectById[log.projectId ?? '']?.name ?? 'Workspace',
    ].map(csvCell).join(','))
    const blob = new Blob([[header.map(csvCell).join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `linguadesk-audit-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading eyebrow="Workspace activity" title="Audit logs" description="A persistent, searchable history of project, account, assignment, translation, and review actions." action={<Button icon={<Download size={15} />} onClick={exportCsv}>Export CSV</Button>} />
      <div className="grid gap-3 sm:grid-cols-3">
        <Panel className="p-4"><p className="text-[11px] text-[var(--muted)]">Events retained</p><p className="mt-1 text-xl font-bold text-[var(--ink)]">{logs.length}</p></Panel>
        <Panel className="p-4"><p className="text-[11px] text-[var(--muted)]">Most recent event</p><p className="mt-1 text-sm font-bold text-[var(--ink)]">{logs[0]?.action ?? 'No events yet'}</p></Panel>
        <Panel className="p-4"><p className="text-[11px] text-[var(--muted)]">Storage</p><p className="mt-1 text-sm font-bold text-emerald-700">Database audit log</p></Panel>
      </div>
      <Panel className="p-4 sm:p-5">
        <PanelHeading title="Activity history" subtitle="Audit entries are read from the database and retained across sessions." action={<Badge tone="violet">{filteredLogs.length} events</Badge>} />
        <div className="my-4 grid gap-2 sm:grid-cols-[minmax(220px,1fr)_200px_200px]">
          <label className="relative"><span className="sr-only">Search audit logs</span><Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" /><input value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} placeholder="Search events, people, or details..." className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-xs outline-none focus:border-violet-400" /></label>
          <label><span className="sr-only">Filter action</span><select value={actionFilter} onChange={(event) => { setActionFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"><option>All actions</option>{Object.keys(actionTones).map((action) => <option key={action}>{action}</option>)}</select></label>
          <label><span className="sr-only">Filter project</span><select value={projectFilter} onChange={(event) => { setProjectFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"><option>All projects</option>{projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select></label>
        </div>
        <DataTable rows={filteredLogs.slice((page - 1) * pageSize, page * pageSize)} columns={columns} caption="Audit log history" empty="No audit events match these filters." />
        <div className="mt-4"><Pagination page={page} pageSize={pageSize} total={filteredLogs.length} onChange={setPage} /></div>
      </Panel>
    </div>
    </LocalizedContent>
  )
}
