<template>
  <div class="order-list">
    <div class="list-header">
      <h2>订单列表</h2>
      <div class="filter-bar">
        <el-select v-model="filter.status" placeholder="选择状态" clearable @change="loadOrders">
          <el-option
            v-for="status in statusOptions"
            :key="status.status"
            :label="status.label"
            :value="status.status"
          >
            <div style="display: flex; align-items: center; gap: 8px">
              <el-tag :color="status.color" effect="dark" size="small">{{ status.label }}</el-tag>
            </div>
          </el-option>
        </el-select>
        <el-input
          v-model="filter.user_id"
          placeholder="用户ID"
          style="width: 150px"
          clearable
          @keyup.enter="loadOrders"
        />
        <el-button type="primary" @click="loadOrders">查询</el-button>
        <el-button type="success" @click="showCreateDialog = true">新建订单</el-button>
      </div>
    </div>

    <el-table :data="orders" v-loading="loading" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="order_no" label="订单号" width="200">
        <template #default="{ row }">
          <span class="order-no-link" @click="viewDetail(row)">{{ row.order_no }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="user_id" label="用户ID" width="100" />
      <el-table-column prop="total_amount" label="金额" width="120">
        <template #default="{ row }">
          ¥{{ row.total_amount.toFixed(2) }}
        </template>
      </el-table-column>
      <el-table-column prop="status_label" label="状态" width="120">
        <template #default="{ row }">
          <el-tag :color="row.status_color" effect="dark" size="small">
            {{ row.status_label }}
          </el-tag>
          <el-tag
            v-if="row.status === 'exception'"
            type="danger"
            size="small"
            style="margin-left: 4px"
          >
            异常
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="previous_status_label" label="上一状态" width="100" />
      <el-table-column label="可操作" width="280">
        <template #default="{ row }">
          <div class="available-events">
            <el-tag
              v-for="event in row.available_events"
              :key="event.event"
              size="small"
              class="event-tag"
            >
              {{ event.label }}
            </el-tag>
            <el-tag
              v-if="row.can_rollback"
              type="warning"
              size="small"
              class="event-tag"
            >
              可回滚({{ row.rollback_depth }})
            </el-tag>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="created_at" label="创建时间" width="180" />
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button type="primary" link @click="viewDetail(row)">查看</el-button>
          <el-button
            v-if="!isTerminalStatus(row.status)"
            type="danger"
            link
            @click="quickMarkException(row)"
          >
            标记异常
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-if="total > 0"
      v-model:current-page="pagination.page"
      v-model:page-size="pagination.page_size"
      :page-sizes="[10, 20, 50, 100]"
      :total="total"
      layout="total, sizes, prev, pager, next, jumper"
      @size-change="handleSizeChange"
      @current-change="handleCurrentChange"
      style="margin-top: 16px; justify-content: flex-end; display: flex"
    />

    <el-dialog v-model="showCreateDialog" title="新建订单" width="500px">
      <el-form :model="createForm" label-width="80px">
        <el-form-item label="用户ID" required>
          <el-input v-model.number="createForm.user_id" placeholder="请输入用户ID" />
        </el-form-item>
        <el-form-item label="订单金额" required>
          <el-input-number
            v-model.number="createForm.total_amount"
            :min="0.01"
            :precision="2"
            :step="10"
            placeholder="请输入订单金额"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input
            v-model="createForm.remark"
            type="textarea"
            :rows="2"
            placeholder="可选备注"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreateDialog = false">取消</el-button>
        <el-button type="primary" @click="confirmCreate" :loading="creating">创建</el-button>
      </template>
    </el-dialog>

    <el-drawer v-model="showDetailDrawer" title="订单详情" size="80%" @close="handleDrawerClose">
      <OrderStateMachine
        v-if="selectedOrderId"
        :key="selectedOrderId"
        :order-id="selectedOrderId"
        @status-changed="onStatusChanged"
        @error="onError"
      />
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { orderApi } from '../api/orderApi'
import OrderStateMachine from './OrderStateMachine.vue'

const emit = defineEmits(['order-created', 'status-changed'])

const orders = ref([])
const total = ref(0)
const loading = ref(false)
const creating = ref(false)
const showCreateDialog = ref(false)
const showDetailDrawer = ref(false)
const selectedOrderId = ref(null)

const filter = reactive({
  status: '',
  user_id: '',
})

const pagination = reactive({
  page: 1,
  page_size: 20,
})

const createForm = reactive({
  user_id: null,
  total_amount: null,
  remark: '',
})

const statusOptions = ref([
  { status: 'pending', label: '待支付', color: '#faad14' },
  { status: 'paid', label: '已支付', color: '#1890ff' },
  { status: 'shipped', label: '已发货', color: '#722ed1' },
  { status: 'delivered', label: '已送达', color: '#13c2c2' },
  { status: 'completed', label: '已完成', color: '#52c41a' },
  { status: 'cancelled', label: '已取消', color: '#8c8c8c' },
  { status: 'refunding', label: '退款中', color: '#eb2f96' },
  { status: 'refunded', label: '已退款', color: '#f5222d' },
  { status: 'exception', label: '异常', color: '#ff4d4f' },
])

const isTerminalStatus = (status) => {
  return ['completed', 'cancelled', 'refunded'].includes(status)
}

const loadOrders = async () => {
  loading.value = true
  try {
    const params = {
      page: pagination.page,
      page_size: pagination.page_size,
      ...(filter.status && { status: filter.status }),
      ...(filter.user_id && { user_id: filter.user_id }),
    }
    const result = await orderApi.listOrders(params)
    if (result.code === 0) {
      orders.value = result.data.items
      total.value = result.data.total
      pagination.page = result.data.page
      pagination.page_size = result.data.page_size
    } else {
      ElMessage.error(result.message || '加载失败')
    }
  } catch (e) {
    ElMessage.error('网络错误')
  } finally {
    loading.value = false
  }
}

const handleSizeChange = (size) => {
  pagination.page_size = size
  pagination.page = 1
  loadOrders()
}

const handleCurrentChange = (page) => {
  pagination.page = page
  loadOrders()
}

const handleDrawerClose = () => {
  selectedOrderId.value = null
}

const viewDetail = (row) => {
  selectedOrderId.value = row.id
  showDetailDrawer.value = true
}

const confirmCreate = async () => {
  if (!createForm.user_id || createForm.user_id <= 0) {
    ElMessage.warning('请输入有效的用户ID')
    return
  }
  if (!createForm.total_amount || createForm.total_amount <= 0) {
    ElMessage.warning('请输入有效的订单金额')
    return
  }

  creating.value = true
  try {
    const extraData = {}
    if (createForm.remark) {
      extraData.remark = createForm.remark
    }
    const result = await orderApi.createOrder({
      user_id: createForm.user_id,
      total_amount: createForm.total_amount,
      extra_data: extraData,
    })
    if (result.code === 0) {
      ElMessage.success('订单创建成功')
      showCreateDialog.value = false
      createForm.user_id = null
      createForm.total_amount = null
      createForm.remark = ''
      await loadOrders()
      emit('order-created', result.data)
    } else {
      ElMessage.error(result.message || '创建失败')
    }
  } catch (e) {
    ElMessage.error('创建失败')
  } finally {
    creating.value = false
  }
}

const quickMarkException = async (row) => {
  try {
    const { value: reason } = await ElMessageBox.prompt(
      '请输入异常原因',
      `标记订单 ${row.order_no} 为异常`,
      {
        confirmButtonText: '确认标记',
        cancelButtonText: '取消',
        inputPattern: /\S+/,
        inputErrorMessage: '异常原因不能为空',
      }
    )
    const result = await orderApi.markException(row.id, reason)
    if (result.code === 0) {
      ElMessage.success('标记成功')
      await loadOrders()
      emit('status-changed', result.data)
    } else {
      ElMessage.error(result.message || '标记失败')
    }
  } catch {
    // 用户取消
  }
}

const onStatusChanged = async (data) => {
  const previousFilterStatus = filter.status
  await loadOrders()
  
  if (selectedOrderId.value && previousFilterStatus) {
    const updatedOrder = orders.value.find(o => o.id === selectedOrderId.value)
    if (!updatedOrder) {
      showDetailDrawer.value = false
      selectedOrderId.value = null
      ElMessage.info('订单状态已变更，不再符合当前筛选条件')
    }
  }
  
  emit('status-changed', data)
}

const onError = (error) => {
  console.error('订单详情错误', error)
}

onMounted(() => {
  loadOrders()
})

defineExpose({
  refresh: loadOrders,
})
</script>

<style scoped>
.order-list {
  padding: 20px;
}

.list-header {
  margin-bottom: 16px;
}

.list-header h2 {
  margin-bottom: 16px;
}

.filter-bar {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}

.order-no-link {
  color: #409eff;
  cursor: pointer;
}

.order-no-link:hover {
  text-decoration: underline;
}

.available-events {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.event-tag {
  cursor: default;
}
</style>
