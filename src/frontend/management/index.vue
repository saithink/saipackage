<template>
  <ElCard shadow="never" class="management-card" v-loading="busy">
    <template #header>
      <ElSpace wrap><strong>{{ tr('title') }}</strong><ElButton @click="refresh(true)">{{ tr('refresh') }}</ElButton><ElButton @click="checkUpdates()">{{ tr('checkUpdates') }}</ElButton></ElSpace>
    </template>
    <ElAlert v-if="error" :title="error" type="error" :closable="false" />
    <ElTable :data="plugins" row-key="id">
      <ElTableColumn prop="id" :label="tr('plugin')" />
      <ElTableColumn prop="manifest.version" :label="tr('version')" />
      <ElTableColumn :label="tr('status')"><template #default="{ row }"><ElTag>{{ tr(row.state.status) }}</ElTag></template></ElTableColumn>
      <ElTableColumn :label="tr('onlineUpdates')" min-width="210"><template #default="{ row }">
        <ElSpace direction="vertical" alignment="start">
          <span v-if="updateCatalog[row.id]?.latest">{{ tr('latestVersion') }}: {{ updateCatalog[row.id].latest }}</span>
          <span v-if="updateCatalog[row.id]?.compatible">{{ tr('compatibleVersion') }}: {{ updateCatalog[row.id].compatible }}</span>
          <ElTag>{{ updateLabel(updateCatalog[row.id]) }}</ElTag>
          <ElButton @click="openUpdates(row.id)">{{ tr('viewUpdates') }}</ElButton>
        </ElSpace>
      </template></ElTableColumn>
      <ElTableColumn :label="tr('actions')" min-width="360"><template #default="{ row }">
        <ElSpace wrap>
          <ElButton :disabled="row.state.status === 'pending_install'" @click="openSettings(row.id)">{{ tr('configure') }}</ElButton>
          <ElButton :disabled="row.state.installation !== 'installed'" @click="toggle(row.id, !row.state.enabled)">{{ tr(row.state.enabled ? 'disable' : 'enable') }}</ElButton>
          <ElButton :disabled="!canPlan(row)" @click="preview(row.id)">{{ tr('plan') }}</ElButton>
          <ElButton @click="showDocs(row.id)">{{ tr('docs') }}</ElButton>
          <ElButton @click="showDependencies(row)">{{ tr('dependencies') }}</ElButton>
          <ElButton type="danger" @click="uninstall(row.id)">{{ tr('uninstall') }}</ElButton>
        </ElSpace>
      </template></ElTableColumn>
    </ElTable>
    <ElDivider>{{ tr('tasks') }}</ElDivider>
    <ElTable :data="tasks">
      <ElTableColumn prop="id" :label="tr('task')" />
      <ElTableColumn :label="tr('taskType')"><template #default="{ row }">{{ tr(row.value.kind) }}</template></ElTableColumn>
      <ElTableColumn :label="tr('status')"><template #default="{ row }">{{ tr(row.value.kind === 'prepare_update' && row.value.state === 'succeeded' ? row.value.stage : row.value.state) }}</template></ElTableColumn>
      <ElTableColumn prop="value.current" :label="tr('plugin')" />
      <ElTableColumn :label="tr('actions')"><template #default="{ row }">
        <ElButton @click="details = JSON.stringify(row.value, null, 2); detailsVisible = true">{{ tr('details') }}</ElButton>
        <ElButton v-if="['needs_attention', 'waiting_dependencies'].includes(row.value.state)" @click="recovery = row">{{ tr('recover') }}</ElButton>
        <ElButton v-if="row.value.kind === 'prepare_update' && row.value.state === 'succeeded' && row.value.stage === 'awaiting_confirmation'" @click="reviewUpdate(row.id)">{{ tr('reviewUpdate') }}</ElButton>
      </template></ElTableColumn>
    </ElTable>

    <ElDialog v-model="settingsVisible" :title="`${tr('configure')} · ${selected}`" width="min(680px, calc(100vw - 32px))" :close-on-click-modal="false">
      <ElForm label-position="top">
        <ElFormItem v-for="field in fields" :key="field.path" :label="field.title || field.path">
          <template v-if="field.writeOnly">
            <ElSpace><ElTag>{{ tr(secrets[field.path]?.configured ? 'configured' : 'notConfigured') }}</ElTag>
              <ElSelect v-model="secretActions[field.path].action" style="width: 180px" :aria-label="tr('secret')">
                <ElOption v-for="action in ['keep', 'replace', 'clear']" :key="action" :value="action" :label="tr(action)" />
              </ElSelect>
            </ElSpace>
            <ElInput v-if="secretActions[field.path].action === 'replace'" v-model="secretActions[field.path].value" type="password" show-password autocomplete="new-password" :aria-label="field.title || field.path" />
          </template>
          <ElSelect v-else-if="field.enum" v-model="form[field.path]" :aria-label="field.title || field.path"><ElOption v-for="option in field.enum" :key="String(option)" :label="String(option)" :value="option" /></ElSelect>
          <ElSwitch v-else-if="field.type === 'boolean'" v-model="form[field.path]" :aria-label="field.title || field.path" />
          <ElInputNumber v-else-if="['integer', 'number'].includes(field.type)" v-model="form[field.path]" :min="field.minimum" :max="field.maximum" :precision="field.type === 'integer' ? 0 : undefined" :aria-label="field.title || field.path" />
          <ElInput v-else v-model="form[field.path]" :aria-label="field.title || field.path" />
        </ElFormItem>
      </ElForm>
      <ElAlert v-if="probeResult" :title="probeResult" :closable="false" />
      <template #footer><ElSpace wrap>
        <ElButton @click="test(true)">{{ tr('testDraft') }}</ElButton><ElButton @click="test(false)">{{ tr('testSaved') }}</ElButton>
        <ElButton type="danger" @click="clearSettings">{{ tr('clearSettings') }}</ElButton><ElButton type="primary" @click="save">{{ tr('save') }}</ElButton>
      </ElSpace></template>
    </ElDialog>
    <ElDialog v-model="planVisible" :title="tr(updateTask ? 'reviewUpdate' : 'plan')" width="min(680px, calc(100vw - 32px))">
      <ElAlert :title="tr('confirmPlan')" :closable="false" />
      <ElTable :data="planChanges">
        <ElTableColumn prop="id" :label="tr('plugin')" /><ElTableColumn prop="from" :label="tr('source')" /><ElTableColumn prop="to" :label="tr('target')" />
      </ElTable>
      <ElAlert v-if="updateTask" :title="tr('manualUpgradeNotice')" type="warning" :closable="false" />
      <template #footer><ElButton type="primary" @click="submit">{{ tr(updateTask ? 'confirmUpgrade' : 'submit') }}</ElButton></template>
    </ElDialog>
    <ElDialog v-model="updatesVisible" :title="`${tr('onlineUpdates')} · ${updatePlugin}`" width="min(720px, calc(100vw - 32px))">
      <ElAlert :title="tr('manualUpgradeNotice')" :closable="false" />
      <p>{{ updateLabel(updateCatalog[updatePlugin]) }}</p>
      <p v-if="updateCatalog[updatePlugin]?.checkedAt">{{ tr('lastChecked') }}: {{ new Date(updateCatalog[updatePlugin].checkedAt * 1000).toLocaleString(locale) }}</p>
      <p v-if="updateCatalog[updatePlugin]?.error">{{ tr(updateCatalog[updatePlugin].error) }}</p>
      <ElTable :data="updateCatalog[updatePlugin]?.releases || []">
        <ElTableColumn prop="version" :label="tr('version')" />
        <ElTableColumn :label="tr('compatibility')"><template #default="{ row }">{{ tr('update_' + row.compatibility) }}<p v-for="reason in row.reasons" :key="reason">{{ tr(reason) }}</p></template></ElTableColumn>
        <ElTableColumn :label="tr('releaseNotes')"><template #default="{ row }"><span class="management-text">{{ row.notes }}</span></template></ElTableColumn>
        <ElTableColumn :label="tr('actions')"><template #default="{ row }"><ElButton :disabled="row.compatibility === 'blocked' || updateCatalog[updatePlugin]?.state !== 'checked'" @click="prepareUpdate(row.fingerprint)">{{ tr('downloadPreview') }}</ElButton></template></ElTableColumn>
      </ElTable>
      <template #footer><ElButton @click="checkUpdates(updatePlugin)">{{ tr('checkUpdates') }}</ElButton></template>
    </ElDialog>
    <ElDialog v-model="detailsVisible" :title="tr('details')"><pre class="management-text">{{ details }}</pre></ElDialog>
    <ElDialog :model-value="!!recovery" :title="tr('recover')" @close="recovery = null">
      <ElAlert type="warning" :title="tr('recoveryWarning')" :closable="false" />
      <template #footer><ElSpace wrap><ElButton v-for="action in ['retry', 'accept_completed', 'cancel']" :key="action" @click="recover(action)">{{ tr(action) }}</ElButton></ElSpace></template>
    </ElDialog>
  </ElCard>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/http'
import messages from './messages'

const { t, mergeLocaleMessage, locale } = useI18n({ useScope: 'global' })
Object.entries(messages).forEach(([locale, value]) => mergeLocaleMessage(locale, value))
const tr = (key: string) => t(`saipackageManagement.${key}`)
const base = '/tool/install/management/'
const busy = ref(false), error = ref('')
const plugins = ref<any[]>([]), tasks = ref<any[]>([])
const updateCatalog = ref<Record<string, any>>({}), updatesVisible = ref(false), updatePlugin = ref(''), updateTask = ref(''), waitingPreparation = ref('')
const selected = ref(''), settingsVisible = ref(false), planVisible = ref(false), detailsVisible = ref(false)
const fields = ref<any[]>([]), form = ref<Record<string, any>>({}), secrets = ref<Record<string, any>>({})
const secretActions = ref<Record<string, { action: string; value?: string }>>({}), revision = ref(0)
const plan = ref<any>(null), recovery = ref<any>(null), details = ref(''), probeResult = ref('')
const planChanges = computed(() => Object.entries(plan.value?.changes || {}).map(([id, change]: [string, any]) => ({ id, ...change })))
let timer: ReturnType<typeof setInterval> | undefined
const get = (action: string, params = {}) => request.get<any>({ url: base + action, params, showErrorMessage: false })
const post = (action: string, data = {}) => request.post<any>({ url: base + action, data, showErrorMessage: false })
const canPlan = (row: any) => row.state.status === 'pending_install' || row.state.installation === 'uninstalled' || (row.availableVersion && row.availableVersion !== row.manifest.version)
async function run(work: () => Promise<void>) {
  busy.value = true; error.value = ''
  try { await work() } catch (failure) {
    const serverMessage = (failure as { data?: { message?: unknown } })?.data?.message
    const message = typeof serverMessage === 'string' ? serverMessage : failure instanceof Error ? failure.message : tr('error')
    error.value = message.startsWith('UPDATE_') ? tr(message) : message
  } finally { busy.value = false }
}
async function refresh(clearError = false) {
  const previousError = error.value
  await run(async () => {
    const [catalog, records, updates] = await Promise.all([get('catalog'), get('tasks'), get('updates')])
    plugins.value = Object.entries(catalog).map(([id, item]: [string, any]) => ({ id, ...item }))
    tasks.value = Object.entries(records).map(([id, item]: [string, any]) => ({ id, ...item }))
    updateCatalog.value = updates
  })
  if (!clearError && !error.value) error.value = previousError
  if (waitingPreparation.value) {
    const task = tasks.value.find(item => item.id === waitingPreparation.value)
    if (task?.value.state === 'succeeded') { const id = waitingPreparation.value; waitingPreparation.value = ''; await reviewUpdate(id) }
    else if (task?.value.state === 'needs_attention') { waitingPreparation.value = ''; error.value = tr(task.value.error || 'UPDATE_PREPARATION_FAILED') }
  }
}
function flatten(schema: any, prefix = ''): any[] {
  return Object.entries(schema.properties || {}).flatMap(([name, value]: [string, any]) =>
    value.type === 'object' ? flatten(value, `${prefix}${name}.`) : [{ ...value, path: prefix + name }])
}
function read(values: any, path: string) { return path.split('.').reduce((node, part) => node?.[part], values) }
async function loadSettings() {
  const data = await get('settings', { appName: selected.value })
  fields.value = flatten(data.schema); revision.value = data.revision; secrets.value = data.secrets
  form.value = {}; secretActions.value = {}
  for (const field of fields.value) {
    if (field.writeOnly) secretActions.value[field.path] = { action: 'keep' }
    else form.value[field.path] = read(data.values, field.path) ?? field.default
  }
}
async function openSettings(id: string) { await run(async () => { selected.value = id; probeResult.value = ''; await loadSettings(); settingsVisible.value = true }) }
function payload() {
  const values: Record<string, any> = {}
  for (const [path, value] of Object.entries(form.value)) {
    if (value === undefined) continue
    const parts = path.split('.'), last = parts.pop()!; let node = values
    for (const part of parts) node = node[part] ||= {}
    node[last] = value
  }
  return { appName: selected.value, revision: revision.value, values, secretActions: secretActions.value }
}
async function save() { await run(async () => { await post('saveSettings', payload()); await loadSettings(); ElMessage.success(tr('saved')) }); await refresh() }
async function test(draft: boolean) { await run(async () => {
  const result = await post('probe', { ...payload(), draft })
  probeResult.value = Object.entries(result.results).map(([name, value]: [string, any]) =>
    `${name}: ${tr(value.code)}${value.serviceVersion ? ` · ${tr('version')} ${value.serviceVersion}` : ''} · ${value.latencyMs} ms`).join(' / ')
  ElMessage.success(tr('probeDone'))
}); await refresh() }
async function toggle(id: string, enabled: boolean) { await run(async () => { await post('enable', { appName: id, enabled }) }); await refresh() }
async function preview(id: string) { await run(async () => { updateTask.value = ''; plan.value = await post('plan', { appName: id }); planVisible.value = true }) }
async function submit() { await run(async () => {
  if (updateTask.value) await post('confirmUpdate', { taskId: updateTask.value, fingerprint: plan.value.fingerprint })
  else await post('submit', { appName: plan.value.root, fingerprint: plan.value.fingerprint })
  planVisible.value = false; updateTask.value = ''
}); await refresh() }
function updateLabel(item: any) {
  if (!item) return tr('update_unconfigured')
  if (item.state !== 'checked') return tr('update_' + item.state)
  return tr(item.latest ? 'update_available' : 'update_current')
}
async function checkUpdates(id?: string) { await run(async () => { await post('checkUpdates', id ? { appName: id } : {}) }); await refresh() }
function openUpdates(id: string) { updatePlugin.value = id; updatesVisible.value = true }
async function prepareUpdate(fingerprint: string) { await run(async () => {
  const result = await post('prepareUpdate', { appName: updatePlugin.value, releaseFingerprint: fingerprint })
  waitingPreparation.value = result.taskId; updatesVisible.value = false; ElMessage.success(tr('downloadQueued'))
}); await refresh() }
async function reviewUpdate(taskId: string) { await run(async () => { plan.value = await post('updatePlan', { taskId }); updateTask.value = taskId; planVisible.value = true }) }
async function showDocs(id: string) { await run(async () => { const data = await get('docs', { appName: id }); details.value = Object.entries(data).map(([name, text]) => `${name}\n\n${text}`).join('\n\n'); detailsVisible.value = true }) }
function showDependencies(row: any) {
  const reverse = plugins.value.filter(item => item.manifest.requiresPlugins?.[row.id]).map(item => item.id)
  details.value = JSON.stringify({ [tr('dependencies')]: row.manifest.requiresPlugins || {}, [tr('reverseDependencies')]: reverse, [tr('externalServices')]: row.manifest.externalServices || [] }, null, 2)
  detailsVisible.value = true
}
async function uninstall(id: string) {
  await run(async () => {
    await post('uninstallPlan', { appName: id })
    await ElMessageBox.confirm(tr('uninstallWarning'), tr('uninstall'))
    await request.post({ url: '/app/saipackage/install/uninstall', data: { appName: id } })
  }); await refresh()
}
async function clearSettings() { await run(async () => { await ElMessageBox.confirm(tr('clearWarning'), tr('clearSettings')); await post('clearSettings', { appName: selected.value, revision: revision.value }); await loadSettings() }) }
async function recover(action: string) { await run(async () => { await post('recover', { taskId: recovery.value.id, revision: recovery.value.revision, action }); recovery.value = null }); await refresh() }
onMounted(() => { refresh(); timer = setInterval(() => { if (!busy.value) refresh() }, 5000) })
onUnmounted(() => { if (timer) clearInterval(timer); secretActions.value = {} })
</script>

<style scoped>
.management-card { margin-bottom: 16px; flex-shrink: 0; }
.management-text { white-space: pre-wrap; overflow-wrap: anywhere; color: var(--el-text-color-primary); }
</style>
