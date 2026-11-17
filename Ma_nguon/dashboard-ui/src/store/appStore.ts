import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { getApiErrorMessage } from '../lib/api'
import { dashboardApi } from '../services/dashboardApi'
import type {
  AuditEntry,
  Project,
  SupportRequest,
  SupportRequestStatus,
  TeamUser,
  ThemePreference,
  ToastMessage,
  TranslationRow,
  TranslationSettings,
  UiLanguage,
  WorkspaceLocale,
} from '../types'
import type { TranslationSuggestion } from '../services/dashboardApi'
import { readUiLanguage, UI_LANGUAGE_STORAGE_KEY } from '../lib/i18n'

interface AppState {
  currentUserId: string
  csrfToken: string
  canManageUsers: boolean
  theme: ThemePreference
  language: UiLanguage
  users: TeamUser[]
  projects: Project[]
  translations: TranslationRow[]
  supportRequests: SupportRequest[]
  logs: AuditEntry[]
  locales: WorkspaceLocale[]
  settings: TranslationSettings
  toasts: ToastMessage[]
  hasLoaded: boolean
  isLoading: boolean
  isRefreshing: boolean
  isMutating: boolean
  isSupportRequestsLoading: boolean
  supportRequestsError: string | null
  error: string | null
  setTheme: (theme: ThemePreference) => void
  setLanguage: (language: UiLanguage) => void
  syncLanguage: (language: UiLanguage) => void
  addToast: (toast: Omit<ToastMessage, 'id'>) => void
  dismissToast: (id: string) => void
  loadWorkspace: (background?: boolean) => Promise<void>
  loadSupportRequests: () => Promise<void>
  updateSupportRequestStatus: (id: string, status: SupportRequestStatus) => Promise<void>
  addProject: (project: Omit<Project, 'id' | 'updatedAt'>) => Promise<void>
  updateProject: (projectId: string, patch: Partial<Project>) => Promise<void>
  deleteProject: (projectId: string) => Promise<void>
  saveTranslation: (translationId: string, translated: string) => Promise<void>
  suggestTranslation: (translationId: string) => Promise<TranslationSuggestion>
  reviewTranslation: (translationId: string, status: 'Approved' | 'Needs revision', comment?: string) => Promise<void>
  saveUser: (user: TeamUser, password?: string) => Promise<void>
  deleteUser: (userId: string) => Promise<void>
  updateSettings: (settings: TranslationSettings) => void
}

const initialSettings: TranslationSettings = {
  provider: 'MyMemory',
  apiBaseUrl: 'https://api.mymemory.translated.net/get',
  contactEmail: '',
  requestDelay: 1000,
}

export const useAppStore = create<AppState>()(
  persist(
    (set, get) => {
      const mutate = async (operation: () => Promise<unknown>) => {
        if (get().isMutating) throw new Error('Another save is already in progress.')
        set({ isMutating: true, error: null })
        try {
          await operation()
          await get().loadWorkspace(true)
        } catch (error) {
          set({ error: getApiErrorMessage(error) })
          throw error
        } finally {
          set({ isMutating: false })
        }
      }

      return {
        currentUserId: '',
        csrfToken: '',
        canManageUsers: false,
        theme: 'system',
        language: readUiLanguage(),
        users: [],
        projects: [],
        translations: [],
        supportRequests: [],
        logs: [],
        locales: [],
        settings: initialSettings,
        toasts: [],
        hasLoaded: false,
        isLoading: true,
        isRefreshing: false,
        isMutating: false,
        isSupportRequestsLoading: false,
        supportRequestsError: null,
        error: null,

        setTheme: (theme) => set({ theme }),
        setLanguage: (language) => {
          window.localStorage.setItem(UI_LANGUAGE_STORAGE_KEY, language)
          set({ language })
          document.documentElement.lang = language
          document.dispatchEvent(new CustomEvent('tms:language-changed', { detail: { language } }))
        },
        syncLanguage: (language) => set({ language }),
        addToast: (toast) => {
          const id = crypto.randomUUID()
          set((state) => ({ toasts: [...state.toasts, { ...toast, id }] }))
          window.setTimeout(() => get().dismissToast(id), 4_500)
        },
        dismissToast: (id) => set((state) => ({ toasts: state.toasts.filter((toast) => toast.id !== id) })),

        loadWorkspace: async (background = false) => {
          const alreadyLoaded = get().hasLoaded
          set({
            isLoading: !background && !alreadyLoaded,
            isRefreshing: background || alreadyLoaded,
            error: null,
          })
          try {
            const workspace = await dashboardApi.loadWorkspace()
            set({ ...workspace, hasLoaded: true, error: null })
          } catch (error) {
            set({ error: getApiErrorMessage(error) })
            throw error
          } finally {
            set({ isLoading: false, isRefreshing: false })
          }
        },

        loadSupportRequests: async () => {
          set({ isSupportRequestsLoading: true, supportRequestsError: null })
          try {
            set({ supportRequests: await dashboardApi.loadSupportRequests() })
          } catch (error) {
            set({ supportRequestsError: getApiErrorMessage(error) })
            throw error
          } finally {
            set({ isSupportRequestsLoading: false })
          }
        },
        updateSupportRequestStatus: async (id, status) => {
          if (get().isMutating) throw new Error('Another save is already in progress.')
          set({ isMutating: true, error: null })
          try {
            await dashboardApi.updateSupportRequestStatus(get().csrfToken, id, status)
            set((state) => ({
              supportRequests: state.supportRequests.map((request) =>
                request.id === id ? { ...request, status } : request
              ),
            }))
            try {
              await get().loadWorkspace(true)
            } catch (refreshError) {
              get().addToast({
                title: 'Support status saved, but workspace refresh failed',
                message: getApiErrorMessage(refreshError),
                tone: 'warning',
              })
            }
          } catch (error) {
            set({ error: getApiErrorMessage(error) })
            throw error
          } finally {
            set({ isMutating: false })
          }
        },

        addProject: (project) => mutate(() => dashboardApi.createProject(get().csrfToken, project)),
        updateProject: (projectId, patch) => {
          const current = get().projects.find((project) => project.id === projectId)
          if (!current) return Promise.reject(new Error('Project not found.'))
          return mutate(() => dashboardApi.updateProject(get().csrfToken, { ...current, ...patch }))
        },
        deleteProject: (projectId) => mutate(() => dashboardApi.deleteProject(get().csrfToken, projectId)),
        saveTranslation: (translationId, translated) => {
          const row = get().translations.find((translation) => translation.id === translationId)
          if (!row) return Promise.reject(new Error('Translation row not found.'))
          return mutate(() => dashboardApi.saveTranslation(get().csrfToken, row, translated))
        },
        suggestTranslation: async (translationId) => {
          const row = get().translations.find((translation) => translation.id === translationId)
          if (!row) throw new Error('Translation row not found.')
          try {
            return await dashboardApi.suggestTranslation(get().csrfToken, row)
          } catch (error) {
            set({ error: getApiErrorMessage(error) })
            throw error
          }
        },
        reviewTranslation: (translationId, status, comment) => {
          const row = get().translations.find((translation) => translation.id === translationId)
          if (!row) return Promise.reject(new Error('Translation row not found.'))
          const decision = status === 'Approved' ? 'approve' : 'request_revision'
          return mutate(() => dashboardApi.reviewTranslation(get().csrfToken, row, decision, comment))
        },
        saveUser: (user, password) => mutate(() => dashboardApi.saveUser(get().csrfToken, user, password)),
        deleteUser: (userId) => mutate(() => dashboardApi.deleteUser(get().csrfToken, userId)),
        updateSettings: (settings) => set({ settings }),
      }
    },
    {
      name: 'lingua-desk-preferences',
      version: 3,
      partialize: (state) => ({
        theme: state.theme,
        settings: state.settings,
      }),
      migrate: (persistedState) => {
        const persisted = persistedState as Partial<AppState>
        const savedSettings = persisted.settings
        return {
          ...persisted,
          settings: {
            ...initialSettings,
            provider: savedSettings?.provider ?? initialSettings.provider,
            apiBaseUrl: savedSettings?.apiBaseUrl ?? initialSettings.apiBaseUrl,
            contactEmail: savedSettings?.contactEmail ?? initialSettings.contactEmail,
            requestDelay: savedSettings?.requestDelay ?? initialSettings.requestDelay,
          },
          language: readUiLanguage(),
        } as AppState
      },
    },
  ),
)
