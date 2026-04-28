<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const mode = computed(() => route.meta.mode === 'register' ? 'register' : 'login')
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })

async function submit() {
  if (mode.value === 'register') {
    await auth.register({ ...form })
  } else {
    await auth.login({ email: form.email, password: form.password })
  }
  await router.push('/chats')
}
</script>

<template>
  <main class="auth-shell">
    <section class="auth-panel" aria-labelledby="auth-title">
      <div class="brand-mark" aria-hidden="true">ОП</div>
      <p class="eyebrow">Рабочее место оператора</p>
      <h1 id="auth-title">{{ mode === 'register' ? 'Первичная регистрация администратора' : 'Вход в консоль' }}</h1>
      <p class="muted">
        Единая минималистичная панель для обработки диалогов Telegram, контроля очереди отправки и совместной работы операторов.
      </p>

      <form class="auth-form" @submit.prevent="submit">
        <label v-if="mode === 'register'">
          Имя администратора
          <input v-model="form.name" autocomplete="name" required placeholder="Анна Иванова" />
        </label>
        <label>
          Email
          <input v-model="form.email" type="email" autocomplete="email" required placeholder="operator@example.ru" />
        </label>
        <label>
          Пароль
          <input v-model="form.password" type="password" autocomplete="current-password" required minlength="8" placeholder="минимум 8 символов" />
        </label>
        <label v-if="mode === 'register'">
          Повтор пароля
          <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required minlength="8" />
        </label>

        <p v-if="auth.error" class="form-error" role="alert">{{ auth.error }}</p>
        <button class="primary-button" type="submit" :disabled="auth.loading">
          {{ auth.loading ? 'Проверяем…' : mode === 'register' ? 'Создать администратора' : 'Войти' }}
        </button>
      </form>

      <p class="auth-switch">
        <RouterLink v-if="mode === 'login'" to="/register">Первый запуск? Создать администратора</RouterLink>
        <RouterLink v-else to="/login">Уже есть аккаунт? Войти</RouterLink>
      </p>
    </section>

    <aside class="auth-aside" aria-label="Возможности системы">
      <div>
        <strong>10 минут</strong>
        <span>auto-release неактивных назначений</span>
      </div>
      <div>
        <strong>read receipts</strong>
        <span>операторы видят прочитанные сообщения</span>
      </div>
      <div>
        <strong>outbox</strong>
        <span>повтор отправки 1/2/5/10/30 минут</span>
      </div>
    </aside>
  </main>
</template>
