import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useChatStore } from './chats'

vi.mock('../api/endpoints', () => ({
  chatApi: {
    markRead: vi.fn(async (id: number) => ({ message: { id, chat_id: 7, direction: 'inbound', type: 'text', body: 'ok', read_by: [{ user_id: 42, read_at: '2026-04-28T00:00:00.000Z' }], created_at: '2026-04-28T00:00:00.000Z' } })),
  },
}))

import { chatApi } from '../api/endpoints'

describe('chat store read receipts', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('marks only inbound messages unread by the current operator', async () => {
    const store = useChatStore()
    store.messages = [
      { id: 1, chat_id: 7, direction: 'inbound', type: 'text', body: 'new', read_by: [], created_at: '2026-04-28T00:00:00.000Z' },
      { id: 2, chat_id: 7, direction: 'inbound', type: 'text', body: 'other read', read_by: [{ user_id: 99, read_at: '2026-04-28T00:00:00.000Z' }], created_at: '2026-04-28T00:00:00.000Z' },
      { id: 3, chat_id: 7, direction: 'inbound', type: 'text', body: 'mine read', read_by: [{ user_id: 42, read_at: '2026-04-28T00:00:00.000Z' }], created_at: '2026-04-28T00:00:00.000Z' },
      { id: 4, chat_id: 7, direction: 'outbound', type: 'text', body: 'sent', read_by: [], created_at: '2026-04-28T00:00:00.000Z' },
    ]

    await store.markVisibleInboundAsRead(42)

    expect(chatApi.markRead).toHaveBeenCalledTimes(2)
    expect(chatApi.markRead).toHaveBeenNthCalledWith(1, 1)
    expect(chatApi.markRead).toHaveBeenNthCalledWith(2, 2)
  })
})
