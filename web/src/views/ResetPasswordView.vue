<script setup>
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { parseError } from '../services/api'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const route = useRoute()
const router = useRouter()
const form = reactive({ token: route.query.token || '', email: route.query.email || '', password: '', password_confirmation: '' })
const loading = ref(false)
const error = ref('')
const fields = ref({})

async function submit() {
  loading.value = true; error.value = ''; fields.value = {}
  try {
    await api.post('/auth/reset-password', form)
    router.push({ name: 'login' })
  } catch (e) { const p = parseError(e); error.value = p.message; fields.value = p.fields } finally { loading.value = false }
}
</script>

<template>
  <div class="card auth-card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 brand mb-4">Definir nova senha</h1>
      <FormAlert :message="error" />
      <form @submit.prevent="submit">
        <FormField v-model="form.password" label="Nova senha" type="password" autocomplete="new-password" :error="fields.password" required />
        <FormField v-model="form.password_confirmation" label="Confirmar senha" type="password" autocomplete="new-password" required />
        <LoadingButton :loading="loading" class="w-100">Redefinir senha</LoadingButton>
      </form>
    </div>
  </div>
</template>
