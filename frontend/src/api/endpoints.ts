import { apiRequest } from './client'
import type { AuditLog, AuthResponse, Chat, ChatFilter, DeliveryInfo, Message, Paginated, User } from '../types'

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload extends LoginPayload {
  name: string
  password_confirmation: string
}

export const authApi = {
  register: (payload: RegisterPayload) => apiRequest<AuthResponse>('/auth/register', { method: 'POST', body: JSON.stringify(payload) }),
  login: (payload: LoginPayload) => apiRequest<AuthResponse>('/auth/login', { method: 'POST', body: JSON.stringify(payload) }),
  logout: () => apiRequest<{ message: string }>('/auth/logout', { method: 'POST', body: '{}' }),
  me: () => apiRequest<{ user: User }>('/me'),
}

export const adminApi = {
  users: () => apiRequest<{ data: User[] }>('/admin/users'),
  createUser: (payload: { name: string; email: string; password: string; role: 'admin' | 'operator' }) => apiRequest<{ user: User }>('/admin/users', { method: 'POST', body: JSON.stringify(payload) }),
  changeRole: (userId: number, role: 'admin' | 'operator') => apiRequest<{ user: User }>(`/admin/users/${userId}/role`, { method: 'PATCH', body: JSON.stringify({ role }) }),
  resetPassword: (userId: number, password: string) => apiRequest<{ user: User }>(`/admin/users/${userId}/reset-password`, { method: 'POST', body: JSON.stringify({ password }) }),
  assignChat: (chatId: number, operatorId: number) => apiRequest<{ chat: Chat }>(`/admin/chats/${chatId}/assign`, { method: 'POST', body: JSON.stringify({ operator_id: operatorId }) }),
  forceReleaseChat: (chatId: number) => apiRequest<{ chat: Chat }>(`/admin/chats/${chatId}/force-release`, { method: 'POST', body: '{}' }),
  auditLog: () => apiRequest<{ data: AuditLog[] }>('/audit-log'),
}

export const chatApi = {
  list: (filter: ChatFilter, cursor?: number | null) => {
    const params = new URLSearchParams({ filter, limit: '50' })
    if (cursor) params.set('cursor', String(cursor))
    return apiRequest<Paginated<Chat>>(`/chats?${params.toString()}`)
  },
  show: (chatId: number) => apiRequest<{ chat: Chat }>(`/chats/${chatId}`),
  messages: (chatId: number, beforeId?: number | null) => {
    const params = new URLSearchParams({ limit: '50' })
    if (beforeId) params.set('before_id', String(beforeId))
    return apiRequest<Paginated<Message>>(`/chats/${chatId}/messages?${params.toString()}`)
  },
  sendMessage: (chatId: number, body: string) => apiRequest<{ message: Message; delivery: DeliveryInfo }>(`/chats/${chatId}/messages`, { method: 'POST', body: JSON.stringify({ body }) }),
  assign: (chatId: number) => apiRequest<{ chat: Chat }>(`/chats/${chatId}/assign`, { method: 'POST', body: '{}' }),
  release: (chatId: number) => apiRequest<{ chat: Chat }>(`/chats/${chatId}/release`, { method: 'POST', body: '{}' }),
  close: (chatId: number) => apiRequest<{ chat: Chat }>(`/chats/${chatId}/close`, { method: 'POST', body: '{}' }),
  heartbeat: (chatId: number) => apiRequest<{ chat: Chat }>(`/chats/${chatId}/heartbeat`, { method: 'POST', body: '{}' }),
  markRead: (messageId: number) => apiRequest<{ message: Message }>(`/messages/${messageId}/read`, { method: 'POST', body: '{}' }),
  retryDelivery: (deliveryId: number) => apiRequest<{ delivery: DeliveryInfo }>(`/deliveries/${deliveryId}/retry`, { method: 'POST', body: '{}' }),
}
