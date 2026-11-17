import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from './Button'
import { LocalizedContent } from './LocalizedContent'

export function Pagination({
  page,
  pageSize,
  total,
  onChange,
}: {
  page: number
  pageSize: number
  total: number
  onChange: (page: number) => void
}) {
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const start = total === 0 ? 0 : (page - 1) * pageSize + 1
  const end = Math.min(page * pageSize, total)
  return (
    <LocalizedContent>
    <nav className="flex flex-col gap-3 text-xs text-[var(--muted)] sm:flex-row sm:items-center sm:justify-between" aria-label="Table pages">
      <p>Showing <strong className="font-semibold text-[var(--ink)]">{start}–{end}</strong> of <strong className="font-semibold text-[var(--ink)]">{total}</strong></p>
      <div className="flex items-center gap-2">
        <Button size="sm" icon={<ChevronLeft size={14} />} onClick={() => onChange(Math.max(1, page - 1))} disabled={page <= 1} aria-label="Previous page">
          Previous
        </Button>
        <span className="min-w-16 text-center font-medium text-[var(--ink)]">Page {page} / {pages}</span>
        <Button size="sm" icon={<ChevronRight size={14} />} onClick={() => onChange(Math.min(pages, page + 1))} disabled={page >= pages} aria-label="Next page">
          Next
        </Button>
      </div>
    </nav>
    </LocalizedContent>
  )
}
