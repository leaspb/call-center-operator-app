import { createRouter, createWebHistory } from 'vue-router'
import AuthView from '../views/AuthView.vue'
import OperatorWorkspace from '../views/OperatorWorkspace.vue'
import { useAuthStore } from '../stores/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/chats' },
    { path: '/login', component: AuthView, meta: { public: true, mode: 'login' } },
    { path: '/register', component: AuthView, meta: { public: true, mode: 'register' } },
    { path: '/chats', component: OperatorWorkspace },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  auth.initializeTokenProvider()
  if (auth.token && !auth.user && !auth.loading) await auth.restore()

  if (!to.meta.public && !auth.isAuthenticated) return '/login'
  if (to.meta.public && auth.isAuthenticated) return '/chats'
})
