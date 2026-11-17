export type ProjectRole = 'Manager' | 'Reviewer' | 'Translator'
export type AccountRole = 'admin' | 'user'
export type ThemePreference = 'system' | 'light' | 'dark'
export type UiLanguage = 'en' | 'vi'
export type ProjectStatus = 'Active' | 'Review' | 'Completed' | 'On hold'
export type TranslationStatus = 'Draft' | 'In review' | 'Approved' | 'Needs revision'
export type SupportRequestStatus = 'pending' | 'in_progress' | 'resolved'
export type LogAction =
  | 'Project created'
  | 'Project updated'
  | 'Project deleted'
  | 'Assignment changed'
  | 'Translation saved'
  | 'Translation approved'
  | 'Revision requested'
  | 'Settings updated'
  | 'User created'
  | 'User updated'
  | 'User deleted'
  | 'Support request status updated'

export interface TeamUser {
  id: string
  name: string
  email: string
  accountRole: AccountRole
  projectRoles: ProjectRoleAssignment[]
  active: boolean
  avatar: string
  color: string
  projects: number
  joinedAt: string
}

export interface ProjectRoleAssignment {
  projectId: string
  role: ProjectRole
}

export interface WorkspaceLocale {
  code: string
  name: string
}

export interface Project {
  id: string
  name: string
  description: string
  sourceLocale: string
  targetLocales: string[]
  status: ProjectStatus
  progress: number
  words: number
  strings: number
  updatedAt: string
  managerId: string
  translatorIds: string[]
  reviewerIds: string[]
  accent: string
}

export interface TranslationRow {
  id: string
  stringId?: string
  translationUpdatedAt?: string | null
  projectId: string
  key: string
  source: string
  translated: string
  sourceLocale: string
  targetLocale: string
  status: TranslationStatus
  assigneeId: string
  updatedAt: string
  comment?: string
  fallback?: boolean
}

export interface SupportRequest {
  id: string
  title: string
  message: string
  senderName: string
  senderEmail: string
  createdAt: string
  imagePath: string | null
  status: SupportRequestStatus
}

export interface AuditEntry {
  id: string
  actorId: string
  action: LogAction
  target: string
  projectId?: string
  details: string
  createdAt: string
}

export interface ToastMessage {
  id: string
  title: string
  message?: string
  tone: 'success' | 'warning' | 'error' | 'info'
}

export interface TranslationSettings {
  provider: 'MyMemory'
  apiBaseUrl: string
  contactEmail: string
  requestDelay: number
}
