import { defineStore } from 'pinia'
import api, { storage } from '../services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({ user: null, token: storage.token, ready: false }),
  getters: { isAuthenticated: (s) => !!s.token },
  actions: {
    async register(payload) { return this.applySession((await api.post('/auth/register', payload)).data.data) },
    async login(payload) { return this.applySession((await api.post('/auth/login', payload)).data.data) },
    applySession({ token, user }) {
      this.token = token; storage.token = token; this.user = user; this.ready = true
    },
    async fetchMe() {
      if (!this.token) { this.ready = true; return }
      try { this.user = (await api.get('/auth/me')).data.data } catch { this.clear() }
      this.ready = true
    },
    async logout() {
      try { await api.post('/auth/logout') } catch { /* token já inválido */ }
      this.clear()
    },
    clear() {
      this.token = null; this.user = null; storage.token = null; storage.tenant = null; this.ready = true
    },
  },
})
