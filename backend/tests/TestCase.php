<?php

abstract class TestCase
{
    protected array $passed = [];
    protected array $failed = [];
    protected array $assertions = [];
    protected string $currentTest = '';
    protected MockDatabase $db;

    public function __construct()
    {
        $this->db = MockDatabase::getInstance();
    }

    protected function setUp(): void
    {
        $this->db->clearAll();
    }

    protected function tearDown(): void
    {
        $this->db->clearAll();
    }

    public function run(): array
    {
        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($m) => str_starts_with($m, 'test'));

        foreach ($testMethods as $method) {
            $this->currentTest = $method;
            $this->setUp();
            try {
                $this->$method();
                $this->passed[] = $method;
                $this->assertions[$method] = $this->assertions[$method] ?? 0;
            } catch (\Throwable $e) {
                $this->failed[] = [
                    'test' => $method,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
            $this->tearDown();
        }

        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'assertions' => $this->assertions,
            'class' => static::class,
        ];
    }

    protected function assertSame($expected, $actual, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if ($expected !== $actual) {
            $msg = $message ?: "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
            throw new \Exception($msg);
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if ($expected != $actual) {
            $msg = $message ?: "Expected equals " . var_export($expected, true) . " but got " . var_export($actual, true);
            throw new \Exception($msg);
        }
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if (!$condition) {
            throw new \Exception($message ?: 'Expected true but got false');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if ($condition) {
            throw new \Exception($message ?: 'Expected false but got true');
        }
    }

    protected function assertNull($actual, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if ($actual !== null) {
            throw new \Exception($message ?: 'Expected null');
        }
    }

    protected function assertNotNull($actual, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if ($actual === null) {
            throw new \Exception($message ?: 'Expected not null');
        }
    }

    protected function assertNotEmpty($actual, string $message = ''): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
        if (empty($actual)) {
            throw new \Exception($message ?: 'Expected not empty');
        }
    }

    protected function expectException(string $class): void
    {
        $this->assertions[$this->currentTest] = ($this->assertions[$this->currentTest] ?? 0) + 1;
    }
}
