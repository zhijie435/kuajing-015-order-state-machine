<?php

require_once __DIR__ . '/bootstrap.php';

$testClasses = [
    'WalletBalanceTest',
    'WalletFreezeTest',
    'WalletStateMachineTest',
];

$totalPassed = 0;
$totalFailed = 0;
$totalAssertions = 0;
$failures = [];

echo "=== Running Tests ===\n\n";

foreach ($testClasses as $class) {
    $file = __DIR__ . '/' . $class . '.php';
    if (!file_exists($file)) {
        echo "SKIP: {$class} (file not found)\n";
        continue;
    }
    require_once $file;

    if (!class_exists($class)) {
        echo "SKIP: {$class} (class not found)\n";
        continue;
    }

    echo "Running {$class}...\n";
    $test = new $class();
    $result = $test->run();

    $classPassed = count($result['passed']);
    $classFailed = count($result['failed']);
    $classAssertions = array_sum($result['assertions']);

    $totalPassed += $classPassed;
    $totalFailed += $classFailed;
    $totalAssertions += $classAssertions;

    foreach ($result['passed'] as $p) {
        echo "  PASS: {$p}\n";
    }
    foreach ($result['failed'] as $f) {
        echo "  FAIL: {$f['test']}\n";
        echo "    {$f['error']}\n";
        $failures[] = $f;
    }
    echo "  -> {$classPassed} passed, {$classFailed} failed, {$classAssertions} assertions\n\n";
}

echo "=== Results ===\n";
echo "Total: {$totalPassed} passed, {$totalFailed} failed, {$totalAssertions} assertions\n";

if ($totalFailed > 0) {
    echo "\nFailed tests:\n";
    foreach ($failures as $f) {
        echo "  - {$f['test']}: {$f['error']}\n";
    }
    exit(1);
}

echo "\nAll tests passed!\n";
exit(0);
