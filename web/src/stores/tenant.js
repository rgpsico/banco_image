import { defineStore } from 'pinia'
import api, { storage } from '../services/api'

const LEVEL = { viewer: 1, editor: 2, admin: 3, owner: 4 }

export const useTenantStore = defineStore('tenant', {
  state: () => ({ tenants: [], currentId: storage.tenant, loading: false }),
  getters: {
    current: (s) => s.tenants.find((t) => t.id === s.currentId) || null,
    role() { return this.current?.role || null },
    can() { return (min) => (LEVEL[this.role] || 0) >= LEVEL[min] },
  },
  actions: {
    async fetch() {
      this.loading = true
      try {
        this.tenants = (await api.get('/tenants')).data.data
        // mantém a seleção só se ainda for um projeto meu
        if (!this.tenants.some((t) => t.id === this.currentId)) this.select(this.tenants[0]?.id ?? null)
      } finally { this.loading = false }
    },
    select(id) { this.currentId = id; storage.tenant = id },
    async create(name) {
      const t = (await api.post('/tenants', { name })).data.data
      this.tenants.push(t); this.select(t.id); return t
    },
    async update(id, name) {
      const t = (await api.patch(`/tenants/${id}`, { name })).data.data
      const i = this.tenants.findIndex((x) => x.id === id)
      if (i >= 0) this.tenants[i] = { ...this.tenants[i], ...t, role: this.tenants[i].role }
    },
    async remove(id) {
      await api.delete(`/tenants/${id}`)
      this.tenants = this.tenants.filter((t) => t.id !== id)
      if (this.currentId === id) this.select(this.tenants[0]?.id ?? null)
    },
    reset() { this.tenants = []; this.select(null) },
  },
})
