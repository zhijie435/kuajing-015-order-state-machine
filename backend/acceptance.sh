#!/bin/bash

echo "========================================"
echo "  经销商钱包状态机系统 - 部署验收"
echo "========================================"

PASS=0
FAIL=0
TOTAL=8

# 测试1: PHP 版本检查
echo -n "[1/${TOTAL}] 检查 PHP 版本... "
php -r "
\$version = phpversion();
\$ok = version_compare(\$version, '7.4.0', '>=');
echo \$ok ? \"✓ PASS (PHP {\$version})\" : \"✗ FAIL: PHP {\$version}，需要 >= 7.4.0\";
exit(\$ok ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试2: PHP 扩展检查
echo -n "[2/${TOTAL}] 检查 PHP 扩展... "
php -r "
\$required = ['pdo', 'pdo_mysql', 'json', 'bcmath'];
\$missing = [];
foreach (\$required as \$ext) {
    if (!extension_loaded(\$ext)) {
        \$missing[] = \$ext;
    }
}
if (empty(\$missing)) {
    echo '✓ PASS (' . implode(', ', \$required) . ')';
    exit(0);
} else {
    echo '✗ FAIL: 缺少扩展 ' . implode(', ', \$missing);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试3: 配置文件加载检查
echo -n "[3/${TOTAL}] 检查配置文件加载... "
php -r "
\$config = require 'config/config.php';
if (!is_array(\$config)) {
    echo '✗ FAIL: config.php 未返回数组';
    exit(1);
}
\$required = ['db', 'state_machine', 'wallet', 'security'];
\$missing = [];
foreach (\$required as \$k) {
    if (!isset(\$config[\$k])) \$missing[] = \$k;
}
if (empty(\$missing)) {
    echo '✓ PASS (db/state_machine/wallet/security 配置完整)';
    exit(0);
} else {
    echo '✗ FAIL: 缺少配置节点: ' . implode(', ', \$missing);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试4: 冻结释放配置检查
echo -n "[4/${TOTAL}] 检查冻结释放配置... "
php -r "
\$config = require 'config/config.php';
\$errors = [];
if (!isset(\$config['wallet']['freeze'])) {
    \$errors[] = '缺少 wallet.freeze 配置';
} else {
    \$freeze = \$config['wallet']['freeze'];
    \$requiredKeys = ['max_single_amount', 'max_daily_amount', 'max_count_per_dealer', 'default_expire_hours', 'auto_unfreeze_enabled', 'allow_partial_unfreeze', 'unfreeze_requires_audit', 'deduct_requires_audit', 'no_prefix'];
    foreach (\$requiredKeys as \$k) {
        if (!isset(\$freeze[\$k])) \$errors[] = '缺少 wallet.freeze.' . \$k;
    }
    if (isset(\$freeze['max_single_amount']) && !is_float(\$freeze['max_single_amount'])) \$errors[] = 'wallet.freeze.max_single_amount 应为 float';
    if (isset(\$freeze['auto_unfreeze_enabled']) && !is_bool(\$freeze['auto_unfreeze_enabled'])) \$errors[] = 'wallet.freeze.auto_unfreeze_enabled 应为 bool';
}
if (empty(\$errors)) {
    echo '✓ PASS (单笔限额/自动解冻/部分解冻/审核配置完整)';
    exit(0);
} else {
    echo '✗ FAIL: ' . implode('; ', \$errors);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试5: 余额变更配置检查
echo -n "[5/${TOTAL}] 检查余额变更配置... "
php -r "
\$config = require 'config/config.php';
\$errors = [];
if (!isset(\$config['wallet']['balance'])) {
    \$errors[] = '缺少 wallet.balance 配置';
} else {
    \$balance = \$config['wallet']['balance'];
    \$requiredTypes = ['recharge', 'withdraw', 'consume', 'refund'];
    foreach (\$requiredTypes as \$type) {
        if (!isset(\$balance[\$type])) {
            \$errors[] = '缺少 wallet.balance.' . \$type;
        }
    }
    if (isset(\$balance['recharge'])) {
        if (!isset(\$balance['recharge']['max_single_amount'])) \$errors[] = '缺少充值单笔限额';
        if (!isset(\$balance['recharge']['requires_audit'])) \$errors[] = '缺少充值审核开关';
    }
    if (isset(\$balance['withdraw'])) {
        if (!isset(\$balance['withdraw']['max_single_amount'])) \$errors[] = '缺少提现单笔限额';
        if (!isset(\$balance['withdraw']['daily_count_limit'])) \$errors[] = '缺少提现每日次数限制';
        if (!isset(\$balance['withdraw']['requires_audit'])) \$errors[] = '缺少提现审核开关';
    }
    if (isset(\$balance['consume'])) {
        if (!isset(\$balance['consume']['allow_negative_balance'])) \$errors[] = '缺少消费透支开关';
    }
    if (isset(\$balance['refund'])) {
        if (!isset(\$balance['refund']['refund_within_days'])) \$errors[] = '缺少退款时效配置';
        if (!isset(\$balance['refund']['requires_audit'])) \$errors[] = '缺少退款审核开关';
    }
}
if (empty(\$errors)) {
    echo '✓ PASS (充值/提现/消费/退款配置完整)';
    exit(0);
} else {
    echo '✗ FAIL: ' . implode('; ', \$errors);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试6: 对账与状态机配置检查
echo -n "[6/${TOTAL}] 检查对账与状态机配置... "
php -r "
\$config = require 'config/config.php';
\$errors = [];
if (!isset(\$config['wallet']['reconciliation'])) {
    \$errors[] = '缺少 wallet.reconciliation 对账配置';
} else {
    \$rec = \$config['wallet']['reconciliation'];
    if (!isset(\$rec['enabled'])) \$errors[] = '缺少对账开关';
    if (!isset(\$rec['auto_reconcile_hour'])) \$errors[] = '缺少自动对账时间';
    if (!isset(\$rec['alert_on_error'])) \$errors[] = '缺少异常告警开关';
    if (!isset(\$rec['alert_email'])) \$errors[] = '缺少告警邮箱';
}
if (!isset(\$config['wallet']['state_machine'])) {
    \$errors[] = '缺少 wallet.state_machine 状态机配置';
} else {
    \$sm = \$config['wallet']['state_machine'];
    if (!isset(\$sm['strict_validation'])) \$errors[] = '缺少严格模式开关';
    if (!isset(\$sm['allow_force_transition'])) \$errors[] = '缺少强制流转开关';
    if (!isset(\$sm['transition_log_enabled'])) \$errors[] = '缺少流转日志开关';
}
if (empty(\$errors)) {
    echo '✓ PASS (对账开关/告警/状态机严格模式配置完整)';
    exit(0);
} else {
    echo '✗ FAIL: ' . implode('; ', \$errors);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试7: 状态枚举类检查
echo -n "[7/${TOTAL}] 检查状态枚举类... "
php -r "
require_once 'src/Enums/OrderStatus.php';
require_once 'src/Enums/OrderEvent.php';
\$errors = [];
if (!class_exists('Order\\\\Enums\\\\OrderStatus')) {
    \$errors[] = 'OrderStatus 枚举类不存在';
} else {
    \$statusClass = 'Order\\\\Enums\\\\OrderStatus';
    if (!defined(\$statusClass . '::PENDING')) \$errors[] = '缺少 PENDING 状态常量';
    if (!defined(\$statusClass . '::PAID')) \$errors[] = '缺少 PAID 状态常量';
    if (!defined(\$statusClass . '::COMPLETED')) \$errors[] = '缺少 COMPLETED 状态常量';
    if (!defined(\$statusClass . '::CANCELLED')) \$errors[] = '缺少 CANCELLED 状态常量';
    \$labels = \$statusClass::LABELS;
    if (empty(\$labels)) \$errors[] = '状态标签映射 LABELS 为空';
}
if (!class_exists('Order\\\\Enums\\\\OrderEvent')) {
    \$errors[] = 'OrderEvent 枚举类不存在';
}
if (empty(\$errors)) {
    echo '✓ PASS (OrderStatus/OrderEvent 枚举完整)';
    exit(0);
} else {
    echo '✗ FAIL: ' . implode('; ', \$errors);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试8: Composer 依赖配置检查
echo -n "[8/${TOTAL}] 检查 Composer 配置... "
php -r "
\$composer = json_decode(file_get_contents('composer.json'), true);
\$errors = [];
if (!is_array(\$composer)) {
    \$errors[] = 'composer.json 格式错误';
} else {
    if (!isset(\$composer['require']['php'])) \$errors[] = '缺少 PHP 版本约束';
    if (!isset(\$composer['require']['ext-pdo'])) \$errors[] = '缺少 ext-pdo 依赖';
    if (!isset(\$composer['autoload']['psr-4'])) \$errors[] = '缺少 PSR-4 自动加载配置';
}
if (empty(\$errors)) {
    echo '✓ PASS (PHP版本/扩展/自动加载配置完整)';
    exit(0);
} else {
    echo '✗ FAIL: ' . implode('; ', \$errors);
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

echo "========================================"
echo "  验收结果: $PASS 通过, $FAIL 失败 (共 $TOTAL 项)"
echo "========================================"
if [ $FAIL -gt 0 ]; then
    echo "  失败项请检查后重新验收"
else
    echo "  全部验收通过，部署成功！"
fi
echo "========================================"
echo ""
echo "验收说明："
echo "  [1] PHP >= 7.4 + bcmath 精确计算扩展"
echo "  [2] PDO/JSON 等必需扩展"
echo "  [3] config.php 四大配置节点完整性"
echo "  [4] 钱包冻结释放：单笔限额/自动解冻/审核开关"
echo "  [5] 余额变更：充值/提现/消费/退款 配置"
echo "  [6] 对账：自动对账时间/异常告警；状态机：严格模式"
echo "  [7] 订单状态枚举与事件枚举"
echo "  [8] Composer 依赖与自动加载配置"
echo ""
exit $FAIL
