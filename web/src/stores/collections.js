import { defineStore } from 'pinia'
import api from '../services/api'

export const useCollectionsStore = defineStore('collections', {
  state: () => ({ items: [], loading: false }),
  getters: {
    // árvore: { id, name, children: [...] }
    tree(state) {
      const map = new Map(state.items.map((c) => [c.id, { ...c, children: [] }]))
      const roots = []
      for (const node of map.values()) {
        const parent = node.parent_id && map.get(node.parent_id)
        ;(parent ? parent.children : roots).push(node)
      }
      return roots
    },
  },
  actions: {
    async fetch(tenantId) {
      if (!tenantId) { this.items = []; return }
      this.loading = true
      try { this.items = (await api.get(`/tenants/${tenantId}/collections`)).data.data } finally { this.loading = false }
    },
    async create(tenantId, name, parentId = null) {
      await api.post(`/tenants/${tenantId}/collections`, { name, parent_id: parentId })
      await this.fetch(tenantId)
    },
    async rename(tenantId, id, name) {
      await api.patch(`/tenants/${tenantId}/collections/${id}`, { name })
      await this.fetch(tenantId)
    },
    async remove(tenantId, id) {
      await api.delete(`/tenants/${tenantId}/collections/${id}`)
      await this.fetch(tenantId)
    },
    async attach(tenantId, collectionId, mediaIds) {
      await api.post(`/tenants/${tenantId}/collections/${collectionId}/media`, { media: mediaIds })
    },
    async detach(tenantId, collectionId, mediaId) {
      await api.delete(`/tenants/${tenantId}/collections/${collectionId}/media/${mediaId}`)
    },
  },
})
