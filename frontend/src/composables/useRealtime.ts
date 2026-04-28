import { onScopeDispose, watch } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useChatStore } from '../stores/chats'
import { useAuthStore } from '../stores/auth'

declare global {
  interface Window {
    Pusher?: typeof Pusher
  }
}

export function useRealtime() {
  const chats = useChatStore()
  const auth = useAuthStore()
  let echo: Echo<'reverb'> | null = null
  let pollTimer: number | null = null
  let heartbeatTimer: number | null = null

  const startPolling = () => {
    stopPolling()
    pollTimer = window.setInterval(() => {
      if (chats.selectedChatId) chats.refreshSelected()
      else chats.loadChats()
    }, 12_000)
  }

  const stopPolling = () => {
    if (pollTimer) window.clearInterval(pollTimer)
    pollTimer = null
  }

  const startHeartbeat = () => {
    if (heartbeatTimer) window.clearInterval(heartbeatTimer)
    heartbeatTimer = window.setInterval(() => {
      chats.refreshAssignmentHeartbeat()
    }, 60_000)
  }

  const connect = () => {
    window.Pusher = Pusher
    const key = import.meta.env.VITE_REVERB_APP_KEY
    const userId = auth.user?.id
    if (!key || !auth.token || !userId) {
      startPolling()
      startHeartbeat()
      return
    }

    try {
      const instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: `${import.meta.env.VITE_API_BASE_URL ?? '/api/v1'}/broadcasting/auth`,
        auth: { headers: { Authorization: `Bearer ${auth.token}` } },
      })
      echo = instance
      const refreshActiveChat = () => {
        if (chats.selectedChatId) chats.openChat(chats.selectedChatId)
        else chats.loadChats()
      }
      const refreshListAndActiveChat = () => {
        if (chats.selectedChatId) chats.refreshSelected()
        else chats.loadChats()
      }

      instance.private(`operator.${userId}`)
        .listen('.chat.created', () => chats.loadChats())
        .listen('.chat.assigned', refreshListAndActiveChat)
        .listen('.chat.released', refreshListAndActiveChat)
        .listen('.chat.closed', refreshListAndActiveChat)
        .listen('.chat.reopened', refreshListAndActiveChat)
        .listen('.chat.assignment_conflict', () => chats.loadChats())
        .listen('.message.created', refreshActiveChat)
        .listen('.message.delivery_status_changed', refreshActiveChat)
        .listen('.message.read', refreshActiveChat)
    } catch {
      // Echo failed; polling fallback runs unconditionally below
    }

    startPolling()
    startHeartbeat()
  }

  const disconnect = () => {
    echo?.disconnect()
    echo = null
    stopPolling()
    if (heartbeatTimer) window.clearInterval(heartbeatTimer)
    heartbeatTimer = null
  }

  watch(() => chats.selectedChatId, () => {
    chats.refreshAssignmentHeartbeat()
  })

  onScopeDispose(disconnect)

  return { connect, disconnect }
}
