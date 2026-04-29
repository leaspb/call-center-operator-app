<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useChatStore } from '../stores/chats'
import { useRealtime } from '../composables/useRealtime'
import AdminPanel from '../components/AdminPanel.vue'
import ChatComposer from '../components/ChatComposer.vue'
import ChatList from '../components/ChatList.vue'
import ChatThread from '../components/ChatThread.vue'
import CustomerPanel from '../components/CustomerPanel.vue'
import HelpPanel from '../components/HelpPanel.vue'

const auth = useAuthStore()
const chats = useChatStore()
const realtime = useRealtime()

const operatorName = computed(() => auth.user?.name ?? 'Оператор')
const panelTitle = computed(() => auth.isAdmin ? 'Панель администратора' : 'Панель оператора')
const adminPanelMode = ref<'users' | 'audit' | null>(null)
const helpOpen = ref(false)

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
          <h1>{{ panelTitle }}</h1>
        </div>
      </div>
      <div class="topbar-actions">
        <button v-if="auth.isAdmin" class="secondary-button action-button" type="button" @click="adminPanelMode = 'users'">
          <span class="button-icon users-icon" aria-hidden="true"></span>
          <span>Пользователи</span>
        </button>
        <button v-if="auth.isAdmin" class="secondary-button action-button" type="button" @click="adminPanelMode = 'audit'">
          <span class="button-icon audit-icon" aria-hidden="true"></span>
          <span>Аудит</span>
        </button>
        <button class="secondary-button action-button" type="button" @click="helpOpen = true">
          <span class="button-icon help-icon" aria-hidden="true">?</span>
          <span>Справка</span>
        </button>
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

    <div v-if="auth.isAdmin && adminPanelMode" class="admin-overlay" role="dialog" aria-modal="true" :aria-label="adminPanelMode === 'users' ? 'Пользователи' : 'Аудит'" @click.self="adminPanelMode = null">
      <div class="admin-dialog">
        <div class="admin-dialog-actions">
          <button class="secondary-button compact" type="button" @click="adminPanelMode = null">Закрыть</button>
        </div>
        <AdminPanel :mode="adminPanelMode" />
      </div>
    </div>

    <div v-if="helpOpen" class="admin-overlay" role="dialog" aria-modal="true" aria-label="Справка" @click.self="helpOpen = false">
      <div class="admin-dialog help-dialog">
        <div class="admin-dialog-actions">
          <button class="secondary-button compact" type="button" @click="helpOpen = false">Закрыть</button>
        </div>
        <HelpPanel :role="auth.user?.role === 'admin' ? 'admin' : 'operator'" />
      </div>
    </div>
  </div>
</template>
