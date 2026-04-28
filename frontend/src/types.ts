export type Role = 'admin' | 'operator'
export type ChatStatus = 'open' | 'assigned' | 'closed'
export type AssignmentState = 'unassigned' | 'open' | 'assigned' | 'closed'
export type MessageDirection = 'inbound' | 'outbound'
export type MessageType = 'text' | 'unsupported_message'
export type DeliveryStatus = 'pending' | 'queued' | 'sending' | 'retrying' | 'sent' | 'failed'
export type ChatFilter = 'all' | 'unassigned' | 'assigned_to_me' | 'assigned_to_others' | 'unread' | 'closed'

export interface ApiErrorShape {
  message: string
  code: string
  details: Record<string, unknown>
}

export interface User {
  id: number
  name: string
  email: string
  role: Role
  is_active?: boolean
}

export interface Channel {
  id: number
  code: string
  name?: string
}

export interface ExternalUser {
  id: number
  external_id?: string
  display_name: string
  username?: string | null
  first_name?: string | null
  last_name?: string | null
}

export interface Chat {
  id: number
  status: ChatStatus
  assignment_state: AssignmentState
  assigned_at?: string | null
  assignment_last_activity_at?: string | null
  last_message_at?: string | null
  last_inbound_message_at?: string | null
  unread_count?: number | null
  last_message_preview?: string | null
  assigned_operator?: Pick<User, 'id' | 'name' | 'email'> | null
  external_user: ExternalUser
  channel: Channel
  read_only?: boolean | null
  created_at?: string
  updated_at?: string
}

export interface MessageRead {
  user_id: number
  name?: string | null
  read_at: string
}

export interface DeliveryInfo {
  id: number
  status: DeliveryStatus
  attempt_count: number
  next_attempt_at?: string | null
  provider_message_id?: string | null
  provider_error_code?: string | null
  provider_error_message?: string | null
}

export interface Message {
  id: number
  chat_id: number
  operator_id?: number | null
  direction: MessageDirection
  type: MessageType
  body?: string | null
  delivery_status?: DeliveryStatus | null
  delivery?: DeliveryInfo | null
  read_by: MessageRead[]
  metadata?: Record<string, unknown>
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  next_cursor: number | null
}

export interface AuthResponse {
  token: string
  user: User
}

export interface AuditLog {
  id: number
  actor_user?: Pick<User, 'id' | 'name' | 'email'> | null
  event_type: string
  target_type?: string | null
  target_id?: number | null
  metadata?: Record<string, unknown>
  ip_address?: string | null
  request_id?: string | null
  created_at: string
}
