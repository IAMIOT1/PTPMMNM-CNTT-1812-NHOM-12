import type { ButtonHTMLAttributes, ReactNode } from 'react'

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: 'sm' | 'md'
  icon?: ReactNode
}

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-violet-600 text-white shadow-sm shadow-violet-600/20 hover:bg-violet-700 focus-visible:outline-violet-600',
  secondary: 'border border-[var(--line)] bg-[var(--surface)] text-[var(--ink)] hover:bg-[var(--surface-soft)] focus-visible:outline-violet-500',
  ghost: 'text-[var(--muted)] hover:bg-[var(--surface-soft)] hover:text-[var(--ink)] focus-visible:outline-violet-500',
  danger: 'bg-rose-50 text-rose-700 hover:bg-rose-100 focus-visible:outline-rose-600',
}

export function Button({
  variant = 'secondary',
  size = 'md',
  icon,
  children,
  className = '',
  type = 'button',
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      className={`inline-flex min-h-10 items-center justify-center gap-2 rounded-xl px-3.5 text-sm font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:pointer-events-none disabled:opacity-50 ${size === 'sm' ? 'min-h-9 px-3 text-xs' : ''} ${variants[variant]} ${className}`}
      {...props}
    >
      {icon}
      {children}
    </button>
  )
}
