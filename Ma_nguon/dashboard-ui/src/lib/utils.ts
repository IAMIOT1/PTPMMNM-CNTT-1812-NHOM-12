import type { AccountRole, Project, ProjectRole, TranslationStatus, UiLanguage } from '../types'

export const projectRoleLabels: Record<ProjectRole, string> = {
  Manager: 'Project manager',
  Reviewer: 'Reviewer',
  Translator: 'Translator',
}

export const projectRoleColors: Record<ProjectRole, string> = {
  Manager: 'bg-violet-50 text-violet-700 ring-violet-200',
  Reviewer: 'bg-amber-50 text-amber-700 ring-amber-200',
  Translator: 'bg-blue-50 text-blue-700 ring-blue-200',
}

export const accountRoleLabels: Record<AccountRole, string> = {
  admin: 'System Administrator',
  user: 'Standard user',
}

export function getProjectRole(project: Project, userId: string): ProjectRole | null {
  if (project.managerId === userId) return 'Manager'
  if (project.reviewerIds.includes(userId)) return 'Reviewer'
  if (project.translatorIds.includes(userId)) return 'Translator'
  return null
}

export const statusColors: Record<TranslationStatus, string> = {
  Draft: 'bg-slate-100 text-slate-600',
  'In review': 'bg-amber-50 text-amber-700',
  Approved: 'bg-emerald-50 text-emerald-700',
  'Needs revision': 'bg-rose-50 text-rose-700',
}

export function formatNumber(value: number, language: UiLanguage = 'en'): string {
  return new Intl.NumberFormat(language === 'vi' ? 'vi-VN' : 'en-US').format(value)
}

export function timeAgo(value: string, language: UiLanguage = 'en'): string {
  const minutes = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60_000))
  if (minutes < 1) return language === 'vi' ? 'Vừa xong' : 'Just now'
  if (minutes < 60) return language === 'vi' ? `${minutes} phút trước` : `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return language === 'vi' ? `${hours} giờ trước` : `${hours}h ago`
  const days = Math.floor(hours / 24)
  return language === 'vi' ? `${days} ngày trước` : `${days}d ago`
}

export function getInitials(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .slice(-2)
    .map((part) => part[0] ?? '')
    .join('')
    .toUpperCase()
}

export function getFriendlyDate(value: string, language: UiLanguage = 'en'): string {
  return new Intl.DateTimeFormat(language === 'vi' ? 'vi-VN' : 'en-US', { month: 'short', day: 'numeric' }).format(new Date(value))
}
