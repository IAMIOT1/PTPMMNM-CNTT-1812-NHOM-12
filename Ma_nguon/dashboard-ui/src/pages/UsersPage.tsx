import { Edit3, Plus, Search, Trash2, Users } from 'lucide-react'
import { useMemo, useState, type FormEvent } from 'react'
import { Avatar } from '../components/common/Avatar'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { DataTable, type TableColumn } from '../components/common/DataTable'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { Modal } from '../components/common/Modal'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { Pagination } from '../components/common/Pagination'
import { accountRoleLabels, formatNumber, projectRoleLabels } from '../lib/utils'
import { useAppStore } from '../store/appStore'
import type { AccountRole, ProjectRole, TeamUser } from '../types'

type UserForm = Pick<TeamUser, 'name' | 'email' | 'active'> & { accountRole: AccountRole; password: string }
const blankUser: UserForm = { name: '', email: '', accountRole: 'user', active: true, password: '' }
const roles: ProjectRole[] = ['Manager', 'Reviewer', 'Translator']
const roleStats: { role: ProjectRole; label: string }[] = [
  { role: 'Manager', label: 'Project managers' },
  { role: 'Reviewer', label: 'Reviewers' },
  { role: 'Translator', label: 'Translators' },
]
const colors = ['violet', 'blue', 'amber', 'emerald', 'rose', 'cyan']

export function UsersPage() {
  const users = useAppStore((state) => state.users)
  const language = useAppStore((state) => state.language)
  const projects = useAppStore((state) => state.projects)
  const currentUserId = useAppStore((state) => state.currentUserId)
  const canManageUsers = useAppStore((state) => state.canManageUsers)
  const isMutating = useAppStore((state) => state.isMutating)
  const saveUser = useAppStore((state) => state.saveUser)
  const deleteUser = useAppStore((state) => state.deleteUser)
  const addToast = useAppStore((state) => state.addToast)
  const [query, setQuery] = useState('')
  const [roleFilter, setRoleFilter] = useState('All project roles')
  const [statusFilter, setStatusFilter] = useState('All members')
  const [page, setPage] = useState(1)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingUser, setEditingUser] = useState<TeamUser | null>(null)
  const [deletingUser, setDeletingUser] = useState<TeamUser | null>(null)
  const [form, setForm] = useState<UserForm>(blankUser)
  const [error, setError] = useState('')
  const pageSize = 6

  const filteredUsers = useMemo(() => users.filter((user) => (
    `${user.name} ${user.email}`.toLowerCase().includes(query.toLowerCase())
    && (roleFilter === 'All project roles' || user.projectRoles.some((assignment) => assignment.role === roleFilter))
    && (statusFilter === 'All members' || (statusFilter === 'Active' ? user.active : !user.active))
  )), [query, roleFilter, statusFilter, users])
  const projectCounts = new Map(users.map((user) => [user.id, user.projectRoles.length]))

  const openCreate = () => {
    setEditingUser(null)
    setForm(blankUser)
    setError('')
    setModalOpen(true)
  }
  const openEdit = (user: TeamUser) => {
    setEditingUser(user)
    setForm({ name: user.name, email: user.email, accountRole: user.accountRole ?? 'user', active: user.active, password: '' })
    setError('')
    setModalOpen(true)
  }
  const handleSave = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const name = form.name.trim()
    const email = form.email.trim().toLowerCase()
    if (!/^[A-Za-z0-9_.-]{3,50}$/.test(name)) { setError('Username must be 3–50 characters using letters, numbers, dots, underscores or hyphens.'); return }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setError('Enter a valid email address.'); return }
    if (users.some((user) => user.email.toLowerCase() === email && user.id !== editingUser?.id)) { setError('That email address is already in use.'); return }
    if (!editingUser && form.password.length < 8) { setError('Password must contain at least 8 characters.'); return }
    const initials = name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
    const saved: TeamUser = {
      id: editingUser?.id ?? '',
      name, email, accountRole: form.accountRole, projectRoles: editingUser?.projectRoles ?? [], active: form.active,
      avatar: initials,
      color: editingUser?.color ?? colors[users.length % colors.length],
      projects: editingUser?.projects ?? 0,
      joinedAt: editingUser?.joinedAt ?? new Date().toISOString().slice(0, 10),
    }
    void saveUser(saved, editingUser ? undefined : form.password).then(() => {
      addToast({ title: editingUser ? 'Team member updated' : 'Team member added', message: `${name} · ${form.accountRole === 'admin' ? 'Administrator' : 'Standard account'}`, tone: 'success' })
      setModalOpen(false)
    }).catch((saveError: unknown) => {
      setError(saveError instanceof Error ? saveError.message : 'Could not save this account.')
    })
  }

  if (!canManageUsers) {
    return <LocalizedContent><Panel className="p-6"><h1 className="text-lg font-bold text-[var(--ink)]">Administrator access required</h1><p className="mt-2 text-sm text-[var(--muted)]">Only system administrators can manage user accounts.</p></Panel></LocalizedContent>
  }

  const columns: TableColumn<TeamUser>[] = [
    { key: 'name', header: 'Team member', sortValue: (user) => user.name, render: (user) => <div className="flex min-w-48 items-center gap-3"><Avatar user={user} /><span className="min-w-0"><span className="block truncate text-xs font-bold text-[var(--ink)]">{user.name}</span><span className="block truncate text-[10px] text-[var(--muted)]">{user.email}</span></span></div> },
    { key: 'projectRoles', header: 'Project roles', sortValue: (user) => user.projectRoles.map((assignment) => assignment.role).join(', '), render: (user) => <div className="flex min-w-48 flex-wrap gap-1">{user.projectRoles.slice(0, 2).map((assignment) => <Badge key={`${assignment.projectId}:${assignment.role}`} tone={assignment.role === 'Manager' ? 'violet' : assignment.role === 'Reviewer' ? 'amber' : 'blue'}>{projects.find((project) => project.id === assignment.projectId)?.name ?? assignment.projectId} · {projectRoleLabels[assignment.role]}</Badge>)}{user.projectRoles.length > 2 && <Badge tone="neutral">+{user.projectRoles.length - 2} projects</Badge>}{user.projectRoles.length === 0 && <span className="text-[10px] text-[var(--muted)]">No project role</span>}</div> },
    { key: 'accountRole', header: 'System account', sortValue: (user) => user.accountRole, render: (user) => <Badge tone={user.accountRole === 'admin' ? 'violet' : 'neutral'}>{accountRoleLabels[user.accountRole]}</Badge> },
    { key: 'projects', header: 'Projects', sortValue: (user) => user.projectRoles.length, render: (user) => <span className="text-xs font-semibold text-[var(--ink)]">{projectCounts.get(user.id) ?? 0} assigned</span> },
    { key: 'joined', header: 'Joined', sortValue: (user) => user.joinedAt, render: (user) => <span className="whitespace-nowrap text-xs text-[var(--muted)]">{new Intl.DateTimeFormat(language === 'vi' ? 'vi-VN' : 'en-US', { dateStyle: 'medium' }).format(new Date(user.joinedAt))}</span> },
    { key: 'status', header: 'Status', sortValue: (user) => user.active ? 'Active' : 'Inactive', render: (user) => <Badge tone={user.active ? 'green' : 'neutral'} dot>{user.active ? 'Active' : 'Inactive'}</Badge> },
    { key: 'actions', header: '', sortable: false, align: 'right', render: (user) => <div className="flex justify-end gap-1"><button type="button" onClick={() => openEdit(user)} className="grid size-8 place-items-center rounded-lg text-[var(--muted)] hover:bg-violet-50 hover:text-violet-700" aria-label={`Edit ${user.name}`}><Edit3 size={15} aria-hidden="true" /></button><button type="button" onClick={() => setDeletingUser(user)} disabled={user.id === currentUserId} className="grid size-8 place-items-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-700 disabled:opacity-30" aria-label={`Delete ${user.name}`}><Trash2 size={15} aria-hidden="true" /></button></div> },
  ]

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading eyebrow="Workspace administration" title="Team & access" description="Manage workspace membership, role-based access, and project participation." action={<Button variant="primary" disabled={isMutating} icon={<Plus size={15} />} onClick={openCreate}>Add member</Button>} />
      <div className="grid gap-3 sm:grid-cols-3">
        {roleStats.map(({ role, label }) => <Panel key={role} className="flex items-center gap-3 p-4"><span className="grid size-10 place-items-center rounded-xl bg-violet-50 text-violet-600"><Users size={17} aria-hidden="true" /></span><div><p className="text-[11px] text-[var(--muted)]">{label}</p><p className="text-lg font-bold text-[var(--ink)]">{formatNumber(users.filter((user) => user.projectRoles.some((assignment) => assignment.role === role)).length, language)}</p></div></Panel>)}
      </div>
      <Panel className="p-4 sm:p-5">
        <PanelHeading title="People & system accounts" subtitle="System Admin controls users and settings. Manager, Reviewer, and Translator roles are assigned separately on each project." action={<Badge tone="violet">{filteredUsers.length} members</Badge>} />
        <div className="my-4 grid gap-2 sm:grid-cols-[minmax(220px,1fr)_180px_180px]">
          <label className="relative"><span className="sr-only">Search team members</span><Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" /><input value={query} onChange={(event) => { setQuery(event.target.value); setPage(1) }} placeholder="Search name or email..." className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-xs outline-none focus:border-violet-400" /></label>
          <label><span className="sr-only">Filter project role</span><select value={roleFilter} onChange={(event) => { setRoleFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"><option>All project roles</option>{roles.map((role) => <option key={role} value={role}>{projectRoleLabels[role]}</option>)}</select></label>
          <label><span className="sr-only">Filter status</span><select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1) }} className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"><option>All members</option><option>Active</option><option>Inactive</option></select></label>
        </div>
        <DataTable rows={filteredUsers.slice((page - 1) * pageSize, page * pageSize)} columns={columns} caption="Team member list" empty="No members match these filters." />
        <div className="mt-4"><Pagination page={page} pageSize={pageSize} total={filteredUsers.length} onChange={setPage} /></div>
      </Panel>
      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editingUser ? 'Edit system account' : 'Add system account'} description="Set the system account role here. Project roles are assigned separately in each project.">
        <form onSubmit={handleSave} className="space-y-4">
          <label className="field-label">Username<input autoFocus required minLength={3} maxLength={50} pattern="[A-Za-z0-9_.-]+" autoComplete="username" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className="field-control" placeholder="e.g. sam.taylor" /></label>
          <label className="field-label">Email address<input required type="email" maxLength={254} autoComplete="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} className="field-control" placeholder="sam@example.com" /></label>
          {!editingUser && <label className="field-label">Initial password<input required type="password" minLength={8} maxLength={200} autoComplete="new-password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} className="field-control" /></label>}
          <label className="field-label">System account role<select value={form.accountRole} onChange={(event) => setForm({ ...form, accountRole: event.target.value as AccountRole })} className="field-control"><option value="user">{accountRoleLabels.user}</option><option value="admin">{accountRoleLabels.admin}</option></select></label>
          <p className="text-[10px] leading-5 text-[var(--muted)]">Project Manager, Reviewer, and Translator roles are assigned for each project in the Projects page.</p>
          <label className="flex items-center gap-2 text-xs font-medium text-[var(--ink)]"><input type="checkbox" checked={form.active} onChange={(event) => setForm({ ...form, active: event.target.checked })} className="accent-violet-600" /> Active member</label>
          {error && <p role="alert" className="text-xs font-medium text-rose-700">{error}</p>}
          <div className="flex justify-end gap-2 border-t border-[var(--line)] pt-4"><Button onClick={() => setModalOpen(false)}>Cancel</Button><Button variant="primary" type="submit" disabled={isMutating}>{isMutating ? 'Saving…' : editingUser ? 'Save changes' : 'Add member'}</Button></div>
        </form>
      </Modal>
      <Modal open={deletingUser !== null} onClose={() => setDeletingUser(null)} title="Remove team member?" description="Their project assignments will be cleared. Existing audit events are retained.">
        {deletingUser && <div><p className="text-sm text-[var(--ink)]">Remove <strong>{deletingUser.name}</strong> from this workspace?</p><div className="mt-5 flex justify-end gap-2"><Button onClick={() => setDeletingUser(null)}>Cancel</Button><Button variant="danger" disabled={isMutating} icon={<Trash2 size={14} />} onClick={async () => {
          try {
            await deleteUser(deletingUser.id)
            addToast({ title: 'Member removed', message: `${deletingUser.name} no longer has workspace access.`, tone: 'success' })
            setDeletingUser(null)
          } catch (deleteError) {
            addToast({ title: 'Could not remove member', message: deleteError instanceof Error ? deleteError.message : undefined, tone: 'error' })
          }
        }}>{isMutating ? 'Removing…' : 'Remove member'}</Button></div></div>}
      </Modal>
    </div>
    </LocalizedContent>
  )
}
