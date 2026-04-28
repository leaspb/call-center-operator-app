import type { ApiErrorShape } from '../types'

export class ApiClientError extends Error {
  status: number
  code: string
  details: Record<string, unknown>

  constructor(status: number, payload: ApiErrorShape) {
    super(payload.message)
    this.name = 'ApiClientError'
    this.status = status
    this.code = payload.code
    this.details = payload.details ?? {}
  }
}

export const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL ?? '/api/v1').replace(/\/$/, '')

let tokenProvider: () => string | null = () => localStorage.getItem('operator_token')

export function setTokenProvider(provider: () => string | null) {
  tokenProvider = provider
}

export async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = tokenProvider()
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')

  if (!(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers,
  })

  const text = await response.text()
  const payload = text ? JSON.parse(text) : null

  if (!response.ok) {
    throw new ApiClientError(response.status, {
      message: payload?.message ?? 'Ошибка запроса',
      code: payload?.code ?? 'HTTP_ERROR',
      details: payload?.details ?? {},
    })
  }

  return payload as T
}
