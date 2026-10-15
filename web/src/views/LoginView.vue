<script setup>
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { parseError } from '../services/api'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const form = reactive({ email: '', password: '' })
const loading = ref(false)
const error = ref('')
const fields = ref({})

async function submit() {
  loading.value = true; error.value = ''; fields.value = {}
  try {
    await auth.login(form)
    router.push(route.query.redirect || { name: 'market' })
  } catch (e) {
    const p = parseError(e); error.value = p.message; fields.value = p.fields
  } finally { loading.value = false }
}
</script>

<template>
  <div class="card auth-card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 brand mb-4">Entrar no MediaHub</h1>
      <FormAlert :message="error" />
      <form @submit.prevent="submit">
        <FormField v-model="form.email" label="E-mail" type="email" autocomplete="email" required />
        <FormField v-model="form.password" label="Senha" type="password" autocomplete="current-password" required />
        <LoadingButton :loading="loading" class="w-100">Entrar</LoadingButton>
      </form>
      <div class="d-flex justify-content-between mt-3 small">
        <RouterLink :to="{ name: 'forgot' }">Esqueci a senha</RouterLink>
        <RouterLink :to="{ name: 'register' }">Criar conta</RouterLink>
      </div>
    </div>
  </div>
</template>
