CREATE DATABASE IF NOT EXISTS order_system DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE order_system;

DROP TABLE IF EXISTS order_status_logs;
DROP TABLE IF EXISTS orders;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(32) NOT NULL UNIQUE COMMENT '订单编号',
    user_id INT UNSIGNED NOT NULL COMMENT '用户ID',
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00 COMMENT '订单总金额',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '订单状态',
    extra_data JSON NULL COMMENT '扩展数据',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_order_no (order_no),
    INDEX idx_created_at (created_at)
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

INSERT INTO orders (order_no, user_id, total_amount, status) VALUES
('ORD2025010100001', 1001, 299.00, 'pending'),
('ORD2025010100002', 1002, 599.00, 'paid'),
('ORD2025010100003', 1003, 1299.00, 'shipped'),
('ORD2025010100004', 1001, 899.00, 'delivered'),
('ORD2025010100005', 1004, 199.00, 'exception');

INSERT INTO order_status_logs (order_id, from_status, to_status, event, message, operator_id, remark, context) VALUES
(1, 'pending', 'pending', 'create', 'Order created', 'system', 'Initial order creation', '{}'),
(2, 'pending', 'paid', 'pay', 'Payment successful', 'user:1002', 'Paid via Alipay', '{"payment_method":"alipay","amount":599.00}'),
(3, 'pending', 'paid', 'pay', 'Payment successful', 'user:1003', 'Paid via WeChat', '{"payment_method":"wechat","amount":1299.00}'),
(3, 'paid', 'shipped', 'ship', 'Order shipped', 'operator:admin01', 'Shipped via SF Express', '{"logistics_company":"SF","tracking_no":"SF1234567890"}'),
(4, 'pending', 'paid', 'pay', 'Payment successful', 'user:1001', 'Paid via credit card', '{"payment_method":"credit_card","amount":899.00}'),
(4, 'paid', 'shipped', 'ship', 'Order shipped', 'operator:admin01', 'Shipped via JD Logistics', '{"logistics_company":"JD","tracking_no":"JD9876543210"}'),
(4, 'shipped', 'delivered', 'confirm_receipt', 'Delivery confirmed', 'user:1001', 'Received in good condition', '{}'),
(5, 'pending', 'paid', 'pay', 'Payment successful', 'user:1004', 'Paid', '{}'),
(5, 'paid', 'exception', 'mark_exception', 'Order marked as exception', 'operator:admin01', 'Payment exception detected, manual review required', '{"exception_type":"payment_abnormal","details":"Duplicate payment detected"}');
