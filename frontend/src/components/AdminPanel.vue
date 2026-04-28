<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { useAdminStore } from '../stores/admin'
import type { Role } from '../types'
import { formatDateTime } from '../utils/time'

const admin = useAdminStore()
const form = reactive({ name: '', email: '', password: '', role: 'operator' as Role })
const resetPasswords = reactive<Record<number, string>>({})

onMounted(() => admin.load())

async function createUser() {
  await admin.createUser({ ...form })
  form.name = ''
  form.email = ''
  form.password = ''
  form.role = 'operator'
}
</script>

<template>
  <section class="admin-panel" aria-labelledby="admin-title">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Администрирование</p>
        <h2 id="admin-title">Пользователи и аудит</h2>
      </div>
      <button class="icon-button" type="button" @click="admin.load()">↻</button>
    </div>

    <form class="admin-create" @submit.prevent="createUser">
      <input v-model="form.name" required placeholder="Имя" />
      <input v-model="form.email" required type="email" placeholder="Email" />
      <input v-model="form.password" required type="password" minlength="8" placeholder="Пароль" />
      <select v-model="form.role" aria-label="Роль">
        <option value="operator">Оператор</option>
        <option value="admin">Администратор</option>
      </select>
      <button class="primary-button compact" type="submit">Добавить</button>
    </form>

    <div class="admin-columns">
      <div class="table-shell">
        <h3>Пользователи</h3>
        <div v-for="user in admin.users" :key="user.id" class="admin-row">
          <div>
            <strong>{{ user.name }}</strong>
            <small>{{ user.email }} · {{ user.is_active ? 'активен' : 'отключён' }}</small>
          </div>
          <select :value="user.role" @change="admin.changeRole(user, ($event.target as HTMLSelectElement).value as Role)">
            <option value="operator">operator</option>
            <option value="admin">admin</option>
          </select>
          <input v-model="resetPasswords[user.id]" type="password" minlength="8" placeholder="Новый пароль" />
          <button class="secondary-button compact" type="button" @click="admin.resetPassword(user, resetPasswords[user.id] || '')">Сбросить</button>
        </div>
      </div>

      <div class="table-shell">
        <h3>Последние события</h3>
        <div v-for="entry in admin.audit.slice(0, 8)" :key="entry.id" class="audit-row">
          <strong>{{ entry.event_type }}</strong>
          <small>{{ entry.actor_user?.name || 'system' }} · {{ formatDateTime(entry.created_at) }}</small>
        </div>
      </div>
    </div>
  </section>
</template>
