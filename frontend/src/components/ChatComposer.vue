<script setup lang="ts">
import { computed, ref } from 'vue'
import { useChatStore } from '../stores/chats'
import { chatApi } from '../api/endpoints'
import { ApiClientError } from '../api/client'

const chats = useChatStore()
const body = ref('')
const remaining = computed(() => 4096 - body.value.length)
const disabled = computed(() => !chats.canWrite || chats.sending || !body.value.trim())

const loadingSuggestion = ref(false)
const suggestionError = ref<string | null>(null)

async function fetchSuggestion() {
  if (!chats.selectedChatId) return
  loadingSuggestion.value = true
  suggestionError.value = null
  try {
    const res = await chatApi.aiSuggestion(chats.selectedChatId)
    body.value = res.suggestion
  } catch (e) {
    if (e instanceof ApiClientError) {
      suggestionError.value = e.message
    } else {
      suggestionError.value = 'Не удалось получить подсказку'
    }
  } finally {
    loadingSuggestion.value = false
  }
}

async function submit() {
  if (disabled.value) return
  const text = body.value
  body.value = ''
  try {
    await chats.send(text)
  } catch (error) {
    body.value = text
    chats.error = error instanceof Error ? error.message : 'Не удалось отправить сообщение'
  }
}
</script>

<template>
  <form class="composer" @submit.prevent="submit">
    <div v-if="suggestionError" class="ai-suggestion-error">{{ suggestionError }}</div>
    <textarea
      v-model="body"
      :disabled="!chats.canWrite"
      maxlength="4096"
      rows="3"
      placeholder="Напишите ответ клиенту…"
      @keydown.meta.enter.prevent="submit"
      @keydown.ctrl.enter.prevent="submit"
    />
    <div class="composer-footer">
      <span>{{ remaining }} символов · Ctrl/⌘+Enter</span>
      <div class="composer-actions">
        <button
          v-if="chats.canWrite"
          type="button"
          class="secondary-button ai-hint-button"
          :disabled="loadingSuggestion"
          @click="fetchSuggestion"
        >{{ loadingSuggestion ? 'Думаю…' : '✦ Подсказка' }}</button>
        <button class="primary-button" type="submit" :disabled="disabled">{{ chats.sending ? 'Отправляем…' : 'Отправить' }}</button>
      </div>
    </div>
  </form>
</template>
