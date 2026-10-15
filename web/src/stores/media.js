import { defineStore } from 'pinia'
import api from '../services/api'

export const useMediaStore = defineStore('media', {
  state: () => ({
    items: [], meta: { current_page: 1, last_page: 1, total: 0 },
    filters: { search: '', type: '', collection: '', page: 1 },
    loading: false, error: '',
  }),
  actions: {
    async fetch(tenantId, { silent = false } = {}) {
      if (!tenantId) { this.items = []; return }
      if (!silent) this.loading = true
      this.error = ''
      try {
        const params = { page: this.filters.page }
        if (this.filters.search) params.search = this.filters.search
        if (this.filters.type) params.type = this.filters.type
        if (this.filters.collection) params.collection = this.filters.collection
        const { data } = await api.get(`/tenants/${tenantId}/media`, { params })
        this.items = data.data
        this.meta = data.meta
      } catch (e) {
        this.error = e.response?.data?.message || 'Não foi possível carregar a biblioteca.'
      } finally { this.loading = false }
    },
    setFilter(patch) { Object.assign(this.filters, patch, patch.page ? {} : { page: 1 }) },
    async rename(tenantId, id, name) {
      const { data } = await api.patch(`/tenants/${tenantId}/media/${id}`, { display_name: name })
      this.replace(data.data)
      return data.data
    },
    async remove(tenantId, id) {
      await api.delete(`/tenants/${tenantId}/media/${id}`)
      this.items = this.items.filter((m) => m.id !== id)
      this.meta.total = Math.max(0, this.meta.total - 1)
    },
    async downloadUrl(tenantId, id) {
      return (await api.post(`/tenants/${tenantId}/media/${id}/download-url`)).data.data.url
    },
    replace(media) {
      const i = this.items.findIndex((m) => m.id === media.id)
      if (i >= 0) this.items[i] = media
    },
    reset() { this.items = []; this.filters = { search: '', type: '', collection: '', page: 1 } },
  },
})
