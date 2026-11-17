import { AlertCircle, ArrowRight, Check, ChevronDown, CirclePause, Languages, Play, Save, Search, Sparkles, WandSparkles } from 'lucide-react'
import { useMemo, useRef, useState, type FormEvent } from 'react'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { accountRoleLabels, getProjectRole, projectRoleLabels, timeAgo } from '../lib/utils'
import { useAppStore } from '../store/appStore'
import type { TranslationRow } from '../types'

const languageNames: Record<string, string> = {
  en: 'English',
  vi: 'Vietnamese',
  ja: 'Japanese',
  ko: 'Korean',
  zh: 'Chinese',
  aa: 'Afar',
  ab: 'Abkhazian',
}

export function TranslationPage() {
  const projects = useAppStore((state) => state.projects)
  const locales = useAppStore((state) => state.locales)
  const users = useAppStore((state) => state.users)
  const translations = useAppStore((state) => state.translations)
  const language = useAppStore((state) => state.language)
  const currentUserId = useAppStore((state) => state.currentUserId)
  const settings = useAppStore((state) => state.settings)
  const saveTranslation = useAppStore((state) => state.saveTranslation)
  const suggestTranslation = useAppStore((state) => state.suggestTranslation)
  const isMutating = useAppStore((state) => state.isMutating)
  const addToast = useAppStore((state) => state.addToast)
  const [projectId, setProjectId] = useState('')
  const [sourceFilter, setSourceFilter] = useState('All languages')
  const [targetFilter, setTargetFilter] = useState('All languages')
  const [activeId, setActiveId] = useState('')
  const [draft, setDraft] = useState<string | null>(null)
  const [queueSearch, setQueueSearch] = useState('')
  const [queueOutcome, setQueueOutcome] = useState<'idle' | 'running' | 'paused' | 'complete' | 'error'>('idle')
  const [activeQueueId, setActiveQueueId] = useState('')
  const [completedCount, setCompletedCount] = useState(0)
  const [totalCount, setTotalCount] = useState(0)
  const [fallbackInfo, setFallbackInfo] = useState('')
  const cancelRequested = useRef(false)
  const activeProject = projects.find((project) => project.id === projectId) ?? projects[0]
  const currentUser = users.find((user) => user.id === currentUserId) ?? users[0]
  const activeProjectRole = activeProject && currentUser ? getProjectRole(activeProject, currentUser.id) : null
  const running = queueOutcome === 'running'

  const selectedProjectId = projects.some((project) => project.id === projectId) ? projectId : projects[0]?.id ?? ''
  const projectRows = useMemo(() => translations.filter((row) => row.projectId === selectedProjectId), [selectedProjectId, translations])
  const filteredProjectRows = useMemo(() => projectRows.filter((row) => (
    (sourceFilter === 'All languages' || row.sourceLocale === sourceFilter)
    && (targetFilter === 'All languages' || row.targetLocale === targetFilter)
  )), [projectRows, sourceFilter, targetFilter])
  const sourceLocaleOptions = [...new Set([...locales.map((locale) => locale.code), ...projectRows.map((row) => row.sourceLocale)])]
  const targetLocaleOptions = [...new Set([...locales.map((locale) => locale.code), ...projectRows.map((row) => row.targetLocale)])]
  const pendingRows = useMemo(() => filteredProjectRows.filter((row) => ['Draft', 'Needs revision'].includes(row.status)
    && `${row.key} ${row.source}`.toLowerCase().includes(queueSearch.toLowerCase())), [filteredProjectRows, queueSearch])
  const activeRow = filteredProjectRows.find((row) => row.id === activeId) ?? pendingRows[0]
  const currentDraft = draft ?? activeRow?.translated ?? ''
  const resolvedFallbackInfo = fallbackInfo || (activeRow?.fallback
    ? `A fallback language was used for ${languageNames[activeRow.targetLocale] ?? activeRow.targetLocale}.`
    : '')
  const approvedCount = projectRows.filter((row) => row.status === 'Approved').length
  const inReviewCount = projectRows.filter((row) => row.status === 'In review').length

  const selectRow = (row: TranslationRow) => {
    setActiveId(row.id)
    setDraft(row.translated)
    setFallbackInfo(row.fallback ? `English fallback was used for this language pair.` : '')
  }

  const translateRow = async (row: TranslationRow) => {
    setActiveId(row.id)
    setDraft(row.translated)
    const result = await suggestTranslation(row.id)
    await saveTranslation(row.id, result.translated)
    setDraft(result.translated)
    setFallbackInfo(result.fallback ? `The provider used ${result.targetLocale.toUpperCase()} as a fallback for ${result.requestedTargetLocale.toUpperCase()}.` : '')
    if (result.fallback) {
      addToast({ title: 'Language fallback applied', message: `The provider returned a fallback for ${languageNames[row.targetLocale] ?? row.targetLocale}.`, tone: 'warning' })
    }
    return result
  }

  const runSequentialQueue = async (rows: TranslationRow[]) => {
    if (running || activeQueueId || rows.length === 0) return
    cancelRequested.current = false
    setQueueOutcome('running')
    setCompletedCount(0)
    setTotalCount(rows.length)
    let completed = 0
    try {
      for (const row of rows) {
        if (cancelRequested.current) break
        setActiveQueueId(row.id)
        await translateRow(row)
        completed += 1
        setCompletedCount(completed)
        if (completed < rows.length && !cancelRequested.current) {
          await new Promise((resolve) => window.setTimeout(resolve, settings.requestDelay))
        }
      }
      if (cancelRequested.current) {
        setQueueOutcome('paused')
        addToast({ title: 'Queue paused', message: `${completed} of ${rows.length} strings were processed.`, tone: 'info' })
      } else {
        setQueueOutcome('complete')
        addToast({ title: 'Translation queue complete', message: `${completed} strings were handled in sequence.`, tone: 'success' })
      }
    } catch (error) {
      setQueueOutcome('error')
      addToast({
        title: 'Translation request failed',
        message: error instanceof Error ? error.message : 'Try again in a moment.',
        tone: 'error',
      })
    } finally {
      setActiveQueueId('')
    }
  }

  const handleSave = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!activeRow || currentDraft.trim().length === 0) {
      addToast({ title: 'Translation required', message: 'Enter a translation before saving.', tone: 'warning' })
      return
    }
    try {
      await saveTranslation(activeRow.id, currentDraft.trim())
      setFallbackInfo('')
      addToast({ title: 'Draft saved', message: `${activeRow.key} is now waiting for review.`, tone: 'success' })
    } catch (error) {
      addToast({ title: 'Could not save translation', message: error instanceof Error ? error.message : undefined, tone: 'error' })
    }
  }

  const progress = totalCount === 0 ? 0 : Math.round((completedCount / totalCount) * 100)

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading
        eyebrow={currentUser?.accountRole === 'admin'
          ? `${accountRoleLabels.admin}${activeProjectRole ? ` · ${projectRoleLabels[activeProjectRole]}` : ''}`
          : activeProjectRole ? `${projectRoleLabels[activeProjectRole]} · ${activeProject?.name}` : 'Project workspace'}
        title="Translation queue"
        description="Work through strings in order. Suggestions come from the server-side translation provider and save to the workspace database."
        action={(
          <div className="flex items-center gap-2">
            {!running
              ? <Button variant="primary" icon={<Play size={15} />} disabled={Boolean(activeQueueId) || isMutating} onClick={() => runSequentialQueue(pendingRows)}>Translate all ({pendingRows.length})</Button>
              : <Button variant="danger" icon={<CirclePause size={15} />} onClick={() => { cancelRequested.current = true }}>Pause queue</Button>}
          </div>
        )}
      />

      <div className="grid gap-3 sm:grid-cols-3">
        <Panel className="flex items-center gap-3 p-4"><span className="grid size-10 place-items-center rounded-xl bg-violet-50 text-violet-600"><Languages size={18} aria-hidden="true" /></span><div><p className="text-[11px] text-[var(--muted)]">Strings in project</p><p className="text-lg font-bold text-[var(--ink)]">{projectRows.length}</p></div></Panel>
        <Panel className="flex items-center gap-3 p-4"><span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><Check size={18} aria-hidden="true" /></span><div><p className="text-[11px] text-[var(--muted)]">Approved</p><p className="text-lg font-bold text-[var(--ink)]">{approvedCount}</p></div></Panel>
        <Panel className="flex items-center gap-3 p-4"><span className="grid size-10 place-items-center rounded-xl bg-amber-50 text-amber-600"><AlertCircle size={18} aria-hidden="true" /></span><div><p className="text-[11px] text-[var(--muted)]">In review</p><p className="text-lg font-bold text-[var(--ink)]">{inReviewCount}</p></div></Panel>
      </div>

      {totalCount > 0 && (
        <Panel className="border-violet-200 bg-violet-50/60 p-4" aria-live="polite">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="flex items-center gap-2 text-xs font-bold text-violet-900"><WandSparkles size={15} aria-hidden="true" />{running ? 'Translating one string at a time' : queueOutcome === 'paused' ? 'Queue paused' : queueOutcome === 'error' ? 'Queue stopped after an error' : 'Queue complete'}</p>
            <span className="text-xs font-semibold text-violet-800">{completedCount}/{totalCount} completed · {progress}%</span>
          </div>
          <div className="mt-3 h-2 overflow-hidden rounded-full bg-white"><span className="block h-full rounded-full bg-violet-600 transition-[width] duration-300" style={{ width: `${progress}%` }} /></div>
          <p className="mt-2 text-[10px] text-violet-800/80">{running ? `Current: ${projectRows.find((row) => row.id === activeQueueId)?.key ?? 'Preparing next string'} · ${settings.requestDelay}ms between requests` : `${completedCount} of ${totalCount} strings were processed sequentially.`}</p>
        </Panel>
      )}

      <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(300px,.8fr)_minmax(0,1.5fr)]">
        <Panel className="min-w-0 overflow-hidden">
          <div className="border-b border-[var(--line)] p-4">
            <PanelHeading title="Work queue" subtitle={activeProject?.name ?? 'Select a project'} />
            <div className="mt-3 grid gap-2 sm:grid-cols-[minmax(150px,1fr)_auto_auto]">
              <label>
                <span className="sr-only">Select project</span>
                <select value={selectedProjectId} onChange={(event) => {
                  setProjectId(event.target.value)
                  const nextRow = translations.find((row) => row.projectId === event.target.value
                    && ['Draft', 'Needs revision'].includes(row.status)
                    && (sourceFilter === 'All languages' || row.sourceLocale === sourceFilter)
                    && (targetFilter === 'All languages' || row.targetLocale === targetFilter))
                  setDraft(null)
                  if (nextRow) setActiveId(nextRow.id)
                  else setActiveId('')
                }} className="h-9 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-xs text-[var(--ink)]">
                  {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                </select>
              </label>
              <label htmlFor="queue-source-filter" className="sr-only">Filter source language</label>
              <select id="queue-source-filter" value={sourceFilter} onChange={(event) => setSourceFilter(event.target.value)} className="h-9 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-xs text-[var(--ink)]">
                <option value="All languages">All sources</option>{sourceLocaleOptions.map((locale) => <option key={locale} value={locale}>{locales.find((item) => item.code === locale)?.name ?? languageNames[locale] ?? locale.toUpperCase()}</option>)}
              </select>
              <label htmlFor="queue-target-filter" className="sr-only">Filter target language</label>
              <select id="queue-target-filter" value={targetFilter} onChange={(event) => setTargetFilter(event.target.value)} className="h-9 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-xs text-[var(--ink)]">
                <option value="All languages">All targets</option>{targetLocaleOptions.map((locale) => <option key={locale} value={locale}>{locales.find((item) => item.code === locale)?.name ?? languageNames[locale] ?? locale.toUpperCase()}</option>)}
              </select>
            </div>
            <label className="relative mt-2 block">
              <span className="sr-only">Search queue</span>
              <Search size={14} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
              <input value={queueSearch} onChange={(event) => setQueueSearch(event.target.value)} placeholder="Find a source string..." className="h-9 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] pl-8 pr-2.5 text-xs outline-none focus:border-violet-400" />
            </label>
          </div>
          <div className="max-h-[620px] divide-y divide-[var(--line)] overflow-y-auto">
            {pendingRows.map((row) => {
              const isSelected = row.id === (activeRow?.id ?? '')
              const isRunning = row.id === activeQueueId
              return (
                <button key={row.id} type="button" onClick={() => selectRow(row)} className={`block w-full px-4 py-3.5 text-left transition hover:bg-[var(--surface-soft)] ${isSelected ? 'border-l-2 border-violet-600 bg-violet-50/65' : 'border-l-2 border-transparent'}`} aria-current={isSelected ? 'true' : undefined}>
                  <span className="flex items-center justify-between gap-2">
                    <span className="truncate font-mono text-[10px] font-semibold text-violet-700">{row.key}</span>
                    <span className="inline-flex items-center gap-1 text-[9px] text-[var(--muted)]">{languageNames[row.targetLocale] ?? row.targetLocale.toUpperCase()} <ChevronDown size={11} aria-hidden="true" /></span>
                  </span>
                  <span className="mt-1.5 block line-clamp-2 text-xs leading-5 text-[var(--ink)]">{row.source}</span>
                  <span className="mt-2 flex items-center justify-between">
                    <Badge tone={row.status === 'Needs revision' ? 'rose' : row.status === 'In review' ? 'amber' : 'neutral'}>{isRunning ? 'Translating…' : row.status}</Badge>
                    <span className="text-[9px] text-[var(--muted)]">{timeAgo(row.updatedAt, language)}</span>
                  </span>
                </button>
              )
            })}
            {pendingRows.length === 0 && <p className="px-4 py-12 text-center text-xs text-[var(--muted)]">No strings match this queue filter.</p>}
          </div>
          <div className="border-t border-[var(--line)] bg-[var(--surface-soft)]/70 px-4 py-3 text-[10px] text-[var(--muted)]">
            <span className="font-semibold text-[var(--ink)]">{pendingRows.length}</span> untranslated or returned strings · queue is sequential
          </div>
        </Panel>

        <Panel className="min-w-0 p-4 sm:p-6">
          {activeRow ? (
            <form onSubmit={handleSave} className="space-y-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-mono text-[11px] font-bold text-violet-700">{activeRow.key}</p>
                  <p className="mt-1 text-[10px] text-[var(--muted)]">Source · {languageNames[activeRow.sourceLocale] ?? activeRow.sourceLocale.toUpperCase()}</p>
                </div>
                <Badge tone={activeRow.status === 'Approved' ? 'green' : activeRow.status === 'Needs revision' ? 'rose' : activeRow.status === 'In review' ? 'amber' : 'neutral'} dot>{activeRow.status}</Badge>
              </div>

              <section className="rounded-2xl border border-[var(--line)] bg-[var(--surface-soft)]/65 p-4 sm:p-5" aria-labelledby="source-heading">
                <div className="mb-2 flex items-center justify-between gap-2">
                  <h2 id="source-heading" className="text-[10px] font-bold uppercase tracking-[0.13em] text-[var(--muted)]">Source text</h2>
                  <span className="text-[10px] text-[var(--muted)]">{activeRow.source.length} characters</span>
                </div>
                <p className="text-sm leading-7 text-[var(--ink)]">{activeRow.source}</p>
              </section>

              <section aria-labelledby="translation-heading">
                <div className="mb-2 flex items-center justify-between gap-2">
                  <label id="translation-heading" htmlFor="translation-draft" className="text-[10px] font-bold uppercase tracking-[0.13em] text-[var(--muted)]">Translation · {languageNames[activeRow.targetLocale] ?? activeRow.targetLocale.toUpperCase()}</label>
                  <button type="button" onClick={async () => {
                    try {
                      setActiveQueueId(activeRow.id)
                      await translateRow(activeRow)
                      addToast({ title: 'Translation suggested and saved', tone: 'success' })
                    } catch (error) {
                      addToast({ title: 'Could not translate this string', message: error instanceof Error ? error.message : undefined, tone: 'error' })
                    } finally {
                      setActiveQueueId('')
                    }
                  }} disabled={running || Boolean(activeQueueId) || isMutating} className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[10px] font-bold text-violet-700 hover:bg-violet-50 disabled:opacity-50">
                    <Sparkles size={13} aria-hidden="true" /> Suggest translation
                  </button>
                </div>
                <textarea
                  id="translation-draft"
                  rows={5}
                  value={currentDraft}
                  onChange={(event) => setDraft(event.target.value)}
                  onFocus={() => { if (!draft && activeRow.translated) setDraft(activeRow.translated) }}
                  placeholder="Type your translation, or ask the configured provider for a suggestion..."
                  className="field-control min-h-32 resize-y text-sm leading-6"
                  disabled={running || Boolean(activeQueueId)}
                  required
                />
                <div className="mt-2 flex items-center justify-between text-[10px] text-[var(--muted)]">
                  <span>{currentDraft.length} characters</span>
                  <span>{currentUser?.name} · {currentUser?.accountRole === 'admin'
                    ? `${accountRoleLabels.admin}${activeProjectRole ? ` · ${projectRoleLabels[activeProjectRole]}` : ''}`
                    : `Project role: ${activeProjectRole ? projectRoleLabels[activeProjectRole] : 'No project role'}`} · {activeProject?.name}</span>
                </div>
              </section>

              {activeRow.comment && (
                <div className="flex gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                  <AlertCircle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
                  <span><strong>Reviewer feedback</strong><br />{activeRow.comment}</span>
                </div>
              )}
              {resolvedFallbackInfo && (
                <div className="flex gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800" role="status">
                  <AlertCircle size={15} className="shrink-0" aria-hidden="true" /> {resolvedFallbackInfo}
                </div>
              )}

              <div className="flex flex-col-reverse justify-between gap-3 border-t border-[var(--line)] pt-4 sm:flex-row sm:items-center">
                <p className="text-[10px] text-[var(--muted)]">Last updated {timeAgo(activeRow.updatedAt, language)}</p>
                <div className="flex items-center gap-2">
                  <Button onClick={() => setDraft(activeRow.translated)} disabled={running || Boolean(activeQueueId)}>Reset</Button>
                  <Button variant="primary" type="submit" icon={<Save size={15} />} disabled={running || Boolean(activeQueueId) || isMutating}>Save draft</Button>
                </div>
              </div>
              <p className="text-[10px] leading-5 text-[var(--muted)]">Translation text is sent to the configured provider by the PHP server. Provider credentials remain server-side.</p>
            </form>
          ) : (
            <div className="grid min-h-80 place-items-center text-center">
              <div><span className="mx-auto grid size-12 place-items-center rounded-2xl bg-violet-50 text-violet-600"><Languages size={22} aria-hidden="true" /></span><h2 className="mt-3 text-sm font-bold text-[var(--ink)]">Choose a string to translate</h2><p className="mt-1 text-xs text-[var(--muted)]">Your selected string will appear here.</p></div>
            </div>
          )}
        </Panel>
      </div>

      <Panel className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <span className="grid size-9 place-items-center rounded-xl bg-blue-50 text-blue-600"><Languages size={17} aria-hidden="true" /></span>
          <div><p className="text-xs font-bold text-[var(--ink)]">Provider: server configured · sequential mode</p><p className="mt-0.5 text-[10px] text-[var(--muted)]">Suggestions are requested one string at a time; fallback language is shown when returned by the provider.</p></div>
        </div>
        <Button size="sm" icon={<ArrowRight size={14} />} onClick={() => addToast({ title: 'Provider settings', message: 'Change delay and contact email in Settings.', tone: 'info' })}>Provider details</Button>
      </Panel>
    </div>
    </LocalizedContent>
  )
}
