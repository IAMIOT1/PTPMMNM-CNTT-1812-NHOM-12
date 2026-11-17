import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { LocalizedContent } from './LocalizedContent'

export interface TableColumn<T> {
  key: string
  header: string
  render: (row: T) => ReactNode
  sortValue?: (row: T) => string | number
  align?: 'left' | 'right'
  width?: string
  sortable?: boolean
}

export function DataTable<T extends { id: string }>({
  rows,
  columns,
  caption,
  empty,
}: {
  rows: T[]
  columns: TableColumn<T>[]
  caption: string
  empty?: ReactNode
}) {
  const [sortKey, setSortKey] = useState(columns[0]?.key ?? '')
  const [direction, setDirection] = useState<'ascending' | 'descending'>('ascending')
  const [announcement, setAnnouncement] = useState('')

  const sortedRows = useMemo(() => {
    const column = columns.find((item) => item.key === sortKey)
    if (!column?.sortValue) return rows
    const multiplier = direction === 'ascending' ? 1 : -1
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
    return [...rows].sort((a, b) => {
      const left = column.sortValue?.(a) ?? ''
      const right = column.sortValue?.(b) ?? ''
      const result = typeof left === 'number' && typeof right === 'number'
        ? left - right
        : collator.compare(String(left), String(right))
      return result * multiplier
    })
  }, [columns, direction, rows, sortKey])

  const sortBy = (column: TableColumn<T>) => {
    const nextDirection = sortKey === column.key && direction === 'ascending' ? 'descending' : 'ascending'
    setSortKey(column.key)
    setDirection(nextDirection)
    setAnnouncement(`Sorted by ${column.header}, ${nextDirection}, ${rows.length} results`)
  }

  return (
    <LocalizedContent>
    <div
      className="max-w-full overflow-x-auto overscroll-x-contain rounded-xl border border-[var(--line)]"
      role="region"
      aria-labelledby={`${caption.replace(/\W+/g, '-').toLowerCase()}-caption`}
      tabIndex={0}
    >
      <table className="w-full min-w-[700px] border-collapse text-left text-sm">
        <caption id={`${caption.replace(/\W+/g, '-').toLowerCase()}-caption`} className="sr-only">{caption}</caption>
        <thead className="sticky top-0 z-[1] bg-[var(--surface-soft)] text-[10px] uppercase tracking-[0.12em] text-[var(--muted)]">
          <tr>
            {columns.map((column) => {
              const ariaSort = sortKey === column.key ? direction : undefined
              return (
                <th
                  key={column.key}
                  scope="col"
                  aria-sort={ariaSort}
                  className={`whitespace-nowrap px-4 py-3 font-bold ${column.align === 'right' ? 'text-right' : ''} ${column.width ?? ''}`}
                >
                  {column.sortable === false || !column.sortValue
                    ? column.header
                    : (
                      <button
                        type="button"
                        onClick={() => sortBy(column)}
                        className="inline-flex min-h-6 items-center gap-1.5 rounded text-inherit hover:text-violet-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                      >
                        {column.header}
                        {ariaSort === 'ascending'
                          ? <ArrowUp size={13} aria-hidden="true" />
                          : ariaSort === 'descending'
                            ? <ArrowDown size={13} aria-hidden="true" />
                            : <ArrowUpDown size={13} aria-hidden="true" />}
                      </button>
                    )}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody className="divide-y divide-[var(--line)]">
          {sortedRows.map((row) => (
            <tr key={row.id} className="transition-colors hover:bg-[var(--surface-soft)]/70">
              {columns.map((column) => (
                <td key={column.key} className={`px-4 py-3.5 ${column.align === 'right' ? 'text-right' : ''}`}>
                  {column.render(row)}
                </td>
              ))}
            </tr>
          ))}
          {sortedRows.length === 0 && (
            <tr>
              <td colSpan={columns.length} className="px-4 py-12 text-center text-sm text-[var(--muted)]">
                {empty ?? 'No matching records.'}
              </td>
            </tr>
          )}
        </tbody>
      </table>
      <p className="sr-only" role="status">{announcement}</p>
    </div>
    </LocalizedContent>
  )
}
