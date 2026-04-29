import type { AuditLog, ChatFilter, DeliveryStatus, MessageRead } from '../types'

export const filterLabels: Record<ChatFilter, string> = {
  all: 'Все',
  unassigned: 'Свободные',
  assigned_to_me: 'Мои',
  assigned_to_others: 'У коллег',
  unread: 'Непрочитанные',
  closed: 'Закрытые',
}

export type AuditFilter = 'all' | 'chats' | 'messages' | 'delivery' | 'users' | 'auth'

export const auditFilterLabels: Record<AuditFilter, string> = {
  all: 'Все',
  chats: 'Чаты',
  messages: 'Сообщения',
  delivery: 'Доставка',
  users: 'Пользователи',
  auth: 'Входы',
}

const auditEventLabels: Record<string, string> = {
  'auth.registered_first_admin': 'Первый администратор создан',
  'auth.login': 'Вход в систему',
  'auth.logout': 'Выход из системы',
  'admin.user_created': 'Админ создал пользователя',
  'admin.user_role_changed': 'Админ изменил роль пользователя',
  'admin.user_password_reset': 'Админ сбросил пароль пользователя',
  'admin.user_status_changed': 'Админ изменил статус пользователя',
  'chat.created': 'Новый чат создан',
  'chat.reopened': 'Чат переоткрыт',
  'chat.assigned': 'Оператор взял чат в работу',
  'chat.admin_assigned': 'Админ назначил оператора',
  'chat.released': 'Оператор освободил чат',
  'chat.force_released': 'Админ освободил чат',
  'chat.auto_released': 'Чат освобождён автоматически',
  'chat.closed': 'Чат закрыт',
  'message.inbound_created': 'Входящее сообщение получено',
  'message.outbound_created': 'Оператор отправил сообщение',
  'delivery.manual_retry': 'Повтор отправки запущен вручную',
  'delivery.sent': 'Сообщение доставлено',
  'delivery.retrying': 'Временная ошибка отправки, будет повтор',
  'delivery.failed': 'Отправка не удалась',
}

const auditTargetLabels: Record<string, string> = {
  chat: 'Чат',
  message: 'Сообщение',
  message_delivery: 'Доставка',
  user: 'Пользователь',
}

const auditMetadataLabels: Record<string, string> = {
  operator_id: 'Оператор',
  previous_operator_id: 'Был оператор',
  admin_id: 'Администратор',
  chat_id: 'Чат',
  delivery_id: 'Доставка',
  message_id: 'Сообщение',
  provider: 'Провайдер',
  role: 'Роль',
  old_role: 'Была роль',
  new_role: 'Новая роль',
  old_is_active: 'Был активен',
  new_is_active: 'Активен',
  provider_error_code: 'Код ошибки',
  provider_error_message: 'Ошибка',
  attempt_count: 'Попыток',
  next_attempt_at: 'Следующая попытка',
}

const roleLabels: Record<string, string> = {
  admin: 'администратор',
  operator: 'оператор',
}

const idMetadataKeys = new Set([
  'operator_id',
  'previous_operator_id',
  'admin_id',
  'chat_id',
  'delivery_id',
  'message_id',
])

export function auditEventLabel(eventType: string): string {
  return auditEventLabels[eventType] ?? eventType
}

export function auditEventFilter(eventType: string): Exclude<AuditFilter, 'all'> | null {
  if (eventType.startsWith('chat.')) return 'chats'
  if (eventType.startsWith('message.')) return 'messages'
  if (eventType.startsWith('delivery.')) return 'delivery'
  if (eventType.startsWith('admin.user_')) return 'users'
  if (eventType.startsWith('auth.')) return 'auth'
  return null
}

export function auditEventDetails(entry: AuditLog): string {
  const details: string[] = []
  if (entry.target_type && entry.target_id) {
    details.push(`${auditTargetLabels[entry.target_type] ?? entry.target_type} #${entry.target_id}`)
  }

  Object.entries(entry.metadata ?? {}).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return
    const label = auditMetadataLabels[key] ?? key
    details.push(`${label}: ${formatAuditMetadataValue(key, value)}`)
  })

  return details.join(' · ') || '—'
}

function formatAuditMetadataValue(key: string, value: unknown): string {
  if (typeof value === 'boolean') return value ? 'да' : 'нет'
  if (typeof value === 'number' && idMetadataKeys.has(key)) return `#${value}`
  if (typeof value === 'string' && roleLabels[value]) return roleLabels[value]
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

export const deliveryLabels: Record<DeliveryStatus, string> = {
  pending: 'ожидает',
  queued: 'в очереди',
  sending: 'отправляется',
  retrying: 'повтор',
  sent: 'доставлено',
  failed: 'ошибка',
}

export function assignmentLabel(state: string, owner?: string | null): string {
  if (state === 'unassigned') return 'Свободен'
  if (state === 'assigned') return owner ? `Назначен: ${owner}` : 'Назначен'
  if (state === 'closed') return 'Закрыт'
  return 'Открыт'
}

export function unsupportedLabel(metadata?: Record<string, unknown>): string {
  if (!metadata) return 'Неподдерживаемый тип сообщения'
  const known = ['photo', 'document', 'voice', 'video', 'sticker'].find((key) => key in metadata)
  return `Неподдерживаемый тип сообщения${known ? `: ${known}` : ''}`
}

export function readReceiptLabel(reads: MessageRead[] = []): string {
  const names = Array.from(new Set(reads.map((read) => read.name || `#${read.user_id}`).filter(Boolean)))
  if (names.length === 0) return ''
  const visible = names.slice(0, 3).join(', ')
  const rest = names.length > 3 ? ` +${names.length - 3}` : ''
  return `прочитано: ${visible}${rest}`
}
