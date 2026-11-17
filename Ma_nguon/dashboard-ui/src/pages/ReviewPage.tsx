import { Check, MessageSquareText, Search, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { Modal } from '../components/common/Modal'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { Pagination } from '../components/common/Pagination'
import { Avatar } from '../components/common/Avatar'
import { formatNumber, timeAgo } from '../lib/utils'
import { useAppStore } from '../store/appStore'
import type { TranslationRow } from '../types'

export function ReviewPage() {
  const rows = useAppStore((state) => state.translations)
  const language = useAppStore((state) => state.language)
  const users = useAppStore((state) => state.users)
  const projects = useAppStore((state) => state.projects)
  const reviewTranslation = useAppStore((state) => state.reviewTranslation)
  const isMutating = useAppStore((state) => state.isMutating)
  const addToast = useAppStore((state) => state.addToast)
  const [query, setQuery] = useState('')
  const [projectFilter, setProjectFilter] = useState('All projects')
  const [statusFilter, setStatusFilter] = useState('Awaiting review')
  const [page, setPage] = useState(1)
  const [revisionTarget, setRevisionTarget] = useState<TranslationRow | null>(null)
  const [comment, setComment] = useState('')
  const [formError, setFormError] = useState('')
  const pageSize = 5

  const reviewRows = useMemo(() => rows.filter((row) => {
    const matchesText = `${row.key} ${row.source} ${row.translated}`.toLowerCase().includes(query.toLowerCase())
    const matchesProject = projectFilter === 'All projects' || row.projectId === projectFilter
    const matchesStatus = statusFilter === 'All statuses'
      || (statusFilter === 'Awaiting review' && row.status === 'In review')
      || row.status === statusFilter
    return matchesText && matchesProject && matchesStatus
  }), [projectFilter, query, rows, statusFilter])

  const handleApprove = async (row: TranslationRow) => {
    try {
      await reviewTranslation(row.id, 'Approved')
      addToast({ title: 'Translation approved', message: `${row.key} is ready to publish.`, tone: 'success' })
    } catch (error) {
      addToast({ title: 'Could not approve translation', message: error instanceof Error ? error.message : undefined, tone: 'error' })
    }
  }

  const handleRequestRevision = async () => {
    if (!revisionTarget) return
    if (comment.trim().length < 8) {
      setFormError('Add at least 8 characters so the translator has actionable feedback.')
      return
    }
    try {
      await reviewTranslation(revisionTarget.id, 'Needs revision', comment.trim())
      addToast({ title: 'Revision requested', message: `Feedback sent for ${revisionTarget.key}.`, tone: 'info' })
      setRevisionTarget(null)
      setComment('')
      setFormError('')
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Could not send this revision request.')
    }
  }

  const pagedRows = reviewRows.slice((page - 1) * pageSize, page * pageSize)
  const reviewerById = Object.fromEntries(users.map((user) => [user.id, user]))
  const projectById = Object.fromEntries(projects.map((project) => [project.id, project]))

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading eyebrow="Quality assurance" title="Review queue" description="Review translations, approve quality work, or return clear feedback to the translator." />
      <div className="grid gap-3 sm:grid-cols-3">
        {[
          { label: 'Awaiting review', count: rows.filter((row) => row.status === 'In review').length, tone: 'bg-amber-50 text-amber-700' },
          { label: 'Approved', count: rows.filter((row) => row.status === 'Approved').length, tone: 'bg-emerald-50 text-emerald-700' },
          { label: 'Needs revision', count: rows.filter((row) => row.status === 'Needs revision').length, tone: 'bg-rose-50 text-rose-700' },
        ].map((item) => <Panel key={item.label} className="flex items-center gap-3 p-4"><span className={`grid size-10 place-items-center rounded-xl ${item.tone}`}><MessageSquareText size={17} aria-hidden="true" /></span><div><p className="text-[11px] text-[var(--muted)]">{item.label}</p><p className="text-lg font-bold text-[var(--ink)]">{item.count}</p></div></Panel>)}
      </div>
      <Panel className="p-4 sm:p-5">
        <PanelHeading title="Translation submissions" subtitle="Select a status or project to focus your review." action={<Badge tone="violet">{reviewRows.length} strings</Badge>} />
        <div className="my-4 grid gap-2 sm:grid-cols-[minmax(220px,1fr)_200px_180px]">
          <label className="relative"><span className="sr-only">Search translations</span><Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" /><input value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} placeholder="Search key or text..." className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-xs outline-none focus:border-violet-400" /></label>
          <label><span className="sr-only">Filter project</span><select value={projectFilter} onChange={(event) => { setProjectFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"><option>All projects</option>{projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select></label>
          <label><span className="sr-only">Filter status</span><select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"><option>Awaiting review</option><option>All statuses</option><option>Approved</option><option>Needs revision</option><option>Draft</option></select></label>
        </div>
        <div className="space-y-3">
          {pagedRows.map((row) => {
            const translator = reviewerById[row.assigneeId]
            const project = projectById[row.projectId]
            return (
              <article key={row.id} className="rounded-2xl border border-[var(--line)] p-4 sm:p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0"><p className="font-mono text-[10px] font-bold text-violet-700">{row.key}</p><h2 className="mt-1 truncate text-sm font-bold text-[var(--ink)]">{project?.name ?? row.projectId}</h2></div>
                  <Badge tone={row.status === 'Approved' ? 'green' : row.status === 'Needs revision' ? 'rose' : row.status === 'In review' ? 'amber' : 'neutral'} dot>{row.status}</Badge>
                </div>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                  <div className="rounded-xl bg-[var(--surface-soft)] p-3.5"><p className="mb-1 text-[9px] font-bold uppercase tracking-wider text-[var(--muted)]">Source · {row.sourceLocale.toUpperCase()}</p><p className="text-xs leading-5 text-[var(--ink)]">{row.source}</p></div>
                  <div className="rounded-xl bg-violet-50/50 p-3.5"><p className="mb-1 text-[9px] font-bold uppercase tracking-wider text-violet-700">Translation · {row.targetLocale.toUpperCase()}</p><p className="text-xs leading-5 text-[var(--ink)]">{row.translated || <span className="italic text-[var(--muted)]">No translation submitted yet.</span>}</p></div>
                </div>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-2">
                    {translator && <Avatar user={translator} size="sm" />}
                    <span className="text-[10px] text-[var(--muted)]">{translator?.name ?? 'Unassigned'} · {timeAgo(row.updatedAt, language)} · {formatNumber(row.translated.length, language)} chars</span>
                  </div>
                  {row.status === 'In review' && <div className="flex gap-2"><Button size="sm" disabled={isMutating} icon={<X size={14} />} onClick={() => { setRevisionTarget(row); setComment(''); setFormError('') }}>Request changes</Button><Button size="sm" disabled={isMutating} variant="primary" icon={<Check size={14} />} onClick={() => { void handleApprove(row) }}>{isMutating ? 'Saving…' : 'Approve'}</Button></div>}
                </div>
                {row.comment && row.status === 'Needs revision' && <p className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-[11px] text-rose-800"><strong>Feedback sent:</strong> {row.comment}</p>}
              </article>
            )
          })}
          {pagedRows.length === 0 && <p className="rounded-xl border border-dashed border-[var(--line)] px-4 py-12 text-center text-xs text-[var(--muted)]">No translations match these filters.</p>}
        </div>
        <div className="mt-4"><Pagination page={page} pageSize={pageSize} total={reviewRows.length} onChange={setPage} /></div>
      </Panel>
      <Modal open={revisionTarget !== null} onClose={() => setRevisionTarget(null)} title="Request a revision" description="Be specific about what needs to change and why.">
        <form onSubmit={(event) => { event.preventDefault(); void handleRequestRevision() }} className="space-y-4">
          <label className="field-label">Feedback for {revisionTarget?.key}
            <textarea autoFocus required minLength={8} maxLength={500} rows={4} value={comment} onChange={(event) => setComment(event.target.value)} placeholder="e.g. Keep the product name untranslated and use a more concise tone." className="field-control resize-y" />
          </label>
          {formError && <p role="alert" className="text-xs font-medium text-rose-700">{formError}</p>}
          <div className="flex justify-end gap-2 border-t border-[var(--line)] pt-4"><Button onClick={() => setRevisionTarget(null)}>Cancel</Button><Button type="submit" variant="primary" disabled={isMutating}>{isMutating ? 'Sending…' : 'Send feedback'}</Button></div>
        </form>
      </Modal>
    </div>
    </LocalizedContent>
  )
}
