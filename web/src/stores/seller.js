import { defineStore } from 'pinia'
import api from '../services/api'

export const useSellerStore = defineStore('seller', {
  state: () => ({ profile: null, loaded: false }),
  getters: { isSeller: (s) => !!s.profile && s.profile.status === 'active' },
  actions: {
    async fetch() {
      try { this.profile = (await api.get('/seller/status')).data.data } catch { this.profile = null }
      this.loaded = true
    },
    async join(payload) {
      this.profile = (await api.post('/seller/join', payload)).data.data
    },
    reset() { this.profile = null; this.loaded = false },
  },
})
