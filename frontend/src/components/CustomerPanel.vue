<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useAdminStore } from '../stores/admin'
import { useAuthStore } from '../stores/auth'
import { useChatStore } from '../stores/chats'
import { formatDateTimeWithYear } from '../utils/time'

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
        <p class="eyebrow">Контекст диалога</p>
        <h2>Клиент</h2>
      </div>
    </div>

    <div v-if="!chat" class="empty-copy">Выберите диалог, чтобы увидеть карточку клиента.</div>
    <template v-else>
      <dl class="detail-list">
        <dt>ФИО</dt>
        <dd>{{ chat.external_user.display_name }}</dd>
        <dt>Ник</dt>
        <dd>{{ chat.external_user.username ? '@' + chat.external_user.username : '—' }}</dd>
        <dt>ID</dt>
        <dd>{{ chat.external_user.external_id || '—' }}</dd>
        <dt>Платформа</dt>
        <dd>{{ chat.channel.name || chat.channel.code }}</dd>
        <dt>Оператор</dt>
        <dd>{{ chat.assigned_operator?.name || 'Свободен' }}</dd>
        <dt>Последняя активность</dt>
        <dd>{{ formatDateTimeWithYear(chat.assignment_last_activity_at || chat.last_message_at) }}</dd>
      </dl>

      <section v-if="auth.isAdmin" class="admin-chat-tools" aria-label="Оператор чата">
        <strong>Оператор</strong>
        <select v-model.number="selectedOperatorId" aria-label="Назначить оператору">
          <option v-for="operator in operators" :key="operator.id" :value="operator.id">{{ operator.name }}</option>
        </select>
        <button class="primary-button compact" type="button" :disabled="!selectedOperatorId" @click="adminAssign">Назначить</button>
        <button class="secondary-button compact" type="button" :disabled="!chat.assigned_operator" @click="forceRelease">Освободить</button>
      </section>
    </template>
  </aside>
</template>
