<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
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
const drawerOpen = ref(false)
const mobileSection = ref<'dialogs' | 'chat' | 'context'>('dialogs')
const mobileViewClass = computed(() => `show-${mobileSection.value}`)

onMounted(async () => {
  await chats.loadChats()
  realtime.connect()
})

watch(() => chats.selectedChatId, (chatId) => {
  if (chatId) mobileSection.value = 'chat'
})

function openAdminPanel(mode: 'users' | 'audit') {
  adminPanelMode.value = mode
  drawerOpen.value = false
}

function openHelp() {
  helpOpen.value = true
  drawerOpen.value = false
}

async function logout() {
  drawerOpen.value = false
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
      <button class="mobile-menu-button" type="button" aria-label="Открыть меню" :aria-expanded="drawerOpen" @click="drawerOpen = true">
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
      </button>
      <div class="topbar-actions desktop-actions">
        <button v-if="auth.isAdmin" class="secondary-button action-button" type="button" @click="openAdminPanel('users')">
          <span class="button-icon users-icon" aria-hidden="true"></span>
          <span>Пользователи</span>
        </button>
        <button v-if="auth.isAdmin" class="secondary-button action-button" type="button" @click="openAdminPanel('audit')">
          <span class="button-icon audit-icon" aria-hidden="true"></span>
          <span>Аудит</span>
        </button>
        <button class="secondary-button action-button" type="button" @click="openHelp">
          <span class="button-icon help-icon" aria-hidden="true">?</span>
          <span>Справка</span>
        </button>
        <span class="user-pill">{{ operatorName }} · {{ auth.user?.role === 'admin' ? 'админ' : 'оператор' }}</span>
        <button class="secondary-button" type="button" @click="logout">Выйти</button>
      </div>
    </header>

    <main :class="['operator-grid', mobileViewClass]">
      <ChatList class="panel-dialogs" />
      <section class="dialog-pane panel-chat" aria-label="Диалог">
        <ChatThread />
        <ChatComposer />
      </section>
      <CustomerPanel class="panel-context" />
    </main>

    <nav class="mobile-tabbar" aria-label="Разделы приложения">
      <button type="button" :class="['mobile-tab', { active: mobileSection === 'dialogs' }]" @click="mobileSection = 'dialogs'">
        <span class="mobile-tab-icon dialogs-tab-icon" aria-hidden="true"></span>
        <span>Диалоги</span>
      </button>
      <button type="button" :class="['mobile-tab', { active: mobileSection === 'chat' }]" @click="mobileSection = 'chat'">
        <span class="mobile-tab-icon chat-tab-icon" aria-hidden="true"></span>
        <span>Чат</span>
      </button>
      <button type="button" :class="['mobile-tab', { active: mobileSection === 'context' }]" @click="mobileSection = 'context'">
        <span class="mobile-tab-icon context-tab-icon" aria-hidden="true"></span>
        <span>Клиент</span>
      </button>
    </nav>

    <div v-if="drawerOpen" class="mobile-drawer-backdrop" role="presentation" @click.self="drawerOpen = false">
      <nav class="mobile-drawer" aria-label="Меню приложения">
        <div class="mobile-drawer-header">
          <div>
            <p class="eyebrow">{{ auth.user?.role === 'admin' ? 'Администратор' : 'Оператор' }}</p>
            <strong>{{ operatorName }}</strong>
          </div>
          <button class="icon-button" type="button" aria-label="Закрыть меню" @click="drawerOpen = false">×</button>
        </div>
        <button v-if="auth.isAdmin" class="drawer-action" type="button" @click="openAdminPanel('users')">
          <span class="button-icon users-icon" aria-hidden="true"></span>
          <span>Пользователи</span>
        </button>
        <button v-if="auth.isAdmin" class="drawer-action" type="button" @click="openAdminPanel('audit')">
          <span class="button-icon audit-icon" aria-hidden="true"></span>
          <span>Аудит</span>
        </button>
        <button class="drawer-action" type="button" @click="openHelp">
          <span class="button-icon help-icon" aria-hidden="true">?</span>
          <span>Справка</span>
        </button>
        <button class="drawer-action danger" type="button" @click="logout">Выйти</button>
      </nav>
    </div>

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
