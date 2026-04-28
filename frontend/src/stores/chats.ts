import { defineStore } from 'pinia'
import { chatApi } from '../api/endpoints'
import { ApiClientError } from '../api/client'
import { useAuthStore } from './auth'
import type { Chat, ChatFilter, DeliveryInfo, Message } from '../types'

export const useChatStore = defineStore('chats', {
  state: () => ({
    chats: [] as Chat[],
    selectedChat: null as Chat | null,
    messages: [] as Message[],
    filter: 'all' as ChatFilter,
    nextChatCursor: null as number | null,
    nextMessageCursor: null as number | null,
    loadingChats: false,
    loadingMessages: false,
    sending: false,
    error: null as string | null,
    conflict: null as string | null,
    lastSyncAt: null as Date | null,
  }),
  getters: {
    selectedChatId: (state) => state.selectedChat?.id ?? null,
    canWrite: (state) => {
      const auth = useAuthStore()
      return Boolean(
        state.selectedChat
        && state.selectedChat.status === 'assigned'
        && !state.selectedChat.read_only
        && state.selectedChat.assigned_operator?.id === auth.user?.id,
      )
    },
  },
  actions: {
    setFilter(filter: ChatFilter) {
      this.filter = filter
      this.nextChatCursor = null
      return this.loadChats()
    },
    upsertChat(chat: Chat) {
      const index = this.chats.findIndex((item) => item.id === chat.id)
      if (index >= 0) this.chats.splice(index, 1, chat)
      else this.chats.unshift(chat)
      if (this.selectedChat?.id === chat.id) this.selectedChat = chat
    },
    upsertMessage(message: Message) {
      const index = this.messages.findIndex((item) => item.id === message.id)
      if (index >= 0) this.messages.splice(index, 1, message)
      else this.messages.push(message)
      this.messages.sort((a, b) => a.id - b.id)
    },
    async loadChats(cursor: number | null = null) {
      this.loadingChats = true
      this.error = null
      try {
        const response = await chatApi.list(this.filter, cursor)
        this.chats = cursor ? [...this.chats, ...response.data] : response.data
        this.nextChatCursor = response.next_cursor
        this.lastSyncAt = new Date()
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Не удалось загрузить чаты'
      } finally {
        this.loadingChats = false
      }
    },
    async openChat(chatId: number) {
      this.loadingMessages = true
      this.error = null
      try {
        const [{ chat }, messages] = await Promise.all([chatApi.show(chatId), chatApi.messages(chatId)])
        this.selectedChat = chat
        this.upsertChat(chat)
        this.messages = messages.data.slice().reverse()
        this.nextMessageCursor = messages.next_cursor
        await this.markVisibleInboundAsRead(useAuthStore().user?.id)
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Не удалось открыть чат'
      } finally {
        this.loadingMessages = false
      }
    },
    async refreshSelected() {
      if (!this.selectedChat) return
      await Promise.all([this.loadChats(), this.openChat(this.selectedChat.id)])
    },
    async loadOlderMessages() {
      if (!this.selectedChat || !this.nextMessageCursor) return
      const response = await chatApi.messages(this.selectedChat.id, this.nextMessageCursor)
      this.messages = [...response.data.reverse(), ...this.messages]
      this.nextMessageCursor = response.next_cursor
    },
    async assignSelected() {
      if (!this.selectedChat) return
      this.conflict = null
      try {
        const { chat } = await chatApi.assign(this.selectedChat.id)
        this.upsertChat(chat)
      } catch (error) {
        if (error instanceof ApiClientError && error.code === 'CHAT_ALREADY_ASSIGNED') {
          const owner = (error.details.assigned_operator as { name?: string } | undefined)?.name
          this.conflict = owner ? `Диалог уже взят оператором ${owner}` : 'Диалог уже взят другим оператором'
        } else {
          this.error = error instanceof Error ? error.message : 'Не удалось назначить чат'
        }
      }
    },
    async releaseSelected() {
      if (!this.selectedChat) return
      const { chat } = await chatApi.release(this.selectedChat.id)
      this.upsertChat(chat)
    },
    async closeSelected() {
      if (!this.selectedChat) return
      const { chat } = await chatApi.close(this.selectedChat.id)
      this.upsertChat(chat)
    },
    async heartbeat() {
      const auth = useAuthStore()
      if (!this.selectedChat || this.selectedChat.status !== 'assigned' || this.selectedChat.assigned_operator?.id !== auth.user?.id) return
      try {
        const { chat } = await chatApi.heartbeat(this.selectedChat.id)
        this.upsertChat(chat)
      } catch {
        // Polling refresh will surface ownership changes without interrupting typing.
      }
    },
    async send(body: string) {
      if (!this.canWrite || !this.selectedChat || !body.trim()) return
      this.sending = true
      try {
        const response = await chatApi.sendMessage(this.selectedChat.id, body.trim())
        this.upsertMessage({ ...response.message, delivery: response.delivery, delivery_status: response.delivery.status })
        await this.loadChats()
      } finally {
        this.sending = false
      }
    },
    async retryDelivery(delivery: DeliveryInfo) {
      const response = await chatApi.retryDelivery(delivery.id)
      const message = this.messages.find((item) => item.delivery?.id === delivery.id)
      if (message) {
        message.delivery = response.delivery
        message.delivery_status = response.delivery.status
      }
    },
    async markVisibleInboundAsRead(currentUserId?: number | null) {
      if (!currentUserId) return
      const unread = this.messages.filter((message) => message.direction === 'inbound' && !message.read_by?.some((read) => read.user_id === currentUserId))
      await Promise.allSettled(unread.map((message) => chatApi.markRead(message.id).then(({ message: updated }) => this.upsertMessage(updated))))
    },
  },
})
