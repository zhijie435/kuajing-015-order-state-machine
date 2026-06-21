<template>
  <div class="order-state-machine">
    <div class="order-header">
      <h2>订单状态管理</h2>
      <div class="order-info" v-if="order">
        <span class="order-no">订单号: {{ order.order_no }}</span>
        <el-tag :color="order.status_color" effect="dark">
          {{ order.status_label }}
        </el-tag>
        <el-tag v-if="order.status === 'exception'" type="danger">
          异常原因: {{ order.exception_reason }}
        </el-tag>
      </div>
    </div>

    <el-alert
      v-if="consistencyCheck && !consistencyCheck.is_consistent"
      title="状态不一致警告"
      type="warning"
      show-icon
      :closable="false"
    >
      <template #default>
        <p>数据库状态: {{ consistencyCheck.db_status }}</p>
        <p>内存状态: {{ consistencyCheck.memory_status }}</p>
        <p>快照状态: {{ consistencyCheck.snapshot_status }}</p>
      </template>
    </el-alert>

    <div class="state-transition-diagram">
      <h3>状态流转图</h3>
      <div class="diagram-container">
        <svg viewBox="0 0 800 400" class="state-svg">
          <defs>
            <marker
              id="arrowhead"
              markerWidth="10"
              markerHeight="7"
              refX="9"
              refY="3.5"
              orient="auto"
            >
              <polygon points="0 0, 10 3.5, 0 7" fill="#666" />
            </marker>
          </defs>

          <g v-for="(node, index) in stateNodes" :key="node.status">
            <ellipse
              :cx="node.x"
              :cy="node.y"
              :rx="60"
              :ry="30"
              :fill="currentStatus === node.status ? node.color : '#f5f5f5'"
              :stroke="node.color"
              stroke-width="2"
              :class="{ 'current-state': currentStatus === node.status }"
            />
            <text
              :x="node.x"
              :y="node.y + 5"
              text-anchor="middle"
              :fill="currentStatus === node.status ? '#fff' : '#333'"
              font-size="14"
            >
              {{ node.label }}
            </text>
          </g>

          <g v-for="(transition, index) in transitions" :key="'t-' + index">
            <path
              :d="transition.path"
              fill="none"
              stroke="#999"
              stroke-width="1.5"
              marker-end="url(#arrowhead)"
              :class="{ 'active-transition': isActiveTransition(transition) }"
            />
            <text
              v-if="transition.labelX !== undefined"
              :x="transition.labelX"
              :y="transition.labelY"
              text-anchor="middle"
              fill="#666"
              font-size="12"
            >
              {{ transition.label }}
            </text>
          </g>
        </svg>
      </div>
    </div>

    <div class="action-section">
      <h3>可用操作</h3>
      <div class="action-buttons">
        <el-button
          v-for="event in availableEvents"
          :key="event.event"
          :type="getButtonType(event.event)"
          @click="executeAction(event.event)"
          :loading="loading === event.event"
          :disabled="!canExecute(event.event)"
        >
          {{ event.label }}
        </el-button>
        <el-button
          v-if="order && order.status === 'exception'"
          type="success"
          @click="showResolveDialog = true"
        >
          解决异常
        </el-button>
        <el-button
          v-if="order && order.can_rollback && !order.requires_rollback_audit"
          type="warning"
          @click="executeRollback"
          :loading="loading === 'rollback'"
        >
          回滚 ({{ order.rollback_depth }})
        </el-button>
        <el-button
          v-if="order && order.requires_rollback_audit && order.rollback_depth > 0"
          type="warning"
          @click="showRollbackAuditDialog = true"
          :loading="loading === 'submit_rollback_audit'"
        >
          申请回滚审核
        </el-button>
        <el-button
          type="danger"
          @click="showExceptionDialog = true"
          :disabled="order && isTerminalStatus(order.status)"
        >
          标记异常
        </el-button>
      </div>

      <div class="validation-preview" v-if="lastValidationError">
        <el-alert
          :title="lastValidationError.message"
          type="error"
          show-icon
          :closable="false"
        >
          <template #default>
            <p>错误码: {{ lastValidationError.error_code }}</p>
            <p>建议: {{ lastValidationError.suggestion }}</p>
          </template>
        </el-alert>
      </div>

      <div class="failure-alert" v-if="failureInfo">
        <el-alert
          :title="'操作失败: ' + failureInfo.eventLabel"
          type="error"
          show-icon
          :closable="true"
          @close="clearFailure"
        >
          <template #default>
            <p>{{ failureInfo.message }}</p>
            <p v-if="failureInfo.suggestion" class="failure-suggestion">{{ failureInfo.suggestion }}</p>
            <div class="failure-actions">
              <el-button
                v-if="failureInfo.retryable"
                type="primary"
                size="small"
                @click="retryFailedAction"
                :loading="loading === failureInfo.event"
              >
                重试操作
              </el-button>
              <el-button
                v-if="failureInfo.rollbackAvailable"
                type="warning"
                size="small"
                @click="executeRollback"
                :loading="loading === 'rollback'"
              >
                回滚到上一状态
              </el-button>
              <el-button
                v-if="failureInfo.rollbackAuditRequired"
                type="warning"
                size="small"
                @click="showRollbackAuditDialog = true"
                :loading="loading === 'submit_rollback_audit'"
              >
                提交回滚审核申请
              </el-button>
            </div>
          </template>
        </el-alert>
      </div>
    </div>

    <div class="history-section">
      <h3>状态流转日志</h3>
      <el-timeline v-if="statusLogs.length > 0">
        <el-timeline-item
          v-for="log in statusLogs"
          :key="log.id"
          :timestamp="log.created_at"
          :type="getLogType(log.event)"
        >
          <el-card class="log-card">
            <h4>{{ log.event_label }}</h4>
            <div class="log-content">
              <p>
                <span class="label">状态变更:</span>
                <el-tag size="small">{{ log.from_status_label }}</el-tag>
                <el-icon><Right /></el-icon>
                <el-tag size="small" type="success">{{ log.to_status_label }}</el-tag>
              </p>
              <p v-if="log.message"><span class="label">结果:</span>{{ log.message }}</p>
              <p v-if="log.remark"><span class="label">备注:</span>{{ log.remark }}</p>
              <p v-if="log.operator_id"><span class="label">操作人:</span>{{ log.operator_id }}</p>
            </div>
          </el-card>
        </el-timeline-item>
      </el-timeline>
      <el-empty v-else description="暂无流转记录" />
    </div>

    <el-dialog v-model="showExceptionDialog" title="标记异常" width="500px">
      <el-form :model="exceptionForm" label-width="80px">
        <el-form-item label="异常原因" required>
          <el-input
            v-model="exceptionForm.reason"
            type="textarea"
            :rows="3"
            placeholder="请输入异常原因"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="exceptionForm.remark" placeholder="可选备注信息" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showExceptionDialog = false">取消</el-button>
        <el-button type="danger" @click="confirmMarkException" :loading="loading === 'mark_exception'">
          确认标记
        </el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showResolveDialog" title="解决异常" width="500px">
      <el-alert
        title="选择异常解决后的目标状态"
        type="info"
        show-icon
        :closable="false"
        style="margin-bottom: 20px"
      />
      <el-form :model="resolveForm" label-width="80px">
        <el-form-item label="目标状态" required>
          <el-select v-model="resolveForm.target_status" placeholder="请选择目标状态">
            <el-option
              v-for="status in statusOptions"
              :key="status.status"
              :label="status.label"
              :value="status.status"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="resolveForm.remark" placeholder="可选备注信息" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showResolveDialog = false">取消</el-button>
        <el-button type="primary" @click="confirmResolveException" :loading="loading === 'resolve_exception'">
          确认解决
        </el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showRollbackAuditDialog" title="提交回滚审核申请" width="500px">
      <el-alert
        title="该订单受回滚保护，需要审核通过后才能执行回滚操作"
        type="warning"
        show-icon
        :closable="false"
        style="margin-bottom: 20px"
      >
        <template #default>
          <p v-if="order && order.rollback_protected">原因：订单已设置回滚保护</p>
          <p v-else-if="order && order.rollback_depth > 0">原因：订单金额或状态需要审核</p>
        </template>
      </el-alert>
      <el-form :model="rollbackAuditForm" label-width="80px">
        <el-form-item label="申请原因" required>
          <el-input
            v-model="rollbackAuditForm.reason"
            type="textarea"
            :rows="3"
            placeholder="请说明申请回滚的原因"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showRollbackAuditDialog = false">取消</el-button>
        <el-button type="warning" @click="confirmSubmitRollbackAudit" :loading="loading === 'submit_rollback_audit'">
          提交申请
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox, ElNotification } from 'element-plus'
import { Right } from '@element-plus/icons-vue'

const props = defineProps({
  orderId: {
    type: Number,
    required: true,
  },
  apiBaseUrl: {
    type: String,
    default: '/backend/public/api.php',
  },
})

const emit = defineEmits(['status-changed', 'error'])

const order = ref(null)
const statusLogs = ref([])
const loading = ref('')
const showExceptionDialog = ref(false)
const showResolveDialog = ref(false)
const showRollbackAuditDialog = ref(false)
const lastValidationError = ref(null)
const consistencyCheck = ref(null)
const stateMachineConfig = ref(null)
const failureInfo = ref(null)

const exceptionForm = reactive({
  reason: '',
  remark: '',
})

const resolveForm = reactive({
  target_status: '',
  remark: '',
})

const rollbackAuditForm = reactive({
  reason: '',
})

const currentStatus = computed(() => order.value?.status || '')

const availableEvents = computed(() => order.value?.available_events || [])

const statusOptions = computed(() => {
  if (!stateMachineConfig.value?.statuses) return []
  return stateMachineConfig.value.statuses.filter(s => !s.is_terminal)
})

const stateNodes = computed(() => [
  { status: 'pending', label: '待支付', x: 80, y: 80, color: '#faad14' },
  { status: 'paid', label: '已支付', x: 220, y: 80, color: '#1890ff' },
  { status: 'shipped', label: '已发货', x: 360, y: 80, color: '#722ed1' },
  { status: 'delivered', label: '已送达', x: 500, y: 80, color: '#13c2c2' },
  { status: 'completed', label: '已完成', x: 640, y: 80, color: '#52c41a' },
  { status: 'cancelled', label: '已取消', x: 80, y: 200, color: '#8c8c8c' },
  { status: 'refunding', label: '退款中', x: 360, y: 200, color: '#eb2f96' },
  { status: 'refunded', label: '已退款', x: 640, y: 200, color: '#f5222d' },
  { status: 'exception', label: '异常', x: 360, y: 320, color: '#ff4d4f' },
])

const transitions = computed(() => [
  { from: 'pending', to: 'paid', label: '支付', path: 'M 140 80 Q 150 80 160 80', labelX: 150, labelY: 65, event: 'pay' },
  { from: 'paid', to: 'shipped', label: '发货', path: 'M 280 80 Q 290 80 300 80', labelX: 290, labelY: 65, event: 'ship' },
  { from: 'shipped', to: 'delivered', label: '确认收货', path: 'M 420 80 Q 430 80 440 80', labelX: 430, labelY: 65, event: 'confirm_receipt' },
  { from: 'delivered', to: 'completed', label: '完成', path: 'M 560 80 Q 570 80 580 80', labelX: 570, labelY: 65, event: 'complete' },
  { from: 'pending', to: 'cancelled', label: '取消', path: 'M 80 110 Q 80 140 80 170', labelX: 55, labelY: 140, event: 'cancel' },
  { from: 'paid', to: 'cancelled', label: '取消', path: 'M 220 110 Q 200 150 140 170', labelX: 180, labelY: 150, event: 'cancel' },
  { from: 'paid', to: 'refunding', label: '申请退款', path: 'M 220 110 Q 270 140 300 170', labelX: 260, labelY: 150, event: 'apply_refund' },
  { from: 'shipped', to: 'refunding', label: '申请退款', path: 'M 360 110 Q 360 140 360 170', labelX: 335, labelY: 140, event: 'apply_refund' },
  { from: 'delivered', to: 'refunding', label: '申请退款', path: 'M 500 110 Q 450 140 420 170', labelX: 460, labelY: 150, event: 'apply_refund' },
  { from: 'refunding', to: 'refunded', label: '同意退款', path: 'M 420 200 Q 500 200 580 200', labelX: 500, labelY: 185, event: 'approve_refund' },
  { from: 'refunding', to: 'paid', label: '拒绝退款', path: 'M 300 200 Q 280 170 260 120', labelX: 270, labelY: 185, event: 'reject_refund' },
])

const isActiveTransition = (transition) => {
  if (!order.value) return false
  return (
    order.value.previous_status === transition.from &&
    order.value.status === transition.to
  )
}

const isTerminalStatus = (status) => {
  return ['completed', 'cancelled', 'refunded'].includes(status)
}

const getButtonType = (event) => {
  const typeMap = {
    pay: 'success',
    ship: 'primary',
    confirm_receipt: 'primary',
    complete: 'success',
    cancel: 'info',
    apply_refund: 'warning',
    approve_refund: 'success',
    reject_refund: 'info',
  }
  return typeMap[event] || ''
}

const getLogType = (event) => {
  const typeMap = {
    pay: 'success',
    ship: 'primary',
    confirm_receipt: 'primary',
    complete: 'success',
    cancel: 'info',
    apply_refund: 'warning',
    approve_refund: 'success',
    reject_refund: 'info',
    mark_exception: 'danger',
    resolve_exception: 'success',
    rollback: 'warning',
  }
  return typeMap[event] || ''
}

const canExecute = async (event) => {
  if (!order.value) return false
  const hasEvent = order.value.available_events.some(e => e.event === event)
  if (!hasEvent) return false

  try {
    const result = await apiRequest('validate', {
      order_id: props.orderId,
      event: event,
    })
    if (result.code !== 0) {
      return false
    }
    return result.data.allowed
  } catch (e) {
    return false
  }
}

const apiRequest = async (action, params = {}) => {
  const url = `${props.apiBaseUrl}?action=${action}`
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params),
  })
  return response.json()
}

const loadOrderDetail = async () => {
  try {
    const result = await apiRequest('detail', { order_id: props.orderId })
    if (result.code === 0 && result.data) {
      order.value = result.data
      statusLogs.value = result.data.status_logs || []
      consistencyCheck.value = result.data.consistency_check
      lastValidationError.value = null
    } else {
      ElMessage.error(result.message || '加载订单失败')
    }
  } catch (e) {
    ElMessage.error('网络错误')
    emit('error', e)
  }
}

const loadStateMachineConfig = async () => {
  try {
    const result = await apiRequest('state_machine_config')
    if (result.code === 0 && result.data) {
      stateMachineConfig.value = result.data
    }
  } catch (e) {
    console.error('加载状态机配置失败', e)
  }
}

const validateBeforeAction = async (event) => {
  try {
    const result = await apiRequest('validate', {
      order_id: props.orderId,
      event: event,
    })
    if (result.code !== 0) {
      lastValidationError.value = {
        message: result.message,
        error_code: result.errors?.error_code || 'unknown',
        suggestion: result.errors?.suggestion || '',
      }
      ElMessage.error(result.message)
      return false
    }
    lastValidationError.value = null
    return true
  } catch (e) {
    ElMessage.error('校验失败')
    return false
  }
}

const getEventLabel = (event) => {
  const labelMap = {
    pay: '支付',
    ship: '发货',
    confirm_receipt: '确认收货',
    complete: '完成',
    cancel: '取消',
    apply_refund: '申请退款',
    approve_refund: '同意退款',
    reject_refund: '拒绝退款',
    mark_exception: '标记异常',
    resolve_exception: '解决异常',
    rollback: '回滚',
  }
  return labelMap[event] || event
}

const clearFailure = () => {
  failureInfo.value = null
}

const retryFailedAction = () => {
  if (failureInfo.value && failureInfo.value.event) {
    const event = failureInfo.value.event
    failureInfo.value = null
    executeAction(event)
  }
}

const executeAction = async (event) => {
  const valid = await validateBeforeAction(event)
  if (!valid) return

  loading.value = event
  failureInfo.value = null
  try {
    const result = await apiRequest('apply_event', {
      order_id: props.orderId,
      event: event,
      operator_id: 'current_user',
    })
    if (result.code === 0) {
      ElMessage.success(result.message || '操作成功')
      failureInfo.value = null
      await loadOrderDetail()
      emit('status-changed', result.data)
    } else {
      const errors = result.errors || {}
      const data = result.data || {}
      const rollbackAvailable = !!(errors.rollback_available || data.can_rollback || (order.value && order.value.can_rollback))
      const retryable = !!errors.retryable
      const rollbackAuditRequired = !!(errors.rollback_audit_required || data.rollback_audit_required)

      failureInfo.value = {
        event: event,
        eventLabel: getEventLabel(event),
        message: result.message || '操作失败',
        error_code: result.error_code || errors.error_code || 'UNKNOWN',
        suggestion: errors.suggestion || '',
        retryable: retryable,
        rollbackAvailable: rollbackAvailable,
        rollbackAuditRequired: rollbackAuditRequired,
      }

      if (rollbackAuditRequired) {
        ElNotification({
          title: '回滚需要审核',
          message: `${result.message}，请提交回滚审核申请`,
          type: 'warning',
          duration: 0,
        })
      } else if (rollbackAvailable) {
        ElNotification({
          title: '操作失败',
          message: `${result.message}，可尝试回滚到上一状态或重试操作`,
          type: 'error',
          duration: 0,
        })
      } else if (retryable) {
        ElNotification({
          title: '操作失败',
          message: `${result.message}，可点击重试按钮重新执行`,
          type: 'error',
          duration: 0,
        })
      } else {
        ElMessage.error(result.message)
      }

      if (result.errors) {
        lastValidationError.value = {
          message: result.message,
          error_code: errors.error_code || result.error_code || 'UNKNOWN',
          suggestion: errors.suggestion || '',
        }
      }
    }
  } catch (e) {
    failureInfo.value = {
      event: event,
      eventLabel: getEventLabel(event),
      message: '网络错误，请稍后重试',
      error_code: 'NETWORK_ERROR',
      suggestion: '请检查网络连接后重试操作',
      retryable: true,
      rollbackAvailable: !!(order.value && order.value.can_rollback),
    }
    ElNotification({
      title: '网络错误',
      message: '操作提交失败，请检查网络后重试',
      type: 'error',
      duration: 0,
    })
    emit('error', e)
  } finally {
    loading.value = ''
  }
}

const executeRollback = async () => {
  try {
    await ElMessageBox.confirm(
      '确定要回滚到上一个状态吗？此操作会撤销最近的一次状态变更。',
      '状态回滚确认',
      { type: 'warning', confirmButtonText: '确认回滚', cancelButtonText: '取消' }
    )
  } catch {
    return
  }

  loading.value = 'rollback'
  try {
    const result = await apiRequest('rollback', {
      order_id: props.orderId,
      operator_id: 'current_user',
    })
    if (result.code === 0) {
      ElMessage.success('回滚成功')
      failureInfo.value = null
      await loadOrderDetail()
      emit('status-changed', result.data)
    } else {
      ElMessage.error(result.message)
    }
  } catch (e) {
    ElMessage.error('回滚失败')
    emit('error', e)
  } finally {
    loading.value = ''
  }
}

const confirmMarkException = async () => {
  if (!exceptionForm.reason) {
    ElMessage.warning('请输入异常原因')
    return
  }

  loading.value = 'mark_exception'
  try {
    const result = await apiRequest('mark_exception', {
      order_id: props.orderId,
      reason: exceptionForm.reason,
      operator_id: 'current_user',
      remark: exceptionForm.remark,
    })
    if (result.code === 0) {
      ElMessage.success('异常标记成功')
      showExceptionDialog.value = false
      exceptionForm.reason = ''
      exceptionForm.remark = ''
      await loadOrderDetail()
      emit('status-changed', result.data)
    } else {
      ElMessage.error(result.message)
    }
  } catch (e) {
    ElMessage.error('标记失败')
    emit('error', e)
  } finally {
    loading.value = ''
  }
}

const confirmResolveException = async () => {
  if (!resolveForm.target_status) {
    ElMessage.warning('请选择目标状态')
    return
  }

  loading.value = 'resolve_exception'
  try {
    const result = await apiRequest('resolve_exception', {
      order_id: props.orderId,
      target_status: resolveForm.target_status,
      operator_id: 'current_user',
      remark: resolveForm.remark,
    })
    if (result.code === 0) {
      ElMessage.success('异常已解决')
      showResolveDialog.value = false
      resolveForm.target_status = ''
      resolveForm.remark = ''
      await loadOrderDetail()
      emit('status-changed', result.data)
    } else {
      ElMessage.error(result.message)
    }
  } catch (e) {
    ElMessage.error('操作失败')
    emit('error', e)
  } finally {
    loading.value = ''
  }
}

const confirmSubmitRollbackAudit = async () => {
  if (!rollbackAuditForm.reason) {
    ElMessage.warning('请输入申请原因')
    return
  }

  loading.value = 'submit_rollback_audit'
  try {
    const result = await apiRequest('submit_rollback_audit', {
      order_id: props.orderId,
      applicant_id: 'current_user',
      reason: rollbackAuditForm.reason,
    })
    if (result.code === 0) {
      ElMessage.success('回滚审核申请已提交，请等待管理员审批')
      showRollbackAuditDialog.value = false
      rollbackAuditForm.reason = ''
      failureInfo.value = null
      await loadOrderDetail()
      emit('status-changed', result.data)
    } else {
      ElMessage.error(result.message)
    }
  } catch (e) {
    ElMessage.error('提交失败')
    emit('error', e)
  } finally {
    loading.value = ''
  }
}

const resetState = () => {
  order.value = null
  statusLogs.value = []
  loading.value = ''
  showExceptionDialog.value = false
  showResolveDialog.value = false
  showRollbackAuditDialog.value = false
  lastValidationError.value = null
  consistencyCheck.value = null
  failureInfo.value = null
  exceptionForm.reason = ''
  exceptionForm.remark = ''
  resolveForm.target_status = ''
  resolveForm.remark = ''
  rollbackAuditForm.reason = ''
}

onMounted(() => {
  loadStateMachineConfig()
  loadOrderDetail()
})

watch(
  () => props.orderId,
  () => {
    resetState()
    loadOrderDetail()
  }
)

defineExpose({
  refresh: loadOrderDetail,
})
</script>

<style scoped>
.order-state-machine {
  max-width: 900px;
  margin: 0 auto;
  padding: 20px;
}

.order-header {
  margin-bottom: 24px;
}

.order-header h2 {
  margin-bottom: 12px;
}

.order-info {
  display: flex;
  align-items: center;
  gap: 16px;
}

.order-no {
  font-weight: 600;
  font-size: 16px;
}

.state-transition-diagram {
  margin-bottom: 24px;
}

.diagram-container {
  background: #fafafa;
  border-radius: 8px;
  padding: 20px;
  overflow-x: auto;
}

.state-svg {
  width: 100%;
  min-width: 700px;
  height: auto;
}

.current-state {
  filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.8; }
}

.active-transition {
  stroke: #1890ff !important;
  stroke-width: 2.5 !important;
}

.action-section {
  margin-bottom: 24px;
}

.action-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}

.validation-preview {
  margin-top: 16px;
}

.failure-alert {
  margin-top: 16px;
}

.failure-suggestion {
  color: #e6a23c;
  margin-top: 4px;
  font-weight: 500;
}

.failure-actions {
  margin-top: 12px;
  display: flex;
  gap: 8px;
}

.history-section {
  margin-bottom: 24px;
}

.log-card {
  margin-bottom: 12px;
}

.log-content {
  font-size: 14px;
}

.log-content p {
  margin: 4px 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.log-content .label {
  color: #909399;
  min-width: 60px;
}
</style>
