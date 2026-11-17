import { CheckCircle2, Info, TriangleAlert, X, XCircle } from 'lucide-react'
import { useAppStore } from '../../store/appStore'
import { translateText } from '../../lib/i18n'

const toastIcons = {
  success: CheckCircle2,
  info: Info,
  warning: TriangleAlert,
  error: XCircle,
}

const toastColors = {
  success: 'text-emerald-600 bg-emerald-50',
  info: 'text-blue-600 bg-blue-50',
  warning: 'text-amber-600 bg-amber-50',
  error: 'text-rose-600 bg-rose-50',
}

export function ToastStack() {
  const toasts = useAppStore((state) => state.toasts)
  const dismiss = useAppStore((state) => state.dismissToast)
  const language = useAppStore((state) => state.language)

  return (
    <div className="fixed bottom-4 right-4 z-[80] flex w-[min(360px,calc(100vw-32px))] flex-col gap-2" aria-live="polite" aria-relevant="additions text">
      {toasts.map((toast) => {
        const Icon = toastIcons[toast.tone]
        return (
          <div key={toast.id} className="toast-enter flex items-start gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3.5 shadow-xl">
            <span className={`mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-xl ${toastColors[toast.tone]}`}>
              <Icon size={17} aria-hidden="true" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-[var(--ink)]">{translateText(language, toast.title)}</p>
              {toast.message && <p className="mt-0.5 text-xs leading-5 text-[var(--muted)]">{translateText(language, toast.message)}</p>}
            </div>
            <button type="button" onClick={() => dismiss(toast.id)} aria-label={translateText(language, 'Dismiss notification')} className="rounded-lg p-1 text-[var(--muted)] hover:bg-[var(--surface-soft)]">
              <X size={15} aria-hidden="true" />
            </button>
          </div>
        )
      })}
    </div>
  )
}
