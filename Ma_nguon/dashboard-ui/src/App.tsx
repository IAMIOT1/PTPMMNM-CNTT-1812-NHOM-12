import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from './components/layout/AppLayout'
import { LocalizedContent } from './components/common/LocalizedContent'

const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage').then((module) => ({ default: module.AnalyticsPage })))
const DashboardPage = lazy(() => import('./pages/DashboardPage').then((module) => ({ default: module.DashboardPage })))
const LogsPage = lazy(() => import('./pages/LogsPage').then((module) => ({ default: module.LogsPage })))
const ProjectsPage = lazy(() => import('./pages/ProjectsPage').then((module) => ({ default: module.ProjectsPage })))
const ReviewPage = lazy(() => import('./pages/ReviewPage').then((module) => ({ default: module.ReviewPage })))
const SettingsPage = lazy(() => import('./pages/SettingsPage').then((module) => ({ default: module.SettingsPage })))
const SupportRequestsPage = lazy(() => import('./pages/SupportRequestsPage').then((module) => ({ default: module.SupportRequestsPage })))
const TranslationPage = lazy(() => import('./pages/TranslationPage').then((module) => ({ default: module.TranslationPage })))
const UsersPage = lazy(() => import('./pages/UsersPage').then((module) => ({ default: module.UsersPage })))

function PageLoading() {
  return <LocalizedContent><div className="grid min-h-64 place-items-center" role="status"><span className="size-8 animate-spin rounded-full border-2 border-violet-200 border-t-violet-600" /><span className="sr-only">Loading page</span></div></LocalizedContent>
}

function NotFound() {
  return (
    <LocalizedContent>
    <div className="mx-auto max-w-lg py-20 text-center">
      <p className="text-sm font-bold text-violet-600">404 · Page not found</p>
      <h1 className="mt-2 text-2xl font-bold text-[var(--ink)]">This workspace page does not exist.</h1>
      <p className="mt-2 text-sm text-[var(--muted)]">Use the sidebar to return to a dashboard page.</p>
    </div>
    </LocalizedContent>
  )
}

function App() {
  return (
    <BrowserRouter basename={import.meta.env.PROD ? '/dashboard-ui' : undefined}>
      <Suspense fallback={<PageLoading />}>
        <Routes>
          <Route element={<AppLayout />}>
            <Route index element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/projects" element={<ProjectsPage />} />
            <Route path="/translation" element={<TranslationPage />} />
            <Route path="/review" element={<ReviewPage />} />
            <Route path="/users" element={<UsersPage />} />
            <Route path="/logs" element={<LogsPage />} />
            <Route path="/support-requests" element={<SupportRequestsPage />} />
            <Route path="/analytics" element={<AnalyticsPage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}

export default App
