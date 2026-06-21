CREATE DATABASE IF NOT EXISTS order_system DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE order_system;

DROP TABLE IF EXISTS order_audit_records;
DROP TABLE IF EXISTS order_rollback_protections;
DROP TABLE IF EXISTS order_writeback_logs;
DROP TABLE IF EXISTS order_status_logs;
DROP TABLE IF EXISTS orders;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(32) NOT NULL UNIQUE COMMENT '订单编号',
    user_id INT UNSIGNED NOT NULL COMMENT '用户ID',
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00 COMMENT '订单总金额',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '订单状态',
    audit_status VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT '审核状态: none/pending/approved/rejected',
    exception_type VARCHAR(50) NULL COMMENT '异常类型: payment_abnormal/shipping_abnormal/system_abnormal/manual_handling/other',
    exception_level TINYINT NOT NULL DEFAULT 0 COMMENT '异常等级: 0-无异常 1-低 2-中 3-高',
    rollback_protected TINYINT(1) NOT NULL DEFAULT 0 COMMENT '回滚保护标志: 0-未保护 1-已保护',
    rollback_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回滚次数',
    writeback_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '数据回写状态: pending/success/failed/partial',
    last_writeback_at DATETIME NULL COMMENT '最后回写时间',
    extra_data JSON NULL COMMENT '扩展数据',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_order_no (order_no),
    INDEX idx_created_at (created_at),
    INDEX idx_audit_status (audit_status),
    INDEX idx_exception_type (exception_type),
    INDEX idx_exception_level (exception_level),
    INDEX idx_rollback_protected (rollback_protected),
    INDEX idx_writeback_status (writeback_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表';

CREATE TABLE order_status_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL COMMENT '订单ID',
    from_status VARCHAR(20) NOT NULL COMMENT '原状态',
    to_status VARCHAR(20) NOT NULL COMMENT '目标状态',
    event VARCHAR(32) NOT NULL COMMENT '触发事件',
    message VARCHAR(500) NULL COMMENT '结果消息',
    operator_id VARCHAR(64) NULL COMMENT '操作人ID',
    remark VARCHAR(500) NULL COMMENT '备注',
    context JSON NULL COMMENT '上下文数据',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
    INDEX idx_order_id (order_id),
    INDEX idx_event (event),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单状态流转日志表';

CREATE TABLE order_audit_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL COMMENT '订单ID',
    audit_type VARCHAR(32) NOT NULL COMMENT '审核类型: status_change/rollback/exception_resolve/writeback',
    action VARCHAR(32) NOT NULL COMMENT '审核动作: approve/reject/submit/cancel',
    before_status VARCHAR(20) NULL COMMENT '审核前状态',
    after_status VARCHAR(20) NULL COMMENT '审核后状态',
    applicant_id VARCHAR(64) NULL COMMENT '申请人ID',
    auditor_id VARCHAR(64) NULL COMMENT '审核人ID',
    audit_remark VARCHAR(500) NULL COMMENT '审核备注',
    reason VARCHAR(500) NULL COMMENT '申请原因',
    context JSON NULL COMMENT '上下文数据',
    audit_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '审核状态: pending/approved/rejected/cancelled',
    submitted_at DATETIME NULL COMMENT '提交时间',
    audited_at DATETIME NULL COMMENT '审核时间',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX idx_order_id (order_id),
    INDEX idx_audit_type (audit_type),
    INDEX idx_audit_status (audit_status),
    INDEX idx_applicant_id (applicant_id),
    INDEX idx_auditor_id (auditor_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单审核记录表';

CREATE TABLE order_rollback_protections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL COMMENT '订单ID',
    protection_type VARCHAR(32) NOT NULL COMMENT '保护类型: amount_threshold/time_window/terminal_status/manual_protect',
    protected_by VARCHAR(64) NULL COMMENT '设置保护的操作人ID',
    protection_reason VARCHAR(500) NULL COMMENT '保护原因',
    threshold_amount DECIMAL(12, 2) NULL COMMENT '金额阈值',
    protect_until DATETIME NULL COMMENT '保护截止时间',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否生效: 0-失效 1-生效',
    context JSON NULL COMMENT '上下文数据',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX idx_order_id (order_id),
    INDEX idx_protection_type (protection_type),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单回滚保护表';

CREATE TABLE order_writeback_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL COMMENT '订单ID',
    target_system VARCHAR(64) NOT NULL COMMENT '目标系统: erp/wms/crm/finance/other',
    writeback_type VARCHAR(32) NOT NULL COMMENT '回写类型: status_create/status_update/payment/refund/shipment',
    writeback_data JSON NULL COMMENT '回写数据',
    writeback_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '回写状态: pending/success/failed/retrying',
    retry_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '重试次数',
    max_retry_count INT UNSIGNED NOT NULL DEFAULT 3 COMMENT '最大重试次数',
    error_message VARCHAR(1000) NULL COMMENT '错误信息',
    operator_id VARCHAR(64) NULL COMMENT '操作人ID',
    last_attempt_at DATETIME NULL COMMENT '最后尝试时间',
    completed_at DATETIME NULL COMMENT '完成时间',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX idx_order_id (order_id),
    INDEX idx_target_system (target_system),
    INDEX idx_writeback_type (writeback_type),
    INDEX idx_writeback_status (writeback_status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单数据回写日志表';

INSERT INTO orders (order_no, user_id, total_amount, status, audit_status, exception_type, exception_level, rollback_protected, rollback_count, writeback_status) VALUES
('ORD2025010100001', 1001, 299.00, 'pending', 'none', NULL, 0, 0, 0, 'success'),
('ORD2025010100002', 1002, 599.00, 'paid', 'approved', NULL, 0, 0, 0, 'success'),
('ORD2025010100003', 1003, 1299.00, 'shipped', 'approved', NULL, 0, 1, 0, 'success'),
('ORD2025010100004', 1001, 899.00, 'delivered', 'approved', NULL, 0, 1, 0, 'success'),
('ORD2025010100005', 1004, 199.00, 'exception', 'pending', 'payment_abnormal', 2, 0, 0, 'failed'),
('ORD2025010100006', 1005, 15999.00, 'paid', 'pending', NULL, 0, 1, 0, 'pending'),
('ORD2025010100007', 1006, 2999.00, 'exception', 'pending', 'shipping_abnormal', 3, 0, 1, 'failed');

INSERT INTO order_status_logs (order_id, from_status, to_status, event, message, operator_id, remark, context) VALUES
(1, 'pending', 'pending', 'create', 'Order created', 'system', 'Initial order creation', '{}'),
(2, 'pending', 'paid', 'pay', 'Payment successful', 'user:1002', 'Paid via Alipay', '{"payment_method":"alipay","amount":599.00}'),
(3, 'pending', 'paid', 'pay', 'Payment successful', 'user:1003', 'Paid via WeChat', '{"payment_method":"wechat","amount":1299.00}'),
(3, 'paid', 'shipped', 'ship', 'Order shipped', 'operator:admin01', 'Shipped via SF Express', '{"logistics_company":"SF","tracking_no":"SF1234567890"}'),
(4, 'pending', 'paid', 'pay', 'Payment successful', 'user:1001', 'Paid via credit card', '{"payment_method":"credit_card","amount":899.00}'),
(4, 'paid', 'shipped', 'ship', 'Order shipped', 'operator:admin01', 'Shipped via JD Logistics', '{"logistics_company":"JD","tracking_no":"JD9876543210"}'),
(4, 'shipped', 'delivered', 'confirm_receipt', 'Delivery confirmed', 'user:1001', 'Received in good condition', '{}'),
(5, 'pending', 'paid', 'pay', 'Payment successful', 'user:1004', 'Paid', '{}'),
(5, 'paid', 'exception', 'mark_exception', 'Order marked as exception', 'operator:admin01', 'Payment exception detected, manual review required', '{"exception_type":"payment_abnormal","details":"Duplicate payment detected"}'),
(6, 'pending', 'paid', 'pay', 'Payment successful', 'user:1005', 'Paid via bank transfer', '{"payment_method":"bank_transfer","amount":15999.00}'),
(7, 'pending', 'paid', 'pay', 'Payment successful', 'user:1006', 'Paid', '{}'),
(7, 'paid', 'shipped', 'ship', 'Order shipped', 'operator:admin01', 'Shipped', '{}'),
(7, 'shipped', 'paid', 'rollback', 'Rolled back from shipped to paid', 'operator:admin02', 'Rollback: Wrong shipping address', '{}'),
(7, 'paid', 'exception', 'mark_exception', 'Order marked as exception', 'operator:admin01', 'Shipping address invalid, manual review required', '{"exception_type":"shipping_abnormal","details":"Invalid shipping address"}');

INSERT INTO order_audit_records (order_id, audit_type, action, before_status, after_status, applicant_id, auditor_id, audit_remark, reason, context, audit_status, submitted_at, audited_at) VALUES
(2, 'status_change', 'approve', 'pending', 'paid', 'user:1002', 'operator:admin01', 'Payment verified', 'Normal payment process', '{"payment_method":"alipay"}', 'approved', '2025-01-01 10:05:00', '2025-01-01 10:06:00'),
(3, 'status_change', 'approve', 'paid', 'shipped', 'operator:admin01', 'operator:admin02', 'Inventory confirmed', 'Ready to ship', '{"logistics_company":"SF"}', 'approved', '2025-01-01 11:00:00', '2025-01-01 11:05:00'),
(5, 'exception_resolve', 'submit', 'exception', NULL, 'operator:admin01', NULL, NULL, 'Duplicate payment detected, need manual review', '{"exception_type":"payment_abnormal"}', 'pending', '2025-01-01 14:30:00', NULL),
(6, 'rollback', 'submit', 'paid', NULL, 'operator:admin03', NULL, NULL, 'Large amount order, need review before rollback', '{"amount":15999.00}', 'pending', '2025-01-01 15:00:00', NULL),
(7, 'rollback', 'approve', 'shipped', 'paid', 'operator:admin01', 'operator:admin02', 'Wrong address confirmed', 'Shipping address invalid', '{}', 'approved', '2025-01-01 16:00:00', '2025-01-01 16:10:00');

INSERT INTO order_rollback_protections (order_id, protection_type, protected_by, protection_reason, threshold_amount, protect_until, is_active, context) VALUES
(3, 'amount_threshold', 'system', 'Order amount exceeds threshold', 1000.00, NULL, 1, '{"threshold":1000.00}'),
(4, 'terminal_status', 'system', 'Delivered order protection', NULL, NULL, 1, '{}'),
(6, 'amount_threshold', 'system', 'Large amount order protection', 10000.00, '2025-01-08 00:00:00', 1, '{"threshold":10000.00}');

INSERT INTO order_writeback_logs (order_id, target_system, writeback_type, writeback_data, writeback_status, retry_count, max_retry_count, error_message, operator_id, last_attempt_at, completed_at) VALUES
(1, 'erp', 'status_create', '{"order_no":"ORD2025010100001","status":"pending"}', 'success', 0, 3, NULL, 'system', '2025-01-01 10:00:00', '2025-01-01 10:00:01'),
(2, 'erp', 'status_update', '{"order_no":"ORD2025010100002","status":"paid"}', 'success', 0, 3, NULL, 'system', '2025-01-01 10:05:00', '2025-01-01 10:05:02'),
(2, 'finance', 'payment', '{"order_no":"ORD2025010100002","amount":599.00}', 'success', 0, 3, NULL, 'system', '2025-01-01 10:05:00', '2025-01-01 10:05:03'),
(5, 'erp', 'status_update', '{"order_no":"ORD2025010100005","status":"exception"}', 'failed', 2, 3, 'ERP system timeout', 'system', '2025-01-01 14:35:00', NULL),
(5, 'finance', 'payment', '{"order_no":"ORD2025010100005","amount":199.00,"exception":true}', 'failed', 1, 3, 'Finance API error: duplicate transaction', 'system', '2025-01-01 14:32:00', NULL),
(6, 'erp', 'status_update', '{"order_no":"ORD2025010100006","status":"paid"}', 'pending', 0, 3, NULL, 'system', NULL, NULL),
(7, 'wms', 'shipment', '{"order_no":"ORD2025010100007","action":"cancel_shipment"}', 'failed', 3, 3, 'WMS order already processed', 'operator:admin02', '2025-01-01 16:15:00', NULL);
