<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useAdminStore } from '../stores/admin'
import type { Role, User } from '../types'
import { auditEventDetails, auditEventFilter, auditEventLabel, auditFilterLabels, type AuditFilter } from '../utils/labels'
import { formatDateTime } from '../utils/time'

const props = defineProps<{ mode: 'users' | 'audit' }>()
const admin = useAdminStore()
const form = reactive({ name: '', email: '', password: '', role: 'operator' as Role })
const resetPasswords = reactive<Record<number, string>>({})
const auditFilters: AuditFilter[] = ['all', 'chats', 'messages', 'delivery', 'users', 'auth']
const selectedAuditFilter = ref<AuditFilter>('all')
const adminNotice = ref('')
let adminNoticeTimer: ReturnType<typeof setTimeout> | null = null
const filteredAudit = computed(() => {
  if (selectedAuditFilter.value === 'all') return admin.audit
  return admin.audit.filter((entry) => auditEventFilter(entry.event_type) === selectedAuditFilter.value)
})

onMounted(() => admin.load())

onUnmounted(() => {
  if (adminNoticeTimer) clearTimeout(adminNoticeTimer)
})

async function createUser() {
  await admin.createUser({ ...form })
  form.name = ''
  form.email = ''
  form.password = ''
  form.role = 'operator'
}

function showAdminNotice(message: string) {
  adminNotice.value = message
  if (adminNoticeTimer) clearTimeout(adminNoticeTimer)
  adminNoticeTimer = setTimeout(() => {
    adminNotice.value = ''
    adminNoticeTimer = null
  }, 3000)
}

async function resetPassword(userId: number, user: User) {
  await admin.resetPassword(user, resetPasswords[userId] || '')
  if (!admin.error) {
    resetPasswords[userId] = ''
    showAdminNotice('Пароль сменён')
  }
}

async function changeStatus(user: User, isActive: boolean) {
  try {
    await admin.changeStatus(user, isActive)
    admin.error = null
    showAdminNotice(isActive ? 'Пользователь включён' : 'Пользователь отключён')
  } catch (error) {
    admin.error = error instanceof Error ? error.message : 'Не удалось изменить статус пользователя'
  }
}
</script>

<template>
  <section class="admin-panel" aria-labelledby="admin-title">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">{{ props.mode === 'users' ? 'Пользователи' : 'Аудит' }}</p>
        <h2 id="admin-title">{{ props.mode === 'users' ? 'Пользователи' : 'Аудит событий' }}</h2>
      </div>
      <button class="icon-button" type="button" @click="admin.load()">↻</button>
    </div>

    <template v-if="props.mode === 'users'">
      <section class="admin-create-section" aria-label="Новый пользователь">
        <h3>Новый пользователь</h3>
        <form class="admin-create" @submit.prevent="createUser">
          <input v-model="form.name" required placeholder="Имя" />
          <input v-model="form.email" required type="email" placeholder="Email" />
          <input v-model="form.password" required type="password" minlength="6" placeholder="Пароль: минимум 6 символов" />
          <select v-model="form.role" aria-label="Роль">
            <option value="operator">Оператор</option>
            <option value="admin">Администратор</option>
          </select>
          <button class="primary-button compact" type="submit">Создать</button>
        </form>
      </section>

      <div class="table-shell">
        <p v-if="admin.error" class="form-error" role="alert">{{ admin.error }}</p>
        <p v-if="adminNotice" class="success-message" role="status">{{ adminNotice }}</p>
        <div v-for="user in admin.users" :key="user.id" class="admin-row">
          <div>
            <strong>{{ user.name }}</strong>
            <small>{{ user.email }} · {{ user.is_active ? 'активен' : 'отключён' }}</small>
          </div>
          <select :value="user.role" aria-label="Роль пользователя" @change="admin.changeRole(user, ($event.target as HTMLSelectElement).value as Role)">
            <option value="operator">Оператор</option>
            <option value="admin">Администратор</option>
          </select>
          <input v-model="resetPasswords[user.id]" type="password" minlength="6" placeholder="Новый пароль" />
          <button class="secondary-button compact" type="button" @click="resetPassword(user.id, user)">Установить пароль</button>
          <button class="secondary-button compact" type="button" @click="changeStatus(user, !user.is_active)">
            {{ user.is_active ? 'Отключить' : 'Включить' }}
          </button>
        </div>
      </div>
    </template>

    <template v-else>
      <div class="filter-strip audit-filter-strip" role="tablist" aria-label="Фильтр событий аудита">
        <button
          v-for="filter in auditFilters"
          :key="filter"
          type="button"
          :class="['filter-chip', { active: selectedAuditFilter === filter }]"
          @click="selectedAuditFilter = filter"
        >
          {{ auditFilterLabels[filter] }}
        </button>
      </div>

      <div class="table-shell">
        <div v-for="entry in filteredAudit" :key="entry.id" class="audit-row">
          <div>
            <strong>{{ auditEventLabel(entry.event_type) }}</strong>
            <small>{{ entry.actor_user?.name || 'system' }} · {{ formatDateTime(entry.created_at) }}</small>
          </div>
          <span class="audit-details">{{ auditEventDetails(entry) }}</span>
        </div>
        <p v-if="admin.audit.length === 0" class="empty-copy">Событий пока нет.</p>
        <p v-else-if="filteredAudit.length === 0" class="empty-copy">Событий выбранного типа нет.</p>
      </div>
    </template>
  </section>
</template>
