<script setup lang="ts">
import { computed } from 'vue'
import { useChatStore } from '../stores/chats'
import type { ChatFilter } from '../types'
import { filterLabels, assignmentLabel } from '../utils/labels'
import { formatTime } from '../utils/time'

const chats = useChatStore()
const filters = Object.keys(filterLabels) as ChatFilter[]
const syncLabel = computed(() => chats.lastSyncAt ? formatTime(chats.lastSyncAt.toISOString()) : '—')
</script>

<template>
  <aside class="chat-list" aria-label="Список диалогов">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Диалоги</p>
        <h2>Очередь</h2>
      </div>
      <button class="icon-button" type="button" title="Обновить" @click="chats.loadChats()">↻</button>
    </div>

    <div class="filter-strip" role="tablist" aria-label="Фильтр диалогов">
      <button
        v-for="filter in filters"
        :key="filter"
        type="button"
        :class="['filter-chip', { active: chats.filter === filter }]"
        @click="chats.setFilter(filter)"
      >
        {{ filterLabels[filter] }}
      </button>
    </div>

    <p v-if="chats.error" class="inline-error" role="alert">{{ chats.error }}</p>
    <p class="sync-note">Синхронизация: {{ syncLabel }} · polling 12 сек</p>

    <div class="chat-items">
      <button
        v-for="chat in chats.chats"
        :key="chat.id"
        type="button"
        :class="['chat-row', { active: chats.selectedChatId === chat.id, readonly: chat.read_only }]"
        @click="chats.openChat(chat.id)"
      >
        <span class="chat-row-main">
          <strong>{{ chat.external_user.display_name }}</strong>
          <small>{{ assignmentLabel(chat.assignment_state, chat.assigned_operator?.name) }}</small>
          <span class="preview">{{ chat.last_message_preview || 'Сообщений пока нет' }}</span>
        </span>
        <span class="chat-row-meta">
          <time>{{ formatTime(chat.last_message_at) }}</time>
          <span v-if="chat.unread_count" class="unread-badge">{{ chat.unread_count }}</span>
        </span>
      </button>

      <p v-if="!chats.loadingChats && chats.chats.length === 0" class="empty-copy">Нет диалогов для выбранного фильтра.</p>
      <button v-if="chats.nextChatCursor" class="load-more" type="button" @click="chats.loadChats(chats.nextChatCursor)">Загрузить ещё</button>
    </div>
  </aside>
</template>
