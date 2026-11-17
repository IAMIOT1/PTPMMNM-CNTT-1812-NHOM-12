import { X } from 'lucide-react'
import { useEffect, useId, useRef, type ReactNode } from 'react'
import { useAppStore } from '../../store/appStore'
import { translateText } from '../../lib/i18n'

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  size = 'md',
}: {
  open: boolean
  onClose: () => void
  title: string
  description?: string
  children: ReactNode
  size?: 'md' | 'lg'
}) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()
  const descriptionId = useId()
  const language = useAppStore((state) => state.language)

  useEffect(() => {
    const dialog = dialogRef.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={dialogRef}
      aria-labelledby={titleId}
      aria-describedby={description ? descriptionId : undefined}
      onClose={onClose}
      onCancel={(event) => {
        event.preventDefault()
        dialogRef.current?.close()
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.preventDefault()
          dialogRef.current?.close()
        }
      }}
      onClick={(event) => {
        if (event.target === event.currentTarget) dialogRef.current?.close()
      }}
      className={`modal-dialog m-auto max-h-[min(90dvh,760px)] w-[calc(100%-24px)] overflow-y-auto rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-0 text-[var(--ink)] shadow-2xl backdrop:bg-slate-950/45 ${size === 'lg' ? 'max-w-2xl' : 'max-w-lg'}`}
    >
      <div className="flex items-start justify-between gap-4 border-b border-[var(--line)] px-5 py-4 sm:px-6">
        <div>
          <h2 id={titleId} className="text-lg font-bold">{title}</h2>
          {description && <p id={descriptionId} className="mt-1 text-sm text-[var(--muted)]">{description}</p>}
        </div>
        <button
          type="button"
          onClick={() => dialogRef.current?.close()}
          className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--surface-soft)] hover:text-[var(--ink)]"
          aria-label={translateText(language, 'Close dialog')}
        >
          <X size={18} aria-hidden="true" />
        </button>
      </div>
      <div className="px-5 py-5 sm:px-6">{children}</div>
    </dialog>
  )
}
