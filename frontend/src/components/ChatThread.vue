<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useChatStore } from '../stores/chats'
import { deliveryLabels, readReceiptLabel, unsupportedLabel } from '../utils/labels'
import { formatDateTime, minutesUntil } from '../utils/time'
import type { Message } from '../types'

const auth = useAuthStore()
const chats = useChatStore()
const thread = ref<HTMLElement | null>(null)

const title = computed(() => chats.selectedChat?.external_user.display_name ?? 'Выберите диалог')
const owner = computed(() => chats.selectedChat?.assigned_operator?.name ?? null)
const canAssign = computed(() => chats.selectedChat?.status === 'open' && !chats.selectedChat?.assigned_operator)
const readOnlyReason = computed(() => {
  if (!chats.selectedChat) return ''
  if (chats.selectedChat.status === 'closed') return 'Диалог закрыт: отправка недоступна.'
  if (chats.selectedChat.read_only) return owner.value ? `Диалог ведёт ${owner.value}. Вы можете читать историю без блокировки.` : 'Диалог доступен только для чтения.'
  return ''
})

function canRetry(message: Message): boolean {
  return Boolean(message.delivery && ['failed', 'retrying'].includes(message.delivery.status) && (chats.canWrite || auth.isAdmin))
}

async function retry(message: Message) {
  if (canRetry(message) && message.delivery) await chats.retryDelivery(message.delivery)
}

watch(() => chats.messages.length, async () => {
  await nextTick()
  thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })
})
</script>

<template>
  <section class="thread-card">
    <header class="thread-header">
      <div>
        <p class="eyebrow">Активный диалог</p>
        <h2>{{ title }}</h2>
        <p v-if="chats.selectedChat" class="status-line">
          <span>{{ chats.selectedChat.channel.name || chats.selectedChat.channel.code }} · #{{ chats.selectedChat.id }}</span>
        </p>
      </div>
      <div v-if="chats.selectedChat" class="thread-actions">
        <span v-if="owner" class="assignment-chip assigned owner-chip">Владелец: {{ owner }}</span>
        <span v-else class="assignment-chip owner-chip">Свободный диалог</span>
        <button v-if="canAssign" class="primary-button compact" type="button" @click="chats.assignSelected()">Взять в работу</button>
      </div>
    </header>

    <p v-if="chats.conflict" class="conflict-banner" role="alert">{{ chats.conflict }}</p>
    <p v-if="readOnlyReason" class="readonly-banner">{{ readOnlyReason }}</p>

    <div v-if="!chats.selectedChat" class="empty-state">
      <strong>Диалог не выбран</strong>
      <span>Откройте свободный или свой чат, чтобы увидеть историю и действия.</span>
    </div>

    <div v-else ref="thread" class="message-thread" aria-live="polite">
      <button v-if="chats.nextMessageCursor" class="load-more" type="button" @click="chats.loadOlderMessages()">Показать предыдущие</button>
      <article
        v-for="message in chats.messages"
        :key="message.id"
        :class="['message-bubble', message.direction]"
      >
        <p v-if="message.type === 'unsupported_message'" class="unsupported">{{ unsupportedLabel(message.metadata) }}</p>
        <p v-else>{{ message.body }}</p>
        <footer>
          <time>{{ formatDateTime(message.created_at) }}</time>
          <span v-if="message.direction === 'inbound' && readReceiptLabel(message.read_by)" class="read-mark">{{ readReceiptLabel(message.read_by) }}</span>
          <span v-if="message.direction === 'outbound' && message.delivery" :class="['delivery-pill', message.delivery.status]">
            {{ deliveryLabels[message.delivery.status] ?? message.delivery.status }}
          </span>
          <button
            v-if="canRetry(message)"
            class="link-button"
            type="button"
            @click="retry(message)"
          >
            повторить<span v-if="minutesUntil(message.delivery?.next_attempt_at)"> через {{ minutesUntil(message.delivery?.next_attempt_at) }} мин</span>
          </button>
        </footer>
        <small v-if="message.delivery?.provider_error_message" class="provider-error">{{ message.delivery.provider_error_message }}</small>
      </article>
    </div>
  </section>
</template>
