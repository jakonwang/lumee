<script setup lang="ts">
import { ref } from 'vue'
import { NIcon, NSpin, useMessage } from 'naive-ui'
import { CloudUploadOutline, TrashOutline } from '@vicons/ionicons5'
import { http, errorMessage } from '@/api/http'
import type { ProductImage } from '@/api/products'
import { t } from '@/i18n'

const images = defineModel<ProductImage[]>({ required: true })
const emit = defineEmits<{ removed: [id: number] }>()
const message = useMessage()

const input = ref<HTMLInputElement>()
const uploading = ref(0)
const dropping = ref(false)
const dragIndex = ref<number | null>(null)

const MAX = 20

async function upload(files: FileList | File[]) {
  const list = Array.from(files).filter((f) => f.type.startsWith('image/'))
  const room = MAX - images.value.length - uploading.value
  if (list.length > room) message.warning(t('最多 {0} 张图片，已忽略多余的 {1} 张', [MAX, list.length - room]))

  await Promise.all(list.slice(0, Math.max(0, room)).map(async (file) => {
    if (file.size > 10 * 1024 * 1024) {
      message.error(t('{0} 超过 10MB', [file.name]))
      return
    }
    uploading.value++
    try {
      const body = new FormData()
      body.append('file', file)
      const { data } = await http.post('/uploads/images', body)
      images.value = [...images.value, data.data]
    } catch (e) {
      message.error(t('{0}：{1}', [file.name, errorMessage(e)]))
    } finally {
      uploading.value--
    }
  }))
}

function onPick(e: Event) {
  const el = e.target as HTMLInputElement
  if (el.files) upload(el.files)
  el.value = ''
}

function onDropFiles(e: DragEvent) {
  dropping.value = false
  if (dragIndex.value !== null) return // internal reorder, handled per-tile
  if (e.dataTransfer?.files.length) upload(e.dataTransfer.files)
}

function remove(i: number) {
  const [img] = images.value.splice(i, 1)
  images.value = [...images.value]
  if (img) emit('removed', img.id)
}

// Native drag-to-reorder
function onDragStart(i: number) { dragIndex.value = i }
function onDragEnter(i: number) {
  const from = dragIndex.value
  if (from === null || from === i) return
  const next = [...images.value]
  const [moved] = next.splice(from, 1)
  next.splice(i, 0, moved!)
  images.value = next
  dragIndex.value = i
}
function onDragEnd() { dragIndex.value = null }
</script>

<template>
  <div
    class="media"
    :class="{ dropping }"
    @dragover.prevent="dropping = dragIndex === null"
    @dragleave.self="dropping = false"
    @drop.prevent="onDropFiles"
  >
    <div
      v-for="(img, i) in images"
      :key="img.id"
      class="tile"
      :class="{ first: i === 0, dragging: dragIndex === i }"
      draggable="true"
      @dragstart="onDragStart(i)"
      @dragenter.prevent="onDragEnter(i)"
      @dragend="onDragEnd"
    >
      <img :src="img.url" :alt="img.alt ?? ''" draggable="false" />
      <span v-if="i === 0" class="badge">{{ t('主图') }}</span>
      <button type="button" class="remove" :aria-label="t('删除图片')" @click="remove(i)">
        <NIcon size="14"><TrashOutline /></NIcon>
      </button>
    </div>

    <div v-for="n in uploading" :key="'u' + n" class="tile pending"><NSpin size="small" /></div>

    <button v-if="images.length + uploading < MAX" type="button" class="tile add" :class="{ big: images.length === 0 }" @click="input?.click()">
      <NIcon size="26"><CloudUploadOutline /></NIcon>
      <span>{{ images.length === 0 ? t('点击或拖拽图片到这里上传') : t('添加') }}</span>
      <small v-if="images.length === 0">{{ t('JPG / PNG / WebP，单张不超过 10MB，可拖动排序，第一张为主图') }}</small>
    </button>

    <input ref="input" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden @change="onPick" />
  </div>
</template>

<style scoped>
.media {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 12px;
  border-radius: 12px; transition: background .15s;
}
.media.dropping { background: var(--brand-50); outline: 2px dashed var(--brand-300); outline-offset: 4px; }
.tile {
  position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden;
  background: var(--surface-muted); border: 1px solid var(--border); cursor: grab;
}
.tile.first { grid-column: span 2; grid-row: span 2; }
.tile.dragging { opacity: .4; }
.tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.badge {
  position: absolute; left: 8px; top: 8px; font-size: 11px; padding: 2px 8px; border-radius: 999px;
  background: rgba(255,255,255,.9); color: var(--brand-700);
}
.remove {
  position: absolute; right: 6px; top: 6px; width: 26px; height: 26px; border-radius: 50%;
  border: none; background: rgba(255,255,255,.92); color: var(--danger); cursor: pointer;
  display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s;
}
.tile:hover .remove, .remove:focus-visible { opacity: 1; }
.pending { display: flex; align-items: center; justify-content: center; cursor: default; }
.add {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
  border-style: dashed; border-color: var(--brand-300); background: var(--brand-50);
  color: var(--brand-700); cursor: pointer; font: inherit; font-size: 13px;
}
.add:hover { background: var(--brand-100); }
.add.big { grid-column: 1 / -1; aspect-ratio: auto; padding: 36px 16px; }
.add small { color: var(--ink-muted); font-size: 12px; text-align: center; }
@media (hover: none) { .remove { opacity: 1; } }
</style>
