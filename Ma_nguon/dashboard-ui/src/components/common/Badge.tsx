import type { ReactNode } from 'react'

interface BadgeProps {
  children: ReactNode
  tone?: 'neutral' | 'violet' | 'blue' | 'green' | 'amber' | 'rose'
  dot?: boolean
}

const toneStyles = {
  neutral: 'bg-slate-100 text-slate-600',
  violet: 'bg-violet-50 text-violet-700',
  blue: 'bg-blue-50 text-blue-700',
  green: 'bg-emerald-50 text-emerald-700',
  amber: 'bg-amber-50 text-amber-700',
  rose: 'bg-rose-50 text-rose-700',
}

export function Badge({ children, tone = 'neutral', dot = false }: BadgeProps) {
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${toneStyles[tone]}`}>
      {dot && <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />}
      {children}
    </span>
  )
}
