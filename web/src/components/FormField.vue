<script setup>
defineProps({ label: String, modelValue: String, type: { type: String, default: 'text' }, error: String, autocomplete: String, required: Boolean })
defineEmits(['update:modelValue'])
</script>

<template>
  <div class="mb-3">
    <label class="form-label">{{ label }}</label>
    <input
      class="form-control"
      :class="{ 'is-invalid': error }"
      :type="type"
      :value="modelValue"
      :autocomplete="autocomplete"
      :required="required"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <div v-if="error" class="invalid-feedback">{{ error }}</div>
  </div>
</template>
