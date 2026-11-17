import type { TeamUser } from '../../types'

const avatarColors: Record<string, string> = {
  violet: 'bg-violet-100 text-violet-700',
  blue: 'bg-blue-100 text-blue-700',
  amber: 'bg-amber-100 text-amber-700',
  rose: 'bg-rose-100 text-rose-700',
  emerald: 'bg-emerald-100 text-emerald-700',
  pink: 'bg-pink-100 text-pink-700',
  cyan: 'bg-cyan-100 text-cyan-700',
  indigo: 'bg-indigo-100 text-indigo-700',
}

export function Avatar({ user, size = 'md' }: { user: Pick<TeamUser, 'avatar' | 'color' | 'name'>; size?: 'sm' | 'md' | 'lg' }) {
  return (
    <span
      className={`inline-flex shrink-0 items-center justify-center rounded-full font-bold ring-2 ring-[var(--surface)] ${avatarColors[user.color] ?? 'bg-slate-100 text-slate-700'} ${size === 'sm' ? 'size-7 text-[10px]' : size === 'lg' ? 'size-12 text-sm' : 'size-9 text-xs'}`}
      aria-label={user.name}
      title={user.name}
    >
      {user.avatar}
    </span>
  )
}

export function AvatarStack({ users, limit = 3 }: { users: TeamUser[]; limit?: number }) {
  const visible = users.slice(0, limit)
  return (
    <div className="flex items-center -space-x-2">
      {visible.map((user) => <Avatar key={user.id} user={user} size="sm" />)}
      {users.length > limit && (
        <span className="inline-flex size-7 items-center justify-center rounded-full bg-[var(--surface-soft)] text-[10px] font-semibold text-[var(--muted)] ring-2 ring-[var(--surface)]">
          +{users.length - limit}
        </span>
      )}
    </div>
  )
}
