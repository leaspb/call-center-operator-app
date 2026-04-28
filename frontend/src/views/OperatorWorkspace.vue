<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useChatStore } from '../stores/chats'
import { useRealtime } from '../composables/useRealtime'
import { API_BASE_URL } from '../api/client'
import AdminPanel from '../components/AdminPanel.vue'
import ChatComposer from '../components/ChatComposer.vue'
import ChatList from '../components/ChatList.vue'
import ChatThread from '../components/ChatThread.vue'
import CustomerPanel from '../components/CustomerPanel.vue'

const auth = useAuthStore()
const chats = useChatStore()
const realtime = useRealtime()

const operatorName = computed(() => auth.user?.name ?? 'Оператор')

onMounted(async () => {
  await chats.loadChats()
  realtime.connect()
})

async function logout() {
  realtime.disconnect()
  await auth.logout()
  location.assign('/login')
}
</script>

<template>
  <div class="workspace-shell">
    <header class="topbar">
      <div class="brand-lockup">
        <span class="brand-mark small" aria-hidden="true">ОП</span>
        <div>
          <p class="eyebrow">Call Center Operator</p>
          <h1>Операторская панель</h1>
        </div>
      </div>
      <div class="topbar-actions">
        <a v-if="auth.isAdmin" class="ghost-link" :href="`${API_BASE_URL}/openapi.json`" target="_blank" rel="noreferrer">Swagger/OpenAPI</a>
        <span class="user-pill">{{ operatorName }} · {{ auth.user?.role === 'admin' ? 'админ' : 'оператор' }}</span>
        <button class="secondary-button" type="button" @click="logout">Выйти</button>
      </div>
    </header>

    <main class="operator-grid">
      <ChatList />
      <section class="dialog-pane" aria-label="Диалог">
        <ChatThread />
        <ChatComposer />
      </section>
      <CustomerPanel />
    </main>

    <AdminPanel v-if="auth.isAdmin" />
  </div>
</template>
