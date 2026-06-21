# 经销商钱包状态机系统 - 部署文档

## 1. 系统概述

经销商钱包状态机系统是海外仓一件代发平台的资金管理模块，负责管理经销商的账户余额、资金冻结释放、状态流转和对账审计。

### 核心功能
- 钱包状态机：正常 → 部分冻结 → 全额冻结 的状态流转管理
- 余额变更：充值、提现、消费、退款
- 冻结释放：资金冻结、部分/全额解冻、冻结资金扣除
- 对账审计：冻结释放对账、余额变更对账、异常检测与修复
- CSV导出：对账明细导出

---

## 2. 系统要求

| 组件 | 最低版本 | 说明 |
|------|----------|------|
| PHP | >= 7.4 | 需要 bcmath 扩展支持精确计算 |
| MySQL | >= 5.7 | 需要 InnoDB 引擎支持事务 |
| PHP 扩展 | pdo, pdo_mysql, json, bcmath | 必须启用 |

---

## 3. 环境变量配置

所有环境变量均在 `config/config.php` 中通过 `$_ENV` 读取，未配置时使用默认值。

### 3.1 数据库配置

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `DB_HOST` | 127.0.0.1 | 数据库主机地址 |
| `DB_PORT` | 3306 | 数据库端口 |
| `DB_NAME` | dealer_wallet | 数据库名 |
| `DB_USER` | root | 数据库用户名 |
| `DB_PASS` | (空) | 数据库密码 |

### 3.2 冻结释放配置 (wallet.freeze)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_FREEZE_MAX_SINGLE_AMOUNT` | 100000.00 | 单笔冻结最大金额 (元) |
| `WALLET_FREEZE_MAX_DAILY_AMOUNT` | 500000.00 | 单经销商每日冻结累计上限 (元) |
| `WALLET_FREEZE_MAX_COUNT_PER_DEALER` | 50 | 单经销商同时存在的冻结单数量上限 |
| `WALLET_FREEZE_DEFAULT_EXPIRE_HOURS` | 72 | 冻结单默认有效期 (小时) |
| `WALLET_FREEZE_AUTO_UNFREEZE_ENABLED` | true | 是否启用自动解冻 |
| `WALLET_FREEZE_AUTO_UNFREEZE_THRESHOLD_HOURS` | 168 | 自动解冻时间阈值，超过此时长的冻结单自动解冻 (小时，默认7天) |
| `WALLET_FREEZE_ALLOW_PARTIAL_UNFREEZE` | true | 是否允许部分解冻 |
| `WALLET_FREEZE_UNFREEZE_REQUIRES_AUDIT` | false | 解冻操作是否需要审核 |
| `WALLET_FREEZE_DEDUCT_REQUIRES_AUDIT` | false | 冻结资金扣除是否需要审核 |
| `WALLET_FREEZE_NO_PREFIX` | FRZ | 冻结单号前缀 |

### 3.3 余额变更配置 (wallet.balance)

#### 3.3.1 充值配置 (wallet.balance.recharge)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_RECHARGE_MIN_SINGLE_AMOUNT` | 0.01 | 单笔充值最小金额 |
| `WALLET_RECHARGE_MAX_SINGLE_AMOUNT` | 500000.00 | 单笔充值最大金额 |
| `WALLET_RECHARGE_MAX_DAILY_AMOUNT` | 2000000.00 | 每日充值累计上限 |
| `WALLET_RECHARGE_REQUIRES_AUDIT` | false | 充值是否需要审核 |
| `WALLET_RECHARGE_AUDIT_THRESHOLD` | 100000.00 | 充值审核阈值，超过此金额需审核 |

#### 3.3.2 提现配置 (wallet.balance.withdraw)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_WITHDRAW_MIN_SINGLE_AMOUNT` | 1.00 | 单笔提现最小金额 |
| `WALLET_WITHDRAW_MAX_SINGLE_AMOUNT` | 200000.00 | 单笔提现最大金额 |
| `WALLET_WITHDRAW_MAX_DAILY_AMOUNT` | 500000.00 | 每日提现累计上限 |
| `WALLET_WITHDRAW_DAILY_COUNT_LIMIT` | 10 | 每日提现次数上限 |
| `WALLET_WITHDRAW_REQUIRES_AUDIT` | true | 提现是否需要审核 |
| `WALLET_WITHDRAW_AUDIT_THRESHOLD` | 50000.00 | 提现审核阈值 |

#### 3.3.3 消费配置 (wallet.balance.consume)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_CONSUME_MIN_SINGLE_AMOUNT` | 0.01 | 单笔消费最小金额 |
| `WALLET_CONSUME_MAX_SINGLE_AMOUNT` | 100000.00 | 单笔消费最大金额 |
| `WALLET_CONSUME_MAX_DAILY_AMOUNT` | 1000000.00 | 每日消费累计上限 |
| `WALLET_CONSUME_ALLOW_NEGATIVE_BALANCE` | false | 是否允许余额为负（透支） |

#### 3.3.4 退款配置 (wallet.balance.refund)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_REFUND_MAX_SINGLE_AMOUNT` | 100000.00 | 单笔退款最大金额 |
| `WALLET_REFUND_REQUIRES_AUDIT` | true | 退款是否需要审核 |
| `WALLET_REFUND_AUDIT_THRESHOLD` | 10000.00 | 退款审核阈值 |
| `WALLET_REFUND_WITHIN_DAYS` | 90 | 允许退款的时间范围 (天) |

### 3.4 对账配置 (wallet.reconciliation)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_RECONCILIATION_ENABLED` | true | 是否启用对账功能 |
| `WALLET_RECONCILIATION_AUTO_HOUR` | 3 | 自动对账执行时间 (小时，0-23，默认凌晨3点) |
| `WALLET_RECONCILIATION_ALERT_ON_ERROR` | true | 对账异常时是否发送告警 |
| `WALLET_RECONCILIATION_ALERT_EMAIL` | admin@example.com | 异常告警接收邮箱 |
| `WALLET_RECONCILIATION_EXPORT_ENCODING` | UTF-8 | CSV导出编码 |
| `WALLET_RECONCILIATION_MAX_EXPORT_ROWS` | 100000 | 单次导出最大行数 |

### 3.5 状态机配置 (wallet.state_machine)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_STATE_MACHINE_STRICT_VALIDATION` | true | 是否启用严格模式（金额负数校验等） |
| `WALLET_STATE_MACHINE_ALLOW_FORCE_TRANSITION` | false | 是否允许强制状态流转（绕过校验） |
| `WALLET_STATE_MACHINE_TRANSITION_LOG_ENABLED` | true | 是否记录状态流转日志 |

### 3.6 安全配置 (wallet.security)

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `WALLET_OPERATION_PASSWORD_REQUIRED` | true | 大额操作是否需要验证支付密码 |
| `WALLET_OPERATION_PASSWORD_THRESHOLD` | 10000.00 | 支付密码验证阈值 (元) |
| `WALLET_TWO_FACTOR_REQUIRED` | false | 是否启用双因素认证 |
| `WALLET_TWO_FACTOR_THRESHOLD` | 50000.00 | 双因素认证阈值 (元) |
| `WALLET_IP_WHITELIST_ENABLED` | false | 是否启用IP白名单限制 |

---

## 4. 部署步骤

### 4.1 代码部署

```bash
# 进入项目目录
cd /path/to/project/backend

# 安装依赖 (如果使用 composer)
composer install --no-dev --optimize-autoloader

# 设置目录权限
chmod -R 755 .
chmod -R 777 storage logs  # 如有日志和缓存目录
```

### 4.2 数据库初始化

```bash
# 1. 创建数据库
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS dealer_wallet DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 导入数据表结构
mysql -u root -p dealer_wallet < sql/database.sql

# 3. 如有需要，执行数据初始化
php install.php
```

### 4.3 环境变量设置

#### 方式一：通过 Shell 环境变量

```bash
# 临时设置（当前会话有效）
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=dealer_wallet
export DB_USER=root
export DB_PASS=your_password

# 钱包冻结配置
export WALLET_FREEZE_MAX_SINGLE_AMOUNT=100000.00
export WALLET_FREEZE_AUTO_UNFREEZE_ENABLED=true

# 余额变更配置
export WALLET_RECHARGE_MAX_SINGLE_AMOUNT=500000.00
export WALLET_WITHDRAW_REQUIRES_AUDIT=true

# 对账配置
export WALLET_RECONCILIATION_ALERT_EMAIL=finance@company.com
```

#### 方式二：通过 .env 文件（需配合 vlucas/phpdotenv）

```bash
# 创建 .env 文件
cat > .env << 'EOF'
# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=dealer_wallet
DB_USER=root
DB_PASS=your_password

# Freeze
WALLET_FREEZE_MAX_SINGLE_AMOUNT=100000.00
WALLET_FREEZE_AUTO_UNFREEZE_ENABLED=true

# Balance
WALLET_RECHARGE_MAX_SINGLE_AMOUNT=500000.00
WALLET_WITHDRAW_REQUIRES_AUDIT=true

# Reconciliation
WALLET_RECONCILIATION_ALERT_EMAIL=finance@company.com
EOF
```

---

## 5. 验收命令

### 5.1 执行完整验收

```bash
cd /path/to/project/backend
chmod +x acceptance.sh
./acceptance.sh
```

### 5.2 验收项说明

验收脚本包含以下 **10 项** 检查：

| 序号 | 验收项 | 说明 |
|------|--------|------|
| 1 | 数据库连接 | 验证数据库连接配置是否正确 |
| 2 | 单元测试 | 运行全部单元测试，验证核心逻辑 |
| 3 | 钱包状态机状态流转 | 验证 NORMAL/PARTIALLY_FROZEN/FULLY_FROZEN 三态流转规则及状态自动计算 |
| 4 | 钱包充值与余额变更 | 验证充值、消费、退款、提现四种余额变更操作的正确性 |
| 5 | 资金冻结功能 | 验证部分冻结、全额冻结、超额冻结校验的正确性 |
| 6 | 资金解冻功能 | 验证部分解冻、全额解冻、状态自动流转的正确性 |
| 7 | 冻结资金扣除功能 | 验证部分扣除、全额扣除、余额与冻结同步扣减的正确性 |
| 8 | 冻结释放对账 | 验证冻结记录与交易流水的勾稽关系、CSV导出功能 |
| 9 | 余额变更对账 | 验证交易流水链完整性、异常汇总检测、CSV导出功能 |
| 10 | 钱包环境变量配置加载 | 验证冻结/余额/对账/状态机/安全五大配置节点完整性 |

### 5.3 单项验收（仅运行单元测试）

```bash
# 运行全部单元测试
php tests/run.php

# 运行指定测试类
php tests/run.php WalletFreezeTest
php tests/run.php WalletBalanceTest
php tests/run.php WalletStateMachineTest
```

### 5.4 验收输出示例

```
========================================
  经销商钱包状态机系统 - 部署验收
========================================
[1/10] 检查数据库连接... ✓ PASS
[2/10] 运行单元测试... ✓ PASS
[3/10] 测试钱包状态机状态流转... ✓ PASS
[4/10] 测试钱包充值与余额变更... ✓ PASS (充值/消费/退款/提现)
[5/10] 测试资金冻结功能... ✓ PASS (部分冻结/全额冻结/超额冻结校验)
[6/10] 测试资金解冻功能... ✓ PASS (部分解冻/全额解冻/状态自动流转)
[7/10] 测试冻结资金扣除功能... ✓ PASS (部分扣除/全额扣除/余额与冻结同步扣减)
[8/10] 测试冻结释放对账... ✓ PASS (对账通过/CSV导出)
[9/10] 测试余额变更对账... ✓ PASS (流水勾稽/异常汇总/CSV导出)
[10/10] 检查钱包环境变量配置加载... ✓ PASS (冻结/余额/对账/状态机/安全配置完整)
========================================
  验收结果: 10 通过, 0 失败 (共 10 项)
========================================
  全部验收通过，部署成功！
========================================
```

---

## 6. 钱包状态流转说明

### 状态定义

| 状态值 | 状态名称 | 说明 | 触发条件 |
|--------|----------|------|----------|
| 1 | NORMAL (正常) | 无冻结资金 | `frozen_amount = 0` |
| 2 | PARTIALLY_FROZEN (部分冻结) | 有部分资金被冻结 | `0 < frozen_amount < balance` |
| 3 | FULLY_FROZEN (全额冻结) | 全部资金被冻结 | `frozen_amount >= balance` |

### 合法状态流转

```
NORMAL ──────┐
   │          │
   ▼          ▼
PARTIALLY_FROZEN
   │          │
   ▼          ▼
FULLY_FROZEN ─┘
```

| 当前状态 | 可流转到 | 说明 |
|----------|----------|------|
| NORMAL | PARTIALLY_FROZEN, FULLY_FROZEN | 冻结操作触发 |
| PARTIALLY_FROZEN | NORMAL, FULLY_FROZEN | 解冻或继续冻结触发 |
| FULLY_FROZEN | PARTIALLY_FROZEN, NORMAL | 部分解冻或全额解冻触发 |

---

## 7. 常用运维命令

### 7.1 对账相关

```bash
# 手动触发对账（可通过封装 CLI 脚本实现）
php -r "
require 'vendor/autoload.php';
\$service = new Dealer\Wallet\Service\ReconciliationService();
\$result = \$service->reconcileFreezeRecords(1001);
print_r(\$result);
"
```

### 7.2 导出对账报告

```bash
# 导出冻结对账 CSV
php -r "
require 'vendor/autoload.php';
\$service = new Dealer\Wallet\Service\WalletService();
\$export = \$service->exportFreezeReconciliation(1001);
file_put_contents('/tmp/freeze_recon.csv', \$export['content']);
echo '导出成功: /tmp/freeze_recon.csv' . PHP_EOL;
"

# 导出余额对账 CSV
php -r "
require 'vendor/autoload.php';
\$service = new Dealer\Wallet\Service\WalletService();
\$export = \$service->exportBalanceReconciliation(1001);
file_put_contents('/tmp/balance_recon.csv', \$export['content']);
echo '导出成功: /tmp/balance_recon.csv' . PHP_EOL;
"
```

---

## 8. 故障排查

### 8.1 验收失败常见原因

| 问题 | 可能原因 | 解决方案 |
|------|----------|----------|
| 数据库连接失败 | DB_HOST/DB_PORT/DB_USER/DB_PASS 配置错误 | 检查环境变量，确认数据库服务正常运行 |
| 单元测试失败 | PHP 版本过低或缺少扩展 | 确认 PHP >= 7.4，启用 pdo, bcmath 扩展 |
| 状态流转校验失败 | 严格模式下金额数据异常 | 检查 `WALLET_STATE_MACHINE_STRICT_VALIDATION` 配置 |
| 配置加载失败 | 缺少 wallet 配置节点 | 确认 `config/config.php` 已更新为最新版本 |

### 8.2 联系支持

如遇无法解决的问题，请提供以下信息：
- `php -v` 输出
- `php -m` 输出（已安装扩展列表）
- `./acceptance.sh` 完整输出
- 相关日志文件
