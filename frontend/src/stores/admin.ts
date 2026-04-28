import { defineStore } from 'pinia'
import { adminApi } from '../api/endpoints'
import type { AuditLog, Chat, Role, User } from '../types'

export const useAdminStore = defineStore('admin', {
  state: () => ({
    users: [] as User[],
    audit: [] as AuditLog[],
    loading: false,
    error: null as string | null,
  }),
  actions: {
    async load() {
      this.loading = true
      try {
        const [users, audit] = await Promise.all([adminApi.users(), adminApi.auditLog()])
        this.users = users.data
        this.audit = audit.data
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Не удалось загрузить админ-данные'
      } finally {
        this.loading = false
      }
    },
    async createUser(payload: { name: string; email: string; password: string; role: Role }) {
      const { user } = await adminApi.createUser(payload)
      this.users.push(user)
    },
    async changeRole(user: User, role: Role) {
      const response = await adminApi.changeRole(user.id, role)
      Object.assign(user, response.user)
    },
    async resetPassword(user: User, password: string) {
      const response = await adminApi.resetPassword(user.id, password)
      Object.assign(user, response.user)
    },
    async assignChat(chatId: number, operatorId: number): Promise<Chat> {
      const { chat } = await adminApi.assignChat(chatId, operatorId)
      return chat
    },
    async forceReleaseChat(chatId: number): Promise<Chat> {
      const { chat } = await adminApi.forceReleaseChat(chatId)
      return chat
    },
  },
})
