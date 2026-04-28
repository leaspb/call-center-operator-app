<script setup lang="ts">
import { computed, ref } from 'vue'
import { useChatStore } from '../stores/chats'

const chats = useChatStore()
const body = ref('')
const remaining = computed(() => 4096 - body.value.length)
const disabled = computed(() => !chats.canWrite || chats.sending || !body.value.trim())

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
      <button class="primary-button" type="submit" :disabled="disabled">{{ chats.sending ? 'Отправляем…' : 'Отправить' }}</button>
    </div>
  </form>
</template>
