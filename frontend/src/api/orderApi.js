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

  async listExceptionOrders(params = {}) {
    return this.request('list_exception', params)
  },

  async listPendingAuditOrders(params = {}) {
    return this.request('list_pending_audit', params)
  },

  async listRollbackProtectedOrders(params = {}) {
    return this.request('list_rollback_protected', params)
  },

  async listWritebackFailedOrders(params = {}) {
    return this.request('list_writeback_failed', params)
  },

  async getOrderDetailFull(orderId) {
    return this.request('detail_full', { order_id: orderId })
  },

  async submitRollbackAudit(orderId, reason, context = {}) {
    return this.request('submit_rollback_audit', {
      order_id: orderId,
      applicant_id: 'current_user',
      reason,
      context,
    })
  },

  async approveRollback(orderId, auditRemark = '', remark = '') {
    return this.request('approve_rollback', {
      order_id: orderId,
      auditor_id: 'current_user',
      audit_remark: auditRemark,
      remark,
    })
  },

  async rejectRollback(orderId, auditRemark) {
    return this.request('reject_rollback', {
      order_id: orderId,
      auditor_id: 'current_user',
      audit_remark: auditRemark,
    })
  },

  async setRollbackProtection(orderId, protectionType, protectionReason, options = {}) {
    return this.request('set_rollback_protection', {
      order_id: orderId,
      protection_type: protectionType,
      protected_by: 'current_user',
      protection_reason: protectionReason,
      ...options,
    })
  },

  async removeRollbackProtection(orderId) {
    return this.request('remove_rollback_protection', {
      order_id: orderId,
      operator_id: 'current_user',
    })
  },

  async getRollbackProtections(orderId) {
    return this.request('get_rollback_protections', { order_id: orderId })
  },

  async getAuditRecords(orderId) {
    return this.request('get_audit_records', { order_id: orderId })
  },

  async getAuditList(params = {}) {
    return this.request('get_audit_list', params)
  },

  async getWritebackLogs(orderId) {
    return this.request('get_writeback_logs', { order_id: orderId })
  },

  async retryWriteback(logId) {
    return this.request('retry_writeback', {
      log_id: logId,
      operator_id: 'current_user',
    })
  },

  async getExceptionStatistics() {
    return this.request('exception_statistics')
  },

  async getAuditStatistics() {
    return this.request('audit_statistics')
  },

  async getWritebackStatistics() {
    return this.request('writeback_statistics')
  },
}
