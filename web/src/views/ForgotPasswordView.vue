<script setup>
import { ref } from 'vue'
import api, { parseError } from '../services/api'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const email = ref('')
const loading = ref(false)
const error = ref('')
const done = ref('')

async function submit() {
  loading.value = true; error.value = ''; done.value = ''
  try {
    done.value = (await api.post('/auth/forgot-password', { email: email.value })).data.message
  } catch (e) { error.value = parseError(e).message } finally { loading.value = false }
}
</script>

<template>
  <div class="card auth-card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 brand mb-4">Recuperar senha</h1>
      <FormAlert :message="error" />
      <FormAlert :message="done" type="success" />
      <form v-if="!done" @submit.prevent="submit">
        <FormField v-model="email" label="E-mail" type="email" autocomplete="email" required />
        <LoadingButton :loading="loading" class="w-100">Enviar instruções</LoadingButton>
      </form>
      <p class="mt-3 mb-0 small text-center"><RouterLink :to="{ name: 'login' }">Voltar ao login</RouterLink></p>
    </div>
  </div>
</template>
