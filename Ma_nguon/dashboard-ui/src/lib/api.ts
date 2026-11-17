import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '',
  timeout: 15_000,
  headers: { Accept: 'application/json' },
  withCredentials: true,
})

export function isApiConfigured(): boolean {
  return Boolean(import.meta.env.VITE_API_BASE_URL)
}

export function getApiErrorMessage(error: unknown): string {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    if (error.response?.data?.message) return error.response.data.message
    if (error.response?.status === 401) return 'Your session expired. Sign in again and reload the dashboard.'
    if (error.response?.status === 403) return 'You do not have permission to perform this action.'
    if (error.code === 'ECONNABORTED') return 'The server took too long to respond. Try again.'
    if (!error.response) return 'The PHP server could not be reached. Check the connection and try again.'
  }
  return error instanceof Error ? error.message : 'The request could not be completed.'
}
