<script setup>
defineProps({ node: Object, active: String, canEdit: Boolean, depth: { type: Number, default: 0 } })
const emit = defineEmits(['select', 'create-child', 'rename', 'remove'])
</script>

<template>
  <li>
    <div class="d-flex align-items-center folder" :class="{ active: active === node.id }" :style="{ paddingLeft: depth * 14 + 8 + 'px' }">
      <button class="btn btn-link text-start flex-grow-1 text-truncate p-1" @click="emit('select', node.id)" :title="node.name">
        <span aria-hidden="true">📁</span> {{ node.name }} <span class="text-muted small">({{ node.media_count }})</span>
      </button>
      <span v-if="canEdit" class="actions">
        <button class="btn btn-sm p-0 px-1" title="Nova subpasta" @click="emit('create-child', node)">＋</button>
        <button class="btn btn-sm p-0 px-1" title="Renomear" @click="emit('rename', node)">✎</button>
        <button class="btn btn-sm p-0 px-1 text-danger" title="Excluir" @click="emit('remove', node)">🗑</button>
      </span>
    </div>
    <ul class="list-unstyled mb-0">
      <CollectionNode
        v-for="c in node.children" :key="c.id" :node="c" :active="active" :can-edit="canEdit" :depth="depth + 1"
        @select="emit('select', $event)" @create-child="emit('create-child', $event)" @rename="emit('rename', $event)" @remove="emit('remove', $event)"
      />
    </ul>
  </li>
</template>

<style scoped>
.folder { border-radius: 6px; }
.folder:hover { background: #eef1f7; }
.folder.active { background: #dfe8ff; }
.folder .btn-link { text-decoration: none; color: inherit; }
.actions { opacity: 0; white-space: nowrap; }
.folder:hover .actions, .folder.active .actions { opacity: 1; }
</style>
