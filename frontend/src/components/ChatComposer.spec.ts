import { describe, expect, it } from 'vitest'
import { createTestingPinia } from '@pinia/testing'
import { fireEvent, render, screen } from '@testing-library/vue'
import ChatComposer from './ChatComposer.vue'
import { useAuthStore } from '../stores/auth'
import { useChatStore } from '../stores/chats'

describe('ChatComposer', () => {
  it('enables sending only when the operator can write and text is present', async () => {
    render(ChatComposer, {
      global: {
        plugins: [createTestingPinia({ stubActions: false })],
      },
    })

    const auth = useAuthStore()
    auth.user = { id: 42, name: 'Operator', email: 'op@example.ru', role: 'operator' }
    const store = useChatStore()
    store.selectedChat = {
      id: 1,
      status: 'assigned',
      assignment_state: 'assigned',
      read_only: false,
      assigned_operator: { id: 42, name: 'Operator', email: 'op@example.ru' },
      external_user: { id: 1, display_name: 'Client' },
      channel: { id: 1, code: 'telegram', name: 'Telegram' },
    }

    const button = screen.getByRole('button', { name: /отправить/i })
    expect(button).toBeDisabled()

    await fireEvent.update(screen.getByPlaceholderText(/напишите ответ/i), 'Здравствуйте')
    expect(button).toBeEnabled()
  })
})
