import { describe, expect, it } from 'vitest'
import { readReceiptLabel } from './labels'

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
