<?php

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config/config.php';

use Order\Core\StateMachine;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;

echo "=== 订单状态机演示 ===\n\n";

$stateMachine = new StateMachine(OrderStatus::PENDING, [
    'strict_validation' => true,
    'allow_force_transition' => false,
    'transition_log_enabled' => true,
    'rollback_enabled' => true,
    'max_rollback_depth' => 3,
]);

echo "初始状态: " . OrderStatus::getLabel($stateMachine->getCurrentStatus()) . "\n\n";

echo "--- 正常流转测试 ---\n";
echo "当前可执行操作: " . implode(', ', $stateMachine->getAvailableEvents()) . "\n";

$events = [
    OrderEvent::PAY,
    OrderEvent::SHIP,
    OrderEvent::CONFIRM_RECEIPT,
    OrderEvent::COMPLETE,
];

foreach ($events as $event) {
    $canResult = $stateMachine->checkCan($event);
    echo "\n检查操作 " . OrderEvent::getLabel($event) . ": ";
    if ($canResult['allowed']) {
        echo "✅ 允许\n";
        $result = $stateMachine->apply($event);
        echo "  流转成功: " . OrderStatus::getLabel($result->getFromStatus()) . " → " . OrderStatus::getLabel($result->getToStatus()) . "\n";
        echo "  当前状态: " . OrderStatus::getLabel($stateMachine->getCurrentStatus()) . "\n";
    } else {
        echo "❌ 不允许\n";
        echo "  错误: " . $canResult['error_message'] . "\n";
        echo "  建议: " . $canResult['suggestion'] . "\n";
    }
}

echo "\n--- 回滚测试 ---\n";
$stateMachine2 = new StateMachine(OrderStatus::PENDING);
echo "初始状态: " . OrderStatus::getLabel($stateMachine2->getCurrentStatus()) . "\n";

$stateMachine2->apply(OrderEvent::PAY);
echo "支付后状态: " . OrderStatus::getLabel($stateMachine2->getCurrentStatus()) . "\n";

$stateMachine2->apply(OrderEvent::SHIP);
echo "发货后状态: " . OrderStatus::getLabel($stateMachine2->getCurrentStatus()) . "\n";

echo "回滚栈深度: " . count($stateMachine2->getRollbackStack()) . "\n";

if ($stateMachine2->can(OrderEvent::ROLLBACK)) {
    $result = $stateMachine2->rollback();
    echo "回滚成功: " . OrderStatus::getLabel($result->getFromStatus()) . " → " . OrderStatus::getLabel($result->getToStatus()) . "\n";
    echo "当前状态: " . OrderStatus::getLabel($stateMachine2->getCurrentStatus()) . "\n";
}

echo "\n--- 异常状态测试 ---\n";
$stateMachine3 = new StateMachine(OrderStatus::PAID);
echo "初始状态: " . OrderStatus::getLabel($stateMachine3->getCurrentStatus()) . "\n";

$result = $stateMachine3->apply(OrderEvent::MARK_EXCEPTION, null, 'admin', '支付异常');
echo "标记异常后状态: " . OrderStatus::getLabel($stateMachine3->getCurrentStatus()) . "\n";
echo "异常原因: " . $stateMachine3->getExceptionReason() . "\n";

echo "当前可执行操作: " . implode(', ', $stateMachine3->getAvailableEvents()) . "\n";

$canResult = $stateMachine3->checkCan(OrderEvent::SHIP);
echo "\n尝试发货操作: ";
if (!$canResult['allowed']) {
    echo "❌ 不允许\n";
    echo "  错误: " . $canResult['error_message'] . "\n";
}

$result = $stateMachine3->resolveException(OrderStatus::PAID);
echo "解决异常后状态: " . OrderStatus::getLabel($stateMachine3->getCurrentStatus()) . "\n";

echo "\n--- 错误提示测试 ---\n";
$stateMachine4 = new StateMachine(OrderStatus::COMPLETED);
echo "初始状态: " . OrderStatus::getLabel($stateMachine4->getCurrentStatus()) . "\n";

$canResult = $stateMachine4->checkCan(OrderEvent::SHIP);
echo "尝试发货操作: ";
if (!$canResult['allowed']) {
    echo "❌ 不允许\n";
    echo "  错误码: " . $canResult['error_code'] . "\n";
    echo "  错误信息: " . $canResult['error_message'] . "\n";
    echo "  建议: " . $canResult['suggestion'] . "\n";
}

echo "\n=== 所有测试完成！\n";
