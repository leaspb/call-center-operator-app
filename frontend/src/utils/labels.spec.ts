import { describe, expect, it } from 'vitest'
import { auditEventDetails, auditEventFilter, auditEventLabel, readReceiptLabel } from './labels'

describe('readReceiptLabel', () => {
  it('shows compact names for all operators who read an inbound message', () => {
    expect(readReceiptLabel([
      { user_id: 1, name: 'Иван', read_at: '2026-04-28T00:00:00.000Z' },
      { user_id: 2, name: 'Анна', read_at: '2026-04-28T00:00:01.000Z' },
    ])).toBe('прочитано: Иван, Анна')
  })

  it('falls back to user id when backend has no display name', () => {
    expect(readReceiptLabel([{ user_id: 9, read_at: '2026-04-28T00:00:00.000Z' }])).toBe('прочитано: #9')
  })
})

describe('audit labels', () => {
  it('shows Russian event label instead of raw event type', () => {
    expect(auditEventLabel('chat.admin_assigned')).toBe('Админ назначил оператора')
  })

  it('builds readable audit details from target and metadata', () => {
    expect(auditEventDetails({
      id: 1,
      event_type: 'chat.admin_assigned',
      target_type: 'chat',
      target_id: 42,
      metadata: { operator_id: 7, provider: 'telegram' },
      created_at: '2026-04-29T00:00:00.000Z',
    })).toBe('Чат #42 · Оператор: #7 · Провайдер: telegram')
  })

  it('maps event types to audit filters', () => {
    expect(auditEventFilter('message.inbound_created')).toBe('messages')
    expect(auditEventFilter('delivery.failed')).toBe('delivery')
    expect(auditEventFilter('admin.user_created')).toBe('users')
  })
})
