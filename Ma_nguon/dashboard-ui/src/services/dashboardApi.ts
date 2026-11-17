import { api } from '../lib/api'
import type {
  AuditEntry,
  Project,
  SupportRequest,
  SupportRequestStatus,
  TeamUser,
  TranslationRow,
  WorkspaceLocale,
} from '../types'

interface ApiEnvelope<T> {
  success: boolean
  data?: T
  message?: string
}

export interface WorkspaceData {
  csrfToken: string
  currentUserId: string
  canManageUsers: boolean
  users: TeamUser[]
  projects: Project[]
  translations: TranslationRow[]
  logs: AuditEntry[]
  locales: WorkspaceLocale[]
}

export interface TranslationSuggestion {
  translated: string
  fallback: boolean
  targetLocale: string
  requestedTargetLocale: string
}

interface ApiTeamUser extends Omit<TeamUser, 'projectRoles'> {
  role?: string
}

interface ApiProject extends Omit<Project, 'managerId'> {
  managerId?: string
  teamLeadId?: string
}

interface ApiWorkspaceData extends Omit<WorkspaceData, 'users' | 'projects'> {
  users: ApiTeamUser[]
  projects: ApiProject[]
}

async function request<T>(url: string, method: 'GET' | 'POST', payload?: Record<string, unknown>): Promise<T> {
  const response = method === 'GET'
    ? await api.get<ApiEnvelope<T>>(url)
    : await api.post<ApiEnvelope<T>>(url, payload)
  if (!response.data.success || response.data.data === undefined) {
    throw new Error(response.data.message || 'The PHP API returned an invalid response.')
  }
  return response.data.data
}

function normalizeWorkspace(data: ApiWorkspaceData): WorkspaceData {
  const projects = data.projects.map((project) => {
    const managerId = project.managerId ?? project.teamLeadId
    if (!managerId) throw new Error(`Project ${project.id} has no manager assignment.`)
    const { teamLeadId: _legacyTeamLeadId, ...projectData } = project
    return { ...projectData, managerId }
  })

  const rolesByUser = new Map<string, TeamUser['projectRoles']>()
  const accountRolesByUser = new Map(data.users.map((user) => [user.id, user.accountRole]))
  const assignRole = (userId: string, projectId: string, role: TeamUser['projectRoles'][number]['role']) => {
    const assignments = rolesByUser.get(userId) ?? []
    if (!assignments.some((assignment) => assignment.projectId === projectId)) {
      assignments.push({ projectId, role })
    }
    rolesByUser.set(userId, assignments)
  }
  for (const project of projects) {
    if (accountRolesByUser.get(project.managerId) !== 'admin') {
      assignRole(project.managerId, project.id, 'Manager')
    }
    for (const userId of project.translatorIds) assignRole(userId, project.id, 'Translator')
    for (const userId of project.reviewerIds) assignRole(userId, project.id, 'Reviewer')
  }

  const users = data.users.map((user): TeamUser => {
    const { role: _legacyWorkspaceRole, ...userData } = user
    return { ...userData, projectRoles: rolesByUser.get(user.id) ?? [] }
  })

  return { ...data, projects, users }
}

function projectPayload(project: Omit<Project, 'id' | 'updatedAt'> | Project) {
  const { managerId, ...data } = project
  return { ...data, teamLeadId: managerId }
}

export const dashboardApi = {
  loadWorkspace: async () => normalizeWorkspace(await request<ApiWorkspaceData>('/api_dashboard.php', 'GET')),

  createProject: (csrfToken: string, project: Omit<Project, 'id' | 'updatedAt'>) =>
    request('/api_dashboard.php', 'POST', { csrf_token: csrfToken, action: 'create_project', ...projectPayload(project) }),

  updateProject: (csrfToken: string, project: Project) =>
    request('/api_dashboard.php', 'POST', { csrf_token: csrfToken, action: 'update_project', ...projectPayload(project) }),

  deleteProject: (csrfToken: string, id: string) =>
    request('/api_dashboard.php', 'POST', { csrf_token: csrfToken, action: 'delete_project', id }),

  saveUser: (csrfToken: string, user: TeamUser, password?: string) =>
    request('/api_dashboard.php', 'POST', {
      csrf_token: csrfToken,
      action: user.id ? 'update_user' : 'create_user',
      ...(user.id ? { id: user.id } : {}),
      name: user.name,
      email: user.email,
      accountRole: user.accountRole ?? 'user',
      active: user.active,
      ...(password ? { password } : {}),
    }),

  deleteUser: (csrfToken: string, id: string) =>
    request('/api_dashboard.php', 'POST', { csrf_token: csrfToken, action: 'delete_user', id }),

  saveTranslation: (csrfToken: string, row: TranslationRow, translated: string) =>
    request('/api_dashboard.php', 'POST', {
      csrf_token: csrfToken,
      action: 'save_translation',
      stringId: row.stringId,
      targetLocale: row.targetLocale,
      translationUpdatedAt: row.translationUpdatedAt ?? null,
      translated,
    }),

  reviewTranslation: (csrfToken: string, row: TranslationRow, decision: 'approve' | 'request_revision', comment?: string) =>
    request('/api_dashboard.php', 'POST', {
      csrf_token: csrfToken,
      action: 'review_translation',
      stringId: row.stringId,
      targetLocale: row.targetLocale,
      decision,
      comment: comment ?? '',
    }),

  suggestTranslation: (csrfToken: string, row: TranslationRow) =>
    request<TranslationSuggestion>(
      '/api_dashboard.php',
      'POST',
      {
        csrf_token: csrfToken,
        action: 'suggest_translation',
        stringId: row.stringId,
        targetLocale: row.targetLocale,
      },
    ),

  loadSupportRequests: () =>
    request<SupportRequest[]>('/api_dashboard.php?resource=support_requests', 'GET'),

  updateSupportRequestStatus: (csrfToken: string, id: string, status: SupportRequestStatus) =>
    request('/api_dashboard.php', 'POST', {
      csrf_token: csrfToken,
      action: 'update_support_request_status',
      id,
      status,
    }),
}
