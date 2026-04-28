<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useAdminStore } from '../stores/admin'
import { useAuthStore } from '../stores/auth'
import { useChatStore } from '../stores/chats'
import { assignmentLabel } from '../utils/labels'
import { formatDateTime } from '../utils/time'

const auth = useAuthStore()
const admin = useAdminStore()
const chats = useChatStore()
const selectedOperatorId = ref<number | null>(null)

const chat = computed(() => chats.selectedChat)
const operators = computed(() => admin.users.filter((user) => user.is_active && user.role === 'operator'))

onMounted(() => {
  if (auth.isAdmin && admin.users.length === 0) admin.load()
})

watch([chat, operators], () => {
  selectedOperatorId.value = chat.value?.assigned_operator?.id ?? operators.value[0]?.id ?? null
})

async function adminAssign() {
  if (!chat.value || !selectedOperatorId.value) return
  const updated = await admin.assignChat(chat.value.id, selectedOperatorId.value)
  chats.upsertChat(updated)
}

async function forceRelease() {
  if (!chat.value) return
  const updated = await admin.forceReleaseChat(chat.value.id)
  chats.upsertChat(updated)
}
</script>

<template>
  <aside class="context-panel" aria-label="Контекст клиента">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Контекст</p>
        <h2>Клиент</h2>
      </div>
    </div>

    <div v-if="!chat" class="empty-copy">Выберите диалог, чтобы увидеть карточку клиента.</div>
    <template v-else>
      <dl class="detail-list">
        <dt>Имя</dt>
        <dd>{{ chat.external_user.display_name }}</dd>
        <dt>External ID</dt>
        <dd>{{ chat.external_user.external_id || '—' }}</dd>
        <dt>Username</dt>
        <dd>{{ chat.external_user.username ? '@' + chat.external_user.username : '—' }}</dd>
        <dt>First / Last</dt>
        <dd>{{ [chat.external_user.first_name, chat.external_user.last_name].filter(Boolean).join(' ') || '—' }}</dd>
        <dt>Платформа</dt>
        <dd>{{ chat.channel.name || chat.channel.code }}</dd>
        <dt>Назначение</dt>
        <dd>{{ assignmentLabel(chat.assignment_state, chat.assigned_operator?.name) }}</dd>
        <dt>Последняя активность</dt>
        <dd>{{ formatDateTime(chat.assignment_last_activity_at || chat.last_message_at) }}</dd>
        <dt>Auto-release</dt>
        <dd>10 минут без heartbeat оператора</dd>
      </dl>

      <section v-if="auth.isAdmin" class="admin-chat-tools" aria-label="Админ-действия с чатом">
        <strong>Админ-действия</strong>
        <select v-model.number="selectedOperatorId" aria-label="Назначить оператору">
          <option v-for="operator in operators" :key="operator.id" :value="operator.id">{{ operator.name }}</option>
        </select>
        <button class="primary-button compact" type="button" :disabled="!selectedOperatorId" @click="adminAssign">Назначить</button>
        <button class="secondary-button compact" type="button" :disabled="!chat.assigned_operator" @click="forceRelease">Force release</button>
      </section>
    </template>

  </aside>
</template>
