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
      chats.heartbeat()
    }, 60_000)
  }

  const connect = () => {
    window.Pusher = Pusher
    const key = import.meta.env.VITE_REVERB_APP_KEY
    if (!key || !auth.token) {
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
      instance.private('operator.chats')
        .listen('.chat.created', () => chats.loadChats())
        .listen('.chat.assigned', () => chats.refreshSelected())
        .listen('.chat.released', () => chats.refreshSelected())
        .listen('.chat.assignment_conflict', () => chats.loadChats())
        .listen('.message.created', () => chats.refreshSelected())
        .listen('.message.delivery_status_changed', () => chats.refreshSelected())
        .listen('.message.read', () => chats.refreshSelected())
    } catch {
      startPolling()
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
    chats.heartbeat()
  })

  onScopeDispose(disconnect)

  return { connect, disconnect }
}
