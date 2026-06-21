const API_BASE_URL = '/backend/public/api.php'

export const orderApi = {
  async request(action, params = {}) {
    const url = `${API_BASE_URL}?action=${action}`
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(params),
    })
    const data = await response.json()
    return data
  },

  async createOrder(params) {
    return this.request('create', params)
  },

  async listOrders(params = {}) {
    return this.request('list', params)
  },

  async getOrderDetail(orderId) {
    return this.request('detail', { order_id: orderId })
  },

  async validateEvent(orderId, event) {
    return this.request('validate', { order_id: orderId, event })
  },

  async applyEvent(orderId, event, remark = '') {
    return this.request('apply_event', {
      order_id: orderId,
      event,
      operator_id: 'current_user',
      remark,
    })
  },

  async pay(orderId, remark = '') {
    return this.request('pay', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async ship(orderId, remark = '') {
    return this.request('ship', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async confirmReceipt(orderId, remark = '') {
    return this.request('confirm_receipt', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async complete(orderId, remark = '') {
    return this.request('complete', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async cancel(orderId, remark = '') {
    return this.request('cancel', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async applyRefund(orderId, remark = '') {
    return this.request('apply_refund', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async approveRefund(orderId, remark = '') {
    return this.request('approve_refund', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async rejectRefund(orderId, remark = '') {
    return this.request('reject_refund', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async markException(orderId, reason, remark = '') {
    return this.request('mark_exception', {
      order_id: orderId,
      reason,
      operator_id: 'current_user',
      remark,
    })
  },

  async resolveException(orderId, targetStatus, remark = '') {
    return this.request('resolve_exception', {
      order_id: orderId,
      target_status: targetStatus,
      operator_id: 'current_user',
      remark,
    })
  },

  async rollback(orderId, remark = '') {
    return this.request('rollback', { order_id: orderId, operator_id: 'current_user', remark })
  },

  async getStatusLogs(orderId) {
    return this.request('status_logs', { order_id: orderId })
  },

  async getStateMachineConfig() {
    return this.request('state_machine_config')
  },

  async checkConsistency(orderId) {
    return this.request('check_consistency', { order_id: orderId })
  },
}
