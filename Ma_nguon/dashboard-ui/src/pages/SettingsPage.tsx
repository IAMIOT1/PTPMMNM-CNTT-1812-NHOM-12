import { Check, Moon, Save, Sun, Monitor, TestTube2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { useAppStore } from '../store/appStore'
import type { ThemePreference, TranslationSettings } from '../types'

const themeChoices: { value: ThemePreference; label: string; description: string; icon: typeof Sun }[] = [
  { value: 'light', label: 'Light', description: 'A crisp, bright workspace.', icon: Sun },
  { value: 'dark', label: 'Dark', description: 'A lower-light interface.', icon: Moon },
  { value: 'system', label: 'System', description: 'Follow your device preference.', icon: Monitor },
]

export function SettingsPage() {
  const settings = useAppStore((state) => state.settings)
  const updateSettings = useAppStore((state) => state.updateSettings)
  const theme = useAppStore((state) => state.theme)
  const setTheme = useAppStore((state) => state.setTheme)
  const addToast = useAppStore((state) => state.addToast)
  const [form, setForm] = useState<TranslationSettings>(settings)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)

  const handleSave = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.contactEmail.trim())) {
      setError('Enter a valid contact email address.')
      return
    }
    try {
      const apiUrl = new URL(form.apiBaseUrl)
      if (apiUrl.protocol !== 'https:' && apiUrl.hostname !== 'localhost') {
        setError('Use HTTPS for the translation API endpoint.')
        return
      }
    } catch {
      setError('Enter a valid absolute URL for the API endpoint.')
      return
    }
    if (!Number.isInteger(form.requestDelay) || form.requestDelay < 0 || form.requestDelay > 10000) {
      setError('Request delay must be a whole number between 0 and 10,000 milliseconds.')
      return
    }
    updateSettings({ ...form, contactEmail: form.contactEmail.trim(), apiBaseUrl: form.apiBaseUrl.trim() })
    setError('')
    setSaved(true)
    addToast({ title: 'Settings saved', message: 'Your workspace preferences are stored in this browser.', tone: 'success' })
    window.setTimeout(() => setSaved(false), 2500)
  }

  const testProvider = () => {
    try {
      const endpoint = new URL(form.apiBaseUrl)
      addToast({ title: 'Preference format looks valid', message: `${endpoint.hostname} is not contacted. Translation requests use the authenticated PHP API and server-side provider configuration.`, tone: 'info' })
    } catch {
      addToast({ title: 'Invalid provider URL', message: 'Enter a valid URL before testing.', tone: 'error' })
    }
  }

  return (
    <LocalizedContent>
    <div className="space-y-5">
      <PageHeading eyebrow="Workspace preferences" title="Settings" description="Personalize this browser's workspace appearance and translation queue preferences." />
      <Panel className="p-4 sm:p-6">
        <PanelHeading title="Appearance" subtitle="Your selection is saved with the rest of the browser demo state." />
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          {themeChoices.map(({ value, label, description, icon: Icon }) => <button key={value} type="button" onClick={() => setTheme(value)} aria-pressed={theme === value} className={`rounded-xl border p-4 text-left transition ${theme === value ? 'border-violet-400 bg-violet-50/60 ring-2 ring-violet-500/10' : 'border-[var(--line)] hover:border-violet-300'}`}><div className="flex items-center justify-between"><span className={`grid size-9 place-items-center rounded-xl ${theme === value ? 'bg-violet-100 text-violet-700' : 'bg-[var(--surface-soft)] text-[var(--muted)]'}`}><Icon size={17} aria-hidden="true" /></span>{theme === value && <Check size={16} className="text-violet-700" aria-hidden="true" />}</div><p className="mt-3 text-xs font-bold text-[var(--ink)]">{label}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{description}</p></button>)}
        </div>
      </Panel>
      <Panel className="p-4 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3"><PanelHeading title="Translation provider" subtitle="Translation suggestions use the authenticated PHP API and server-side MyMemory configuration." /><Badge tone="blue" dot>Server-side</Badge></div>
        <form onSubmit={handleSave} className="mt-5 space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="field-label sm:col-span-2">Provider endpoint preference<input type="url" required value={form.apiBaseUrl} onChange={(event) => setForm({ ...form, apiBaseUrl: event.target.value })} className="field-control font-mono text-xs" placeholder="https://api.mymemory.translated.net/get" /><span className="text-[10px] font-normal text-[var(--muted)]">Browser-only preference; changing it does not change the provider endpoint configured on the PHP server.</span></label>
            <label className="field-label">Contact email<input type="email" required value={form.contactEmail} onChange={(event) => setForm({ ...form, contactEmail: event.target.value })} className="field-control" placeholder="team@example.com" /></label>
            <label className="field-label">Delay between queued requests (ms)<input required type="number" min={0} max={10000} step={100} value={form.requestDelay} onChange={(event) => setForm({ ...form, requestDelay: Number(event.target.value) })} className="field-control" /><span className="text-[10px] font-normal text-[var(--muted)]">Applied between individual strings in sequential mode.</span></label>
          </div>
          {error && <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">{error}</p>}
          <div className="flex flex-col-reverse justify-between gap-3 border-t border-[var(--line)] pt-4 sm:flex-row sm:items-center"><p className="text-[10px] leading-5 text-[var(--muted)]">Never enter production secrets in this client-side demo.</p><div className="flex gap-2"><Button icon={<TestTube2 size={14} />} onClick={testProvider}>Validate setup</Button><Button type="submit" variant="primary" icon={saved ? <Check size={14} /> : <Save size={14} />}>{saved ? 'Saved' : 'Save settings'}</Button></div></div>
        </form>
      </Panel>
      <Panel className="p-4 sm:p-6">
        <PanelHeading title="Workspace access" subtitle="Projects, members, translations, and audit history are loaded from the authenticated PHP API." />
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <div className="rounded-xl bg-[var(--surface-soft)] p-4"><p className="text-xs font-bold text-[var(--ink)]">Account permissions</p><p className="mt-1 text-[11px] leading-5 text-[var(--muted)]">Access is based on your PHP session, system account role, and project assignments.</p></div>
          <div className="rounded-xl bg-[var(--surface-soft)] p-4"><p className="text-xs font-bold text-[var(--ink)]">Translation fallback</p><p className="mt-1 text-[11px] leading-5 text-[var(--muted)]">The server reports the effective target language when its translation provider uses a fallback.</p></div>
        </div>
      </Panel>
    </div>
    </LocalizedContent>
  )
}
