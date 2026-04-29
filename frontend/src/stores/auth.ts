import { defineStore } from 'pinia'
import { authApi } from '../api/endpoints'
import { setTokenProvider } from '../api/client'
import type { LoginPayload, RegisterPayload } from '../api/endpoints'
import type { User } from '../types'

const TOKEN_KEY = 'operator_token'

function getStoredToken(): string | null {
  return typeof sessionStorage?.getItem === 'function' ? sessionStorage.getItem(TOKEN_KEY) : null
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: getStoredToken(),
    user: null as User | null,
    loading: false,
    error: null as string | null,
    registrationAvailable: false,
  }),
  getters: {
    isAuthenticated: (state) => Boolean(state.token),
    isAdmin: (state) => state.user?.role === 'admin',
  },
  actions: {
    initializeTokenProvider() {
      setTokenProvider(() => this.token)
    },
    setSession(token: string, user: User) {
      this.token = token
      this.user = user
      sessionStorage.setItem(TOKEN_KEY, token)
      this.initializeTokenProvider()
    },
    async loadBootstrapStatus() {
      try {
        const response = await authApi.bootstrapStatus()
        this.registrationAvailable = response.registration_available
      } catch {
        this.registrationAvailable = false
      }
    },
    clearSession() {
      this.token = null
      this.user = null
      sessionStorage.removeItem(TOKEN_KEY)
      this.initializeTokenProvider()
    },
    async restore() {
      if (!this.token) return
      this.loading = true
      try {
        const response = await authApi.me()
        this.user = response.user
      } catch {
        this.clearSession()
      } finally {
        this.loading = false
      }
    },
    async login(payload: LoginPayload) {
      this.loading = true
      this.error = null
      try {
        const response = await authApi.login(payload)
        this.setSession(response.token, response.user)
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Не удалось войти'
        throw error
      } finally {
        this.loading = false
      }
    },
    async register(payload: RegisterPayload) {
      this.loading = true
      this.error = null
      try {
        const response = await authApi.register(payload)
        this.registrationAvailable = false
        this.setSession(response.token, response.user)
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Не удалось зарегистрироваться'
        throw error
      } finally {
        this.loading = false
      }
    },
    async logout() {
      try {
        if (this.token) await authApi.logout()
      } finally {
        this.clearSession()
      }
    },
  },
})
