import type { HTMLAttributes, ReactNode } from 'react'

export function Panel({ children, className = '', ...props }: HTMLAttributes<HTMLElement> & { children: ReactNode }) {
  return (
    <section
      className={`rounded-2xl border border-[var(--line)] bg-[var(--surface)] shadow-[0_8px_24px_rgba(27,39,68,0.035)] ${className}`}
      {...props}
    >
      {children}
    </section>
  )
}

export function PanelHeading({
  title,
  subtitle,
  action,
  className = '',
}: {
  title: string
  subtitle?: string
  action?: ReactNode
  className?: string
}) {
  return (
    <div className={`flex flex-wrap items-start justify-between gap-3 ${className}`}>
      <div>
        <h2 className="text-sm font-bold tracking-tight text-[var(--ink)]">{title}</h2>
        {subtitle && <p className="mt-1 text-xs text-[var(--muted)]">{subtitle}</p>}
      </div>
      {action}
    </div>
  )
}

export function PageHeading({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow?: string
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        {eyebrow && <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-violet-600">{eyebrow}</p>}
        <h1 className="text-2xl font-bold tracking-tight text-[var(--ink)] sm:text-[28px]">{title}</h1>
        <p className="mt-1.5 max-w-2xl text-sm text-[var(--muted)]">{description}</p>
      </div>
      {action}
    </div>
  )
}
