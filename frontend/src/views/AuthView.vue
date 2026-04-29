<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const mode = computed(() => route.meta.mode === 'register' ? 'register' : 'login')
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })

const showsAuthSwitch = computed(() => mode.value === 'register' || (mode.value === 'login' && auth.registrationAvailable))

watch(mode, (currentMode) => {
  if (currentMode === 'login') void auth.loadBootstrapStatus()
}, { immediate: true })

async function submit() {
  try {
    if (mode.value === 'register') {
      await auth.register({ ...form })
    } else {
      await auth.login({ email: form.email, password: form.password })
    }
    await router.push('/chats')
  } catch {
    // auth.error is already populated by the store.
  }
}
</script>

<template>
  <main class="auth-shell">
    <section class="auth-panel" aria-labelledby="auth-title">
      <div class="brand-mark" aria-hidden="true">ОП</div>
      <p class="eyebrow">Рабочее место оператора</p>
      <h1 id="auth-title">{{ mode === 'register' ? 'Первичная регистрация администратора' : 'Вход в консоль' }}</h1>
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
          <input v-model="form.password" type="password" autocomplete="current-password" required minlength="6" placeholder="минимум 6 символов" />
        </label>
        <label v-if="mode === 'register'">
          Повтор пароля
          <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required minlength="6" />
        </label>

        <p v-if="auth.error" class="form-error" role="alert">{{ auth.error }}</p>
        <button class="primary-button" type="submit" :disabled="auth.loading">
          {{ auth.loading ? 'Проверяем…' : mode === 'register' ? 'Создать администратора' : 'Войти' }}
        </button>
      </form>

      <p v-if="showsAuthSwitch" class="auth-switch">
        <RouterLink v-if="mode === 'login' && auth.registrationAvailable" to="/register">Первый запуск? Создать администратора</RouterLink>
        <RouterLink v-if="mode === 'register'" to="/login">Уже есть аккаунт? Войти</RouterLink>
      </p>
    </section>
  </main>
</template>
