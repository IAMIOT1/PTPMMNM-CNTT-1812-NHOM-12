import { Edit3, FolderPlus, Plus, Search, Trash2 } from 'lucide-react'
import { useMemo, useState, type FormEvent } from 'react'
import { AvatarStack } from '../components/common/Avatar'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { DataTable, type TableColumn } from '../components/common/DataTable'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { Modal } from '../components/common/Modal'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { Pagination } from '../components/common/Pagination'
import { formatNumber, getFriendlyDate } from '../lib/utils'
import { useAppStore } from '../store/appStore'
import type { Project, ProjectStatus } from '../types'

const statuses: ProjectStatus[] = ['Active', 'Review', 'Completed', 'On hold']

type ProjectForm = Pick<Project, 'name' | 'description' | 'targetLocales' | 'managerId' | 'translatorIds' | 'reviewerIds'>

const emptyForm: ProjectForm = {
  name: '',
  description: '',
  targetLocales: ['vi'],
  managerId: '',
  translatorIds: [],
  reviewerIds: [],
}

function projectStatusTone(status: ProjectStatus): 'green' | 'amber' | 'neutral' | 'rose' {
  if (status === 'Completed') return 'green'
  if (status === 'Review') return 'amber'
  if (status === 'On hold') return 'rose'
  return 'neutral'
}

export function ProjectsPage() {
  const projects = useAppStore((state) => state.projects)
  const language = useAppStore((state) => state.language)
  const users = useAppStore((state) => state.users)
  const locales = useAppStore((state) => state.locales)
  const isMutating = useAppStore((state) => state.isMutating)
  const currentUserId = useAppStore((state) => state.currentUserId)
  const addProject = useAppStore((state) => state.addProject)
  const updateProject = useAppStore((state) => state.updateProject)
  const deleteProject = useAppStore((state) => state.deleteProject)
  const addToast = useAppStore((state) => state.addToast)
  const [query, setQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('All statuses')
  const [localeFilter, setLocaleFilter] = useState('All languages')
  const [page, setPage] = useState(1)
  const [modalOpen, setModalOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<Project | null>(null)
  const [editingProject, setEditingProject] = useState<Project | null>(null)
  const [form, setForm] = useState<ProjectForm>(emptyForm)
  const [formError, setFormError] = useState('')
  const pageSize = 5

  const filteredProjects = useMemo(() => projects.filter((project) => {
    const matchesText = `${project.name} ${project.description} ${project.id}`.toLowerCase().includes(query.toLowerCase())
    const matchesStatus = statusFilter === 'All statuses' || project.status === statusFilter
    const matchesLocale = localeFilter === 'All languages' || project.targetLocales.includes(localeFilter)
    return matchesText && matchesStatus && matchesLocale
  }), [localeFilter, projects, query, statusFilter])

  const pagedProjects = filteredProjects.slice((page - 1) * pageSize, page * pageSize)
  const translators = users.filter((user) => user.active && user.accountRole !== 'admin')
  const reviewers = translators
  const managers = users.filter((user) => user.active && user.accountRole !== 'admin'
    && !form.translatorIds.includes(user.id)
    && !form.reviewerIds.includes(user.id))
  const userById = Object.fromEntries(users.map((user) => [user.id, user]))

  const openCreate = () => {
    setEditingProject(null)
    setForm({ ...emptyForm, managerId: currentUserId })
    setFormError('')
    setModalOpen(true)
  }
  const openEdit = (project: Project) => {
    setEditingProject(project)
    setForm({
      name: project.name,
      description: project.description,
      targetLocales: project.targetLocales,
      managerId: project.managerId,
      translatorIds: project.translatorIds,
      reviewerIds: project.reviewerIds,
    })
    setFormError('')
    setModalOpen(true)
  }
  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (form.name.trim().length < 3) {
      setFormError('Project name must have at least 3 characters.')
      return
    }
    if (form.targetLocales.length === 0) {
      setFormError('Select at least one target language.')
      return
    }
    if (!form.translatorIds.length || !form.reviewerIds.length) {
      setFormError('Assign at least one translator and one reviewer.')
      return
    }
    if (form.translatorIds.some((id) => form.reviewerIds.includes(id)) || form.managerId && (form.translatorIds.includes(form.managerId) || form.reviewerIds.includes(form.managerId))) {
      setFormError('Each person can hold only one project role in this project.')
      return
    }
    try {
      if (editingProject) {
        await updateProject(editingProject.id, { ...form, name: form.name.trim() })
        addToast({ title: 'Project updated', message: `${form.name} and its assignments are saved.`, tone: 'success' })
      } else {
        await addProject({
          ...form,
          name: form.name.trim(),
          sourceLocale: 'en',
          status: 'Active',
          progress: 0,
          words: 0,
          strings: 0,
          accent: 'violet',
        })
        addToast({ title: 'Project created', message: 'Your team assignments are ready to go.', tone: 'success' })
      }
      setModalOpen(false)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Could not save this project.')
    }
  }

  const toggleListItem = (field: 'translatorIds' | 'reviewerIds', userId: string) => {
    setForm((current) => ({
      ...current,
      [field]: current[field].includes(userId)
        ? current[field].filter((id) => id !== userId)
        : [...current[field], userId],
    }))
  }

  const columns: TableColumn<Project>[] = [
    {
      key: 'name',
      header: 'Project',
      sortValue: (project) => project.name,
      render: (project) => (
        <div className="flex min-w-48 items-center gap-3">
          <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${project.accent === 'blue' ? 'bg-blue-50 text-blue-600' : project.accent === 'emerald' ? 'bg-emerald-50 text-emerald-600' : project.accent === 'amber' ? 'bg-amber-50 text-amber-600' : project.accent === 'rose' ? 'bg-rose-50 text-rose-600' : project.accent === 'cyan' ? 'bg-cyan-50 text-cyan-600' : 'bg-violet-50 text-violet-600'}`}>
            <FolderPlus size={18} aria-hidden="true" />
          </span>
          <span className="min-w-0">
            <span className="block truncate text-xs font-bold text-[var(--ink)]">{project.name}</span>
            <span className="mt-1 block text-[10px] text-[var(--muted)]">{project.id} · {project.sourceLocale.toUpperCase()} → {project.targetLocales.length} locales</span>
          </span>
        </div>
      ),
    },
    {
      key: 'progress',
      header: 'Progress',
      sortValue: (project) => project.progress,
      render: (project) => (
        <div className="min-w-28">
          <div className="mb-1.5 flex items-center justify-between text-[10px]"><span className="font-bold text-[var(--ink)]">{project.progress}%</span><span className="text-[var(--muted)]">{formatNumber(project.words, language)} words</span></div>
          <div className="h-1.5 overflow-hidden rounded-full bg-[var(--surface-soft)]"><span className={`block h-full rounded-full ${project.status === 'Completed' ? 'bg-emerald-500' : 'bg-violet-500'}`} style={{ width: `${project.progress}%` }} /></div>
        </div>
      ),
    },
    {
      key: 'team',
      header: 'Team',
      sortable: false,
      render: (project) => <AvatarStack users={[project.managerId, ...project.translatorIds, ...project.reviewerIds].map((id) => userById[id]).filter(Boolean)} />,
    },
    { key: 'status', header: 'Status', sortValue: (project) => project.status, render: (project) => <Badge tone={projectStatusTone(project.status)} dot>{project.status}</Badge> },
    { key: 'updated', header: 'Updated', sortValue: (project) => project.updatedAt, render: (project) => <span className="whitespace-nowrap text-xs text-[var(--muted)]">{getFriendlyDate(project.updatedAt, language)}</span> },
    {
      key: 'actions',
      header: '',
      sortable: false,
      align: 'right',
      render: (project) => (
        <div className="flex justify-end gap-1">
          <button type="button" onClick={() => openEdit(project)} className="grid size-8 place-items-center rounded-lg text-[var(--muted)] hover:bg-violet-50 hover:text-violet-700" aria-label={`Edit ${project.name}`}><Edit3 size={15} aria-hidden="true" /></button>
          <button type="button" onClick={() => setDeleteTarget(project)} className="grid size-8 place-items-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-700" aria-label={`Delete ${project.name}`}><Trash2 size={15} aria-hidden="true" /></button>
        </div>
      ),
    },
  ]

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading
        eyebrow="Workspace management"
        title="Projects & assignments"
        description="Keep every language team aligned from kickoff to final review."
        action={<Button variant="primary" icon={<Plus size={16} />} onClick={openCreate} disabled={isMutating}>Create project</Button>}
      />

      <section className="grid gap-3 sm:grid-cols-3">
        {[
          { label: 'Total projects', value: projects.length, tone: 'text-violet-600 bg-violet-50' },
          { label: 'In progress', value: projects.filter((project) => ['Active', 'Review'].includes(project.status)).length, tone: 'text-blue-600 bg-blue-50' },
          { label: 'Team members', value: users.filter((user) => user.active).length, tone: 'text-emerald-600 bg-emerald-50' },
        ].map((item) => (
          <Panel className="flex items-center gap-3 p-4" key={item.label}>
            <span className={`grid size-10 place-items-center rounded-xl ${item.tone}`}><FolderPlus size={18} aria-hidden="true" /></span>
            <div><p className="text-xs text-[var(--muted)]">{item.label}</p><p className="mt-1 text-xl font-bold text-[var(--ink)]">{item.value}</p></div>
          </Panel>
        ))}
      </section>

      <Panel className="p-4 sm:p-5">
        <PanelHeading title="All projects" subtitle="Search, filter, and maintain your project roster." action={<Badge tone="violet">{filteredProjects.length} projects</Badge>} />
        <div className="my-4 grid gap-2 sm:grid-cols-[minmax(220px,1fr)_180px_180px]">
          <label className="relative">
            <span className="sr-only">Search projects</span>
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
            <input type="search" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} placeholder="Search projects or IDs..." className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-xs outline-none focus:border-violet-400 focus:ring-4 focus:ring-violet-500/10" />
          </label>
          <label>
            <span className="sr-only">Filter by status</span>
            <select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)] outline-none focus:border-violet-400">
              <option>All statuses</option>{statuses.map((status) => <option key={status}>{status}</option>)}
            </select>
          </label>
          <label>
            <span className="sr-only">Filter by target language</span>
            <select value={localeFilter} onChange={(event) => { setLocaleFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)] outline-none focus:border-violet-400">
              <option>All languages</option>{locales.map((locale) => <option key={locale.code} value={locale.code}>{locale.name}</option>)}
            </select>
          </label>
        </div>

        <DataTable rows={pagedProjects} columns={columns} caption="Project list" empty="No projects match these filters." />
        <div className="mt-4"><Pagination page={page} pageSize={pageSize} total={filteredProjects.length} onChange={setPage} /></div>
      </Panel>

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editingProject ? 'Edit project' : 'Create a project'} description="Set project details and make sure translation and review owners are assigned." size="lg">
        <form className="space-y-4" onSubmit={handleSubmit}>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="field-label sm:col-span-2">Project name
              <input autoFocus required minLength={3} maxLength={100} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="e.g. Mobile app launch" className="field-control" />
            </label>
            <label className="field-label sm:col-span-2">Description
              <textarea rows={2} maxLength={300} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="What are we localizing?" className="field-control resize-y" />
            </label>
            <p className="text-xs leading-5 text-[var(--muted)] sm:col-span-2">Source language is English. Project status is calculated from translation and review progress.</p>
            <fieldset className="field-label sm:col-span-2">
              <legend>Target languages <span className="text-rose-500">*</span></legend>
              <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                {locales.map((locale) => (
                  <label key={locale.code} className="flex cursor-pointer items-center gap-2 rounded-xl border border-[var(--line)] px-3 py-2 text-xs text-[var(--ink)] hover:border-violet-300">
                    <input type="checkbox" checked={form.targetLocales.includes(locale.code)} onChange={(event) => setForm({ ...form, targetLocales: event.target.checked ? [...form.targetLocales, locale.code] : form.targetLocales.filter((code) => code !== locale.code) })} className="accent-violet-600" />
                    {locale.name}
                  </label>
                ))}
              </div>
            </fieldset>
            <fieldset className="field-label">
              <legend>Translators <span className="text-rose-500">*</span></legend>
              <div className="mt-2 max-h-32 space-y-1 overflow-y-auto rounded-xl border border-[var(--line)] p-2">
                {translators.map((user) => (
                  <label key={user.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-[var(--surface-soft)]">
                    <input type="checkbox" checked={form.translatorIds.includes(user.id)} disabled={form.managerId === user.id || form.reviewerIds.includes(user.id)} onChange={() => toggleListItem('translatorIds', user.id)} className="accent-violet-600" />{user.name}
                  </label>
                ))}
              </div>
            </fieldset>
            <fieldset className="field-label">
              <legend>Reviewers <span className="text-rose-500">*</span></legend>
              <div className="mt-2 max-h-32 space-y-1 overflow-y-auto rounded-xl border border-[var(--line)] p-2">
                {reviewers.map((user) => (
                  <label key={user.id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-[var(--surface-soft)]">
                    <input type="checkbox" checked={form.reviewerIds.includes(user.id)} disabled={form.managerId === user.id || form.translatorIds.includes(user.id)} onChange={() => toggleListItem('reviewerIds', user.id)} className="accent-violet-600" />{user.name}
                  </label>
                ))}
              </div>
            </fieldset>
            <label className="field-label sm:col-span-2">Project manager
              <select value={form.managerId} onChange={(event) => setForm({ ...form, managerId: event.target.value })} className="field-control">
                {managers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
              </select>
            </label>
          </div>
          {formError && <p className="rounded-xl bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700" role="alert">{formError}</p>}
          <div className="flex flex-col-reverse gap-2 border-t border-[var(--line)] pt-4 sm:flex-row sm:justify-end">
            <Button onClick={() => setModalOpen(false)}>Cancel</Button>
            <Button type="submit" variant="primary" disabled={isMutating} icon={editingProject ? <Edit3 size={15} /> : <Plus size={15} />}>{isMutating ? 'Saving…' : editingProject ? 'Save changes' : 'Create project'}</Button>
          </div>
        </form>
      </Modal>

      <Modal open={deleteTarget !== null} onClose={() => setDeleteTarget(null)} title="Delete project?" description="This removes the project and its translations. Existing audit entries remain in the activity log.">
        {deleteTarget && (
          <div>
            <p className="text-sm text-[var(--ink)]">You’re about to delete <strong>{deleteTarget.name}</strong> ({deleteTarget.id}). This cannot be undone.</p>
            <div className="mt-5 flex justify-end gap-2">
              <Button onClick={() => setDeleteTarget(null)}>Keep project</Button>
              <Button variant="danger" disabled={isMutating} icon={<Trash2 size={15} />} onClick={async () => {
                try {
                  await deleteProject(deleteTarget.id)
                  addToast({ title: 'Project deleted', message: `${deleteTarget.name} was removed. Its audit history is retained.`, tone: 'success' })
                  setDeleteTarget(null)
                } catch (error) {
                  addToast({ title: 'Could not delete project', message: error instanceof Error ? error.message : undefined, tone: 'error' })
                }
              }}>{isMutating ? 'Deleting…' : 'Delete project'}</Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
    </LocalizedContent>
  )
}
