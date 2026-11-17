import { ExternalLink, Eye, LifeBuoy, Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Badge } from '../components/common/Badge'
import { Button } from '../components/common/Button'
import { DataTable, type TableColumn } from '../components/common/DataTable'
import { LocalizedContent } from '../components/common/LocalizedContent'
import { Modal } from '../components/common/Modal'
import { PageHeading, Panel, PanelHeading } from '../components/common/Panel'
import { Pagination } from '../components/common/Pagination'
import { useAppStore } from '../store/appStore'
import type { SupportRequest, SupportRequestStatus } from '../types'

const statusLabels: Record<SupportRequestStatus, string> = {
  pending: 'Pending',
  in_progress: 'In progress',
  resolved: 'Resolved',
}

function localizedStatusLabel(status: SupportRequestStatus, language: 'en' | 'vi'): string {
  if (language === 'en') return statusLabels[status]
  return {
    pending: 'Chờ xử lý',
    in_progress: 'Đang xử lý',
    resolved: 'Đã giải quyết',
  }[status]
}

const statusTones: Record<SupportRequestStatus, 'amber' | 'blue' | 'green'> = {
  pending: 'amber',
  in_progress: 'blue',
  resolved: 'green',
}

const statuses: SupportRequestStatus[] = ['pending', 'in_progress', 'resolved']
const pageSize = 10

function isSupportRequestStatus(value: string): value is SupportRequestStatus {
  return value === 'pending' || value === 'in_progress' || value === 'resolved'
}

function attachmentUrl(imagePath: string | null): string | null {
  if (!imagePath || !/^uploads\/support\/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:jpe?g|png|gif|webp)$/i.test(imagePath)) return null
  return `/${imagePath.split('/').map(encodeURIComponent).join('/')}`
}

function formatSubmittedDate(value: string, language: 'en' | 'vi'): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(language === 'vi' ? 'vi-VN' : 'en-US', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

export function SupportRequestsPage() {
  const canManageUsers = useAppStore((state) => state.canManageUsers)
  const requests = useAppStore((state) => state.supportRequests)
  const isLoading = useAppStore((state) => state.isSupportRequestsLoading)
  const isMutating = useAppStore((state) => state.isMutating)
  const error = useAppStore((state) => state.supportRequestsError)
  const loadSupportRequests = useAppStore((state) => state.loadSupportRequests)
  const updateSupportRequestStatus = useAppStore((state) => state.updateSupportRequestStatus)
  const addToast = useAppStore((state) => state.addToast)
  const language = useAppStore((state) => state.language)
  const [query, setQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | SupportRequestStatus>('all')
  const [page, setPage] = useState(1)
  const [selectedRequestId, setSelectedRequestId] = useState<string | null>(null)

  useEffect(() => {
    if (canManageUsers) void loadSupportRequests().catch(() => undefined)
  }, [canManageUsers, loadSupportRequests])

  const filteredRequests = useMemo(() => requests.filter((request) => (
    `${request.title} ${request.message} ${request.senderName} ${request.senderEmail}`
      .toLowerCase()
      .includes(query.trim().toLowerCase())
    && (statusFilter === 'all' || request.status === statusFilter)
  )), [query, requests, statusFilter])

  const selectedRequest = requests.find((request) => request.id === selectedRequestId) ?? null
  const pendingCount = requests.filter((request) => request.status === 'pending').length
  const inProgressCount = requests.filter((request) => request.status === 'in_progress').length
  const resolvedCount = requests.filter((request) => request.status === 'resolved').length

  const handleStatusChange = async (request: SupportRequest, status: SupportRequestStatus) => {
    try {
      await updateSupportRequestStatus(request.id, status)
      setPage(1)
      addToast({
        title: 'Support request status updated',
        message: `${request.title} · ${localizedStatusLabel(status, language)}`,
        tone: 'success',
      })
    } catch (updateError) {
      addToast({
        title: 'Could not update support request',
        message: updateError instanceof Error ? updateError.message : undefined,
        tone: 'error',
      })
    }
  }

  const columns: TableColumn<SupportRequest>[] = [
    {
      key: 'title',
      header: 'Request',
      sortValue: (request) => request.title,
      width: 'min-w-[220px]',
      render: (request) => (
        <div className="max-w-[280px]">
          <p className="truncate text-xs font-semibold text-[var(--ink)]">{request.title}</p>
          <Button
            size="sm"
            variant="ghost"
            icon={<Eye size={14} aria-hidden="true" />}
            aria-label={`View details for ${request.title}`}
            onClick={() => setSelectedRequestId(request.id)}
            className="mt-1 min-h-8 px-1.5"
          >
            View details
          </Button>
        </div>
      ),
    },
    {
      key: 'content',
      header: 'Content',
      sortValue: (request) => request.message,
      width: 'min-w-[240px]',
      render: (request) => (
        <p className="max-w-[360px] whitespace-pre-wrap break-words text-xs leading-5 text-[var(--muted)] line-clamp-2">
          {request.message}
        </p>
      ),
    },
    {
      key: 'sender',
      header: 'Sender',
      sortValue: (request) => request.senderName,
      width: 'min-w-[160px]',
      render: (request) => (
        <div>
          <p className="text-xs font-semibold text-[var(--ink)]">{request.senderName}</p>
          <p className="mt-0.5 text-[11px] text-[var(--muted)]">{request.senderEmail}</p>
        </div>
      ),
    },
    {
      key: 'submitted',
      header: 'Date submitted',
      sortValue: (request) => new Date(request.createdAt).getTime(),
      width: 'min-w-[160px]',
      render: (request) => (
        <time dateTime={request.createdAt} className="whitespace-nowrap text-xs text-[var(--muted)]">
          {formatSubmittedDate(request.createdAt, language)}
        </time>
      ),
    },
    {
      key: 'attachment',
      header: 'Attachment',
      sortValue: (request) => request.imagePath ?? '',
      render: (request) => {
        const href = attachmentUrl(request.imagePath)
        return href ? (
          <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex min-h-8 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-violet-700 hover:bg-violet-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
            aria-label={`View attachment for ${request.title}`}
          >
            <ExternalLink size={14} aria-hidden="true" />
            View attachment
          </a>
        ) : (
          <span className="text-xs text-[var(--muted)]">
            {request.imagePath ? 'Attachment unavailable' : 'No attachment'}
          </span>
        )
      },
    },
    {
      key: 'status',
      header: 'Status',
      sortValue: (request) => localizedStatusLabel(request.status, language),
      width: 'min-w-[170px]',
      render: (request) => (
        <div className="space-y-1.5">
          <Badge tone={statusTones[request.status]} dot>{localizedStatusLabel(request.status, language)}</Badge>
          <label className="sr-only" htmlFor={`support-status-${request.id}`}>
            {`Update status for ${request.title}`}
          </label>
          <select
            id={`support-status-${request.id}`}
            value={request.status}
            disabled={isMutating}
            onChange={(event) => {
              const nextStatus = event.target.value
              if (isSupportRequestStatus(nextStatus)) void handleStatusChange(request, nextStatus)
            }}
            className="min-h-9 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2 text-xs text-[var(--ink)] disabled:opacity-60"
          >
            {statuses.map((status) => (
              <option key={status} value={status}>{localizedStatusLabel(status, language)}</option>
            ))}
          </select>
        </div>
      ),
    },
  ]

  if (!canManageUsers) {
    return (
      <LocalizedContent>
        <Panel className="p-6">
          <h1 className="text-lg font-bold text-[var(--ink)]">Administrator access required</h1>
          <p className="mt-2 text-sm text-[var(--muted)]">Only system administrators can manage support requests.</p>
        </Panel>
      </LocalizedContent>
    )
  }

  return (
    <LocalizedContent>
      <div className="space-y-5">
        <PageHeading
          eyebrow="Support"
          title="Support requests"
          description="Review user-reported issues, inspect attachments, and track each request through resolution."
          action={<Badge tone="violet" dot><LifeBuoy size={13} aria-hidden="true" /> Admin only</Badge>}
        />

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Panel className="p-4">
            <p className="text-[11px] text-[var(--muted)]">Total requests</p>
            <p className="mt-1 text-xl font-bold text-[var(--ink)]">{requests.length}</p>
          </Panel>
          <Panel className="p-4">
            <p className="text-[11px] text-[var(--muted)]">Pending</p>
            <p className="mt-1 text-xl font-bold text-amber-700">{pendingCount}</p>
          </Panel>
          <Panel className="p-4">
            <p className="text-[11px] text-[var(--muted)]">In progress</p>
            <p className="mt-1 text-xl font-bold text-blue-700">{inProgressCount}</p>
          </Panel>
          <Panel className="p-4">
            <p className="text-[11px] text-[var(--muted)]">Resolved</p>
            <p className="mt-1 text-xl font-bold text-emerald-700">{resolvedCount}</p>
          </Panel>
        </div>

        <Panel className="p-4 sm:p-5">
          <PanelHeading
            title="Support queue"
            subtitle="Submissions are stored in the support database and visible only to system administrators."
            action={<Badge tone="violet">{filteredRequests.length} requests</Badge>}
          />
          <div className="my-4 grid gap-2 sm:grid-cols-[minmax(220px,1fr)_220px]">
            <label className="relative">
              <span className="sr-only">Search support requests</span>
              <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
              <input
                value={query}
                onChange={(event) => { setQuery(event.target.value); setPage(1) }}
                placeholder="Search title, content, or sender..."
                className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] pl-9 pr-3 text-xs outline-none focus:border-violet-400"
              />
            </label>
            <label>
              <span className="sr-only">Filter support requests by status</span>
              <select
                value={statusFilter}
                onChange={(event) => {
                  const value = event.target.value
                  setStatusFilter(value === 'all' || isSupportRequestStatus(value) ? value : 'all')
                  setPage(1)
                }}
                className="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-xs text-[var(--ink)]"
              >
                <option value="all">All statuses</option>
                {statuses.map((status) => (
                  <option key={status} value={status}>{localizedStatusLabel(status, language)}</option>
                ))}
              </select>
            </label>
          </div>

          {error && (
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5">
              <p role="alert" className="text-xs font-medium text-rose-800">{error}</p>
              <Button
                size="sm"
                onClick={() => void loadSupportRequests().catch(() => undefined)}
                disabled={isLoading}
              >
                Retry
              </Button>
            </div>
          )}

          {isLoading && requests.length === 0 ? (
            <div className="grid min-h-40 place-items-center text-sm text-[var(--muted)]" role="status">
              Loading support requests…
            </div>
          ) : (
            <>
              <DataTable
                rows={filteredRequests.slice((page - 1) * pageSize, page * pageSize)}
                columns={columns}
                caption="Support requests"
                empty="No support requests match these filters."
              />
              <div className="mt-4">
                <Pagination page={page} pageSize={pageSize} total={filteredRequests.length} onChange={setPage} />
              </div>
            </>
          )}
        </Panel>

        <Modal
          open={selectedRequest !== null}
          onClose={() => setSelectedRequestId(null)}
          title={selectedRequest?.title ?? 'Support request details'}
          description={selectedRequest
            ? `${selectedRequest.senderName} · ${formatSubmittedDate(selectedRequest.createdAt, language)}`
            : undefined}
          size="lg"
        >
          {selectedRequest && (
            <div className="space-y-5">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--muted)]">Sender</p>
                  <p className="mt-1 text-sm font-semibold text-[var(--ink)]">{selectedRequest.senderName}</p>
                  <p className="text-xs text-[var(--muted)]">{selectedRequest.senderEmail}</p>
                </div>
                <Badge tone={statusTones[selectedRequest.status]} dot>{localizedStatusLabel(selectedRequest.status, language)}</Badge>
              </div>
              <section aria-labelledby="support-request-content-heading">
                <h3 id="support-request-content-heading" className="text-sm font-bold text-[var(--ink)]">Request content</h3>
                <p className="mt-2 whitespace-pre-wrap break-words rounded-xl bg-[var(--surface-soft)] p-4 text-sm leading-6 text-[var(--ink)]">
                  {selectedRequest.message}
                </p>
              </section>
              {selectedRequest.imagePath && (
                <section aria-labelledby="support-request-attachment-heading">
                  <h3 id="support-request-attachment-heading" className="text-sm font-bold text-[var(--ink)]">Attachment</h3>
                  {attachmentUrl(selectedRequest.imagePath) ? (
                    <a
                      href={attachmentUrl(selectedRequest.imagePath) ?? undefined}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-2 inline-flex max-w-full rounded-xl border border-[var(--line)] p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                    >
                      <img
                        src={attachmentUrl(selectedRequest.imagePath) ?? undefined}
                        alt="Support request attachment"
                        loading="lazy"
                        className="max-h-80 max-w-full rounded-lg object-contain"
                      />
                    </a>
                  ) : (
                    <p className="mt-2 text-sm text-[var(--muted)]">Attachment unavailable</p>
                  )}
                </section>
              )}
            </div>
          )}
        </Modal>
      </div>
    </LocalizedContent>
  )
}
