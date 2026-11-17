import type { AuditEntry, Project, TeamUser, TranslationRow } from '../types'

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString()

export const initialUsers: TeamUser[] = [
  { id: 'u-01', name: 'Linh Nguyễn', email: 'linh.nguyen@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2401', role: 'Manager' }, { projectId: 'PRJ-2392', role: 'Manager' }, { projectId: 'PRJ-2374', role: 'Manager' }, { projectId: 'PRJ-2380', role: 'Manager' }], active: true, avatar: 'LN', color: 'violet', projects: 4, joinedAt: '2024-01-18' },
  { id: 'u-02', name: 'Minh Trần', email: 'minh.tran@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2401', role: 'Translator' }, { projectId: 'PRJ-2398', role: 'Translator' }, { projectId: 'PRJ-2380', role: 'Translator' }, { projectId: 'PRJ-2361', role: 'Translator' }], active: true, avatar: 'MT', color: 'blue', projects: 4, joinedAt: '2024-03-04' },
  { id: 'u-03', name: 'An Phạm', email: 'an.pham@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2401', role: 'Reviewer' }, { projectId: 'PRJ-2392', role: 'Reviewer' }, { projectId: 'PRJ-2361', role: 'Reviewer' }, { projectId: 'PRJ-2398', role: 'Manager' }], active: true, avatar: 'AP', color: 'amber', projects: 4, joinedAt: '2024-02-12' },
  { id: 'u-04', name: 'Hà Lê', email: 'ha.le@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2401', role: 'Translator' }, { projectId: 'PRJ-2392', role: 'Translator' }, { projectId: 'PRJ-2374', role: 'Translator' }], active: true, avatar: 'HL', color: 'rose', projects: 3, joinedAt: '2024-06-25' },
  { id: 'u-05', name: 'Quân Võ', email: 'quan.vo@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2398', role: 'Reviewer' }, { projectId: 'PRJ-2380', role: 'Reviewer' }, { projectId: 'PRJ-2361', role: 'Manager' }], active: true, avatar: 'QV', color: 'emerald', projects: 3, joinedAt: '2023-11-09' },
  { id: 'u-06', name: 'Mai Đặng', email: 'mai.dang@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2380', role: 'Translator' }], active: false, avatar: 'MĐ', color: 'pink', projects: 1, joinedAt: '2024-08-16' },
  { id: 'u-07', name: 'Phúc Bùi', email: 'phuc.bui@lingua.io', accountRole: 'admin', projectRoles: [], active: true, avatar: 'PB', color: 'cyan', projects: 0, joinedAt: '2023-09-22' },
  { id: 'u-08', name: 'Thảo Đỗ', email: 'thao.do@lingua.io', accountRole: 'user', projectRoles: [{ projectId: 'PRJ-2398', role: 'Reviewer' }, { projectId: 'PRJ-2374', role: 'Reviewer' }], active: true, avatar: 'TĐ', color: 'indigo', projects: 2, joinedAt: '2024-04-03' },
]

export const initialProjects: Project[] = [
  { id: 'PRJ-2401', name: 'Lingo Mobile App', description: 'Mobile app launch localization for APAC markets.', sourceLocale: 'en', targetLocales: ['vi', 'ja', 'ko', 'zh'], status: 'Active', progress: 78, words: 18420, strings: 1240, updatedAt: minutesAgo(12), managerId: 'u-01', translatorIds: ['u-02', 'u-04'], reviewerIds: ['u-03'], accent: 'violet' },
  { id: 'PRJ-2398', name: 'Northstar Website', description: 'Marketing pages, product education and help center.', sourceLocale: 'en', targetLocales: ['vi', 'fr', 'de'], status: 'Review', progress: 92, words: 28600, strings: 1875, updatedAt: minutesAgo(46), managerId: 'u-03', translatorIds: ['u-02'], reviewerIds: ['u-05', 'u-08'], accent: 'blue' },
  { id: 'PRJ-2392', name: 'Atlas Help Center', description: 'Support articles and onboarding content.', sourceLocale: 'en', targetLocales: ['vi', 'th'], status: 'Active', progress: 54, words: 12850, strings: 946, updatedAt: minutesAgo(180), managerId: 'u-01', translatorIds: ['u-04'], reviewerIds: ['u-03'], accent: 'emerald' },
  { id: 'PRJ-2380', name: 'Finwise Dashboard', description: 'A new finance experience, localized for launch.', sourceLocale: 'en', targetLocales: ['vi', 'ja'], status: 'Completed', progress: 100, words: 9320, strings: 682, updatedAt: minutesAgo(420), managerId: 'u-01', translatorIds: ['u-02', 'u-06'], reviewerIds: ['u-05'], accent: 'amber' },
  { id: 'PRJ-2374', name: 'Bloom Commerce', description: 'Checkout, catalog and seasonal campaign copy.', sourceLocale: 'en', targetLocales: ['vi', 'id', 'ms'], status: 'On hold', progress: 31, words: 7460, strings: 514, updatedAt: minutesAgo(1080), managerId: 'u-01', translatorIds: ['u-04'], reviewerIds: ['u-08'], accent: 'rose' },
  { id: 'PRJ-2361', name: 'Orbit Partner Portal', description: 'Partner resources and account management.', sourceLocale: 'en', targetLocales: ['vi', 'ko'], status: 'Active', progress: 66, words: 16400, strings: 1108, updatedAt: minutesAgo(1620), managerId: 'u-05', translatorIds: ['u-02'], reviewerIds: ['u-03'], accent: 'cyan' },
]

export const initialTranslations: TranslationRow[] = [
  { id: 'tr-01', projectId: 'PRJ-2401', key: 'welcome.title', source: 'Welcome back to your workspace', translated: 'Chào mừng bạn quay lại không gian làm việc', sourceLocale: 'en', targetLocale: 'vi', status: 'Approved', assigneeId: 'u-02', updatedAt: minutesAgo(18) },
  { id: 'tr-02', projectId: 'PRJ-2401', key: 'welcome.subtitle', source: 'Everything you need, all in one place', translated: 'Mọi thứ bạn cần, tất cả trong một nơi', sourceLocale: 'en', targetLocale: 'vi', status: 'In review', assigneeId: 'u-02', updatedAt: minutesAgo(25) },
  { id: 'tr-03', projectId: 'PRJ-2401', key: 'settings.notifications', source: 'Manage your notification preferences', translated: '', sourceLocale: 'en', targetLocale: 'vi', status: 'Draft', assigneeId: 'u-02', updatedAt: minutesAgo(45) },
  { id: 'tr-04', projectId: 'PRJ-2401', key: 'checkout.payment_method', source: 'Choose a payment method', translated: '', sourceLocale: 'en', targetLocale: 'vi', status: 'Draft', assigneeId: 'u-04', updatedAt: minutesAgo(60) },
  { id: 'tr-05', projectId: 'PRJ-2398', key: 'hero.heading', source: 'Make work feel effortless', translated: 'Biến công việc trở nên nhẹ nhàng hơn', sourceLocale: 'en', targetLocale: 'vi', status: 'Needs revision', assigneeId: 'u-02', updatedAt: minutesAgo(75), comment: 'Please keep the tone more concise.' },
  { id: 'tr-06', projectId: 'PRJ-2398', key: 'nav.pricing', source: 'Simple, transparent pricing', translated: 'Giá cả minh bạch, đơn giản', sourceLocale: 'en', targetLocale: 'vi', status: 'In review', assigneeId: 'u-02', updatedAt: minutesAgo(95) },
  { id: 'tr-07', projectId: 'PRJ-2392', key: 'support.contact.title', source: 'How can we help you today?', translated: '', sourceLocale: 'en', targetLocale: 'vi', status: 'Draft', assigneeId: 'u-04', updatedAt: minutesAgo(130) },
  { id: 'tr-08', projectId: 'PRJ-2392', key: 'support.contact.button', source: 'Contact our support team', translated: 'Liên hệ với đội ngũ hỗ trợ', sourceLocale: 'en', targetLocale: 'vi', status: 'Approved', assigneeId: 'u-04', updatedAt: minutesAgo(155) },
  { id: 'tr-09', projectId: 'PRJ-2401', key: 'account.profile.save', source: 'Save changes', translated: '', sourceLocale: 'en', targetLocale: 'ab', status: 'Draft', assigneeId: 'u-02', updatedAt: minutesAgo(185) },
  { id: 'tr-10', projectId: 'PRJ-2401', key: 'account.profile.cancel', source: 'Discard changes', translated: '', sourceLocale: 'en', targetLocale: 'aa', status: 'Draft', assigneeId: 'u-02', updatedAt: minutesAgo(195) },
  { id: 'tr-11', projectId: 'PRJ-2380', key: 'dashboard.monthly_report', source: 'Your monthly report is ready', translated: 'Báo cáo tháng của bạn đã sẵn sàng', sourceLocale: 'en', targetLocale: 'vi', status: 'Approved', assigneeId: 'u-06', updatedAt: minutesAgo(280) },
  { id: 'tr-12', projectId: 'PRJ-2361', key: 'team.invite.success', source: 'Your invitation has been sent', translated: '', sourceLocale: 'en', targetLocale: 'vi', status: 'Draft', assigneeId: 'u-02', updatedAt: minutesAgo(320) },
]

const now = Date.now()
export const initialLogs: AuditEntry[] = [
  { id: 'log-01', actorId: 'u-03', action: 'Translation approved', target: 'welcome.title', projectId: 'PRJ-2401', details: 'Approved Vietnamese translation after terminology review.', createdAt: new Date(now - 8 * 60_000).toISOString() },
  { id: 'log-02', actorId: 'u-01', action: 'Assignment changed', target: 'Minh Trần → PRJ-2401', projectId: 'PRJ-2401', details: 'Translator assigned to Lingo Mobile App.', createdAt: new Date(now - 35 * 60_000).toISOString() },
  { id: 'log-03', actorId: 'u-02', action: 'Translation saved', target: 'welcome.subtitle', projectId: 'PRJ-2401', details: 'Saved a draft translation in Vietnamese.', createdAt: new Date(now - 72 * 60_000).toISOString() },
  { id: 'log-04', actorId: 'u-05', action: 'Revision requested', target: 'hero.heading', projectId: 'PRJ-2398', details: 'Requested a shorter, more direct marketing tone.', createdAt: new Date(now - 3 * 60 * 60_000).toISOString() },
  { id: 'log-05', actorId: 'u-07', action: 'Project updated', target: 'Northstar Website', projectId: 'PRJ-2398', details: 'Updated project delivery date and team members.', createdAt: new Date(now - 5 * 60 * 60_000).toISOString() },
  { id: 'log-06', actorId: 'u-01', action: 'Project created', target: 'Atlas Help Center', projectId: 'PRJ-2392', details: 'Created project and invited its translation team.', createdAt: new Date(now - 26 * 60 * 60_000).toISOString() },
  { id: 'log-07', actorId: 'u-04', action: 'Translation saved', target: 'support.contact.button', projectId: 'PRJ-2392', details: 'Saved a revised translation.', createdAt: new Date(now - 30 * 60 * 60_000).toISOString() },
  { id: 'log-08', actorId: 'u-08', action: 'Translation approved', target: 'nav.pricing', projectId: 'PRJ-2398', details: 'Approved translation for publishing.', createdAt: new Date(now - 40 * 60 * 60_000).toISOString() },
  { id: 'log-09', actorId: 'u-07', action: 'Settings updated', target: 'MyMemory provider', details: 'Updated the contact email used for translation requests.', createdAt: new Date(now - 58 * 60 * 60_000).toISOString() },
  { id: 'log-10', actorId: 'u-02', action: 'Translation saved', target: 'checkout.payment_method', projectId: 'PRJ-2401', details: 'Saved a draft translation in Vietnamese.', createdAt: new Date(now - 76 * 60 * 60_000).toISOString() },
]

export const weeklyProgress = [
  { day: 'Mon', translated: 520, reviewed: 380 },
  { day: 'Tue', translated: 680, reviewed: 460 },
  { day: 'Wed', translated: 610, reviewed: 520 },
  { day: 'Thu', translated: 890, reviewed: 640 },
  { day: 'Fri', translated: 740, reviewed: 570 },
  { day: 'Sat', translated: 360, reviewed: 250 },
  { day: 'Sun', translated: 420, reviewed: 320 },
]

export const languageProgress = [
  { locale: 'Vietnamese', code: 'VI', progress: 84, color: '#6558f5', words: '38.4k' },
  { locale: 'Japanese', code: 'JA', progress: 67, color: '#16a085', words: '30.6k' },
  { locale: 'Korean', code: 'KO', progress: 52, color: '#ef9d4b', words: '23.8k' },
  { locale: 'French', code: 'FR', progress: 41, color: '#ed6b82', words: '18.7k' },
]
