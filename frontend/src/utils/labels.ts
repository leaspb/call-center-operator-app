import type { ChatFilter, DeliveryStatus, MessageRead } from '../types'

export const filterLabels: Record<ChatFilter, string> = {
  all: 'Все',
  unassigned: 'Свободные',
  assigned_to_me: 'Мои',
  assigned_to_others: 'У других',
  unread: 'Непрочитанные',
  closed: 'Закрытые',
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
