<?php

namespace Dealer\Wallet\Service;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\WalletPermissionException;
use Dealer\Wallet\Exception\WalletStateException;
use Dealer\Wallet\Model\Wallet;
use Dealer\Wallet\Model\FreezeRecord;
use Dealer\Wallet\Repository\FreezeRecordRepository;
use Dealer\Wallet\Repository\TransactionRepository;
use Dealer\Wallet\Repository\WalletRepository;
use Dealer\Wallet\StateMachine\WalletStateMachine;
use PermissionService;

class WalletService
{
    private WalletRepository $walletRepository;
    private TransactionRepository $transactionRepository;
    private FreezeRecordRepository $freezeRecordRepository;
    private ReconciliationService $reconciliationService;
    private PermissionService $permissionService;
    /** @var PDO|MockDatabase */
    private $pdo;

    public function __construct()
    {
        $this->walletRepository = new WalletRepository();
        $this->transactionRepository = new TransactionRepository();
        $this->freezeRecordRepository = new FreezeRecordRepository();
        $this->reconciliationService = new ReconciliationService();
        $this->permissionService = new PermissionService();
        $this->pdo = Database::getConnection();
    }

    public function getPermissionService(): PermissionService
    {
        return $this->permissionService;
    }

    public function getWallet(int $dealerId): array
    {
        $this->assertCanViewWallet($dealerId);
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            throw new WalletException("钱包不存在：经销商ID【{$dealerId}】，请先为该经销商创建钱包。");
        }
        return $wallet->toArray();
    }

    public function listWallets(int $page = 1, int $pageSize = 20): array
    {
        if (!$this->permissionService->isAdmin()) {
            throw WalletPermissionException::forAdminRequired('查看钱包列表');
        }
        return [
            'items' => $this->walletRepository->findAll($page, $pageSize),
            'total' => $this->walletRepository->count(),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function recharge(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '钱包充值');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::RECHARGE,
            true,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount]
        );
    }

    public function withdraw(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '余额提现');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::WITHDRAW,
            false,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount],
            true
        );
    }

    public function consume(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '余额消费');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::CONSUME,
            false,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount],
            true
        );
    }

    public function refund(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '消费退款');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::REFUND,
            true,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount]
        );
    }

    public function freeze(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '资金冻结');

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);
            $this->assertSufficientAvailable($wallet, $amount, '冻结');

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcadd((string)$frozenBefore, (string)$amount, 2);

            $transition = $stateMachine->applyToWallet($wallet, $wallet->balance, $frozenAfter);
            $this->updateWallet($wallet);

            $freezeNo = $this->freezeRecordRepository->generateFreezeNo();
            $this->freezeRecordRepository->create([
                'wallet_id' => $wallet->id,
                'dealer_id' => $dealerId,
                'freeze_no' => $freezeNo,
                'amount' => $amount,
                'reason' => $options['reason'] ?? '',
                'expired_at' => $options['expired_at'] ?? null,
                'operator' => $options['operator'] ?? '',
            ]);

            $this->recordTransaction($wallet, TransactionType::FREEZE, $amount, $wallet->balance, $wallet->balance, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['reason'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            $result['freeze_no'] = $freezeNo;
            return $result;
        }, ['dealer_id' => $dealerId, 'amount' => $amount]);
    }

    public function unfreeze(string $freezeNo, float $amount = null, array $options = []): array
    {
        $this->assertCanOperateWallet(0, '资金解冻');

        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
            $record = $this->findFreezeRecordForUpdate($freezeNo, '解冻');
            $wallet = $this->getWalletForUpdate($record->dealerId);

            $remaining = $record->remainingAmount;
            $unfreezeAmount = $amount ?? $remaining;

            if ($unfreezeAmount > $remaining + 0.001) {
                throw new WalletException(
                    "解冻金额超额：冻结单剩余 ¥{$remaining}，申请解冻 ¥{$unfreezeAmount}。" .
                    "请调整解冻金额或核对冻结单。"
                );
            }
            $this->validateAmount($unfreezeAmount);

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcsub((string)$frozenBefore, (string)$unfreezeAmount, 2);

            $transition = $stateMachine->applyToWallet($wallet, $wallet->balance, $frozenAfter);
            $this->updateWallet($wallet);

            $newRemaining = (float)bcsub((string)$remaining, (string)$unfreezeAmount, 2);
            $newStatus = $newRemaining < 0.001 ? FreezeStatus::FULLY_UNFROZEN : FreezeStatus::PARTIALLY_UNFROZEN;
            $this->freezeRecordRepository->updateRemaining($record, $newRemaining, $newStatus);

            $this->recordTransaction($wallet, TransactionType::UNFREEZE, $unfreezeAmount, $wallet->balance, $wallet->balance, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['reason'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            $result['freeze_no'] = $freezeNo;
            $result['unfrozen_amount'] = number_format($unfreezeAmount, 2, '.', '');
            $result['remaining_amount'] = number_format($newRemaining, 2, '.', '');
            return $result;
        }, ['freeze_no' => $freezeNo, 'amount' => $amount]);
    }

    public function deductFrozen(string $freezeNo, float $amount = null, array $options = []): array
    {
        $this->assertCanOperateWallet(0, '冻结资金扣除');

        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
            $record = $this->findFreezeRecordForUpdate($freezeNo, '扣除');
            $wallet = $this->getWalletForUpdate($record->dealerId);

            $remaining = $record->remainingAmount;
            $deductAmount = $amount ?? $remaining;

            if ($deductAmount > $remaining + 0.001) {
                throw new WalletException(
                    "扣除金额超额：冻结单剩余 ¥{$remaining}，申请扣除 ¥{$deductAmount}。" .
                    "请调整扣除金额或核对冻结单。"
                );
            }
            $this->validateAmount($deductAmount);

            $balanceBefore = $wallet->balance;
            $balanceAfter = (float)bcsub((string)$balanceBefore, (string)$deductAmount, 2);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcsub((string)$frozenBefore, (string)$deductAmount, 2);

            if ($balanceAfter < -0.001) {
                throw new WalletException("余额不足：扣除 ¥{$deductAmount} 后余额将为负。请先充值或调整扣除金额。");
            }

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $transition = $stateMachine->applyToWallet($wallet, $balanceAfter, $frozenAfter);
            $this->updateWallet($wallet);

            $newRemaining = (float)bcsub((string)$remaining, (string)$deductAmount, 2);
            $newStatus = $newRemaining < 0.001 ? FreezeStatus::DEDUCTED : FreezeStatus::PARTIALLY_UNFROZEN;
            $this->freezeRecordRepository->updateRemaining($record, $newRemaining, $newStatus);

            $this->recordTransaction($wallet, TransactionType::DEDUCT_FROZEN, $deductAmount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['reason'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            $result['freeze_no'] = $freezeNo;
            $result['deducted_amount'] = number_format($deductAmount, 2, '.', '');
            $result['remaining_amount'] = number_format($newRemaining, 2, '.', '');
            return $result;
        }, ['freeze_no' => $freezeNo, 'amount' => $amount]);
    }

    public function getTransactions(int $dealerId, int $page = 1, int $pageSize = 20): array
    {
        $this->assertCanViewWallet($dealerId);
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            throw new WalletException("钱包不存在：经销商ID【{$dealerId}】");
        }
        return [
            'items' => $this->transactionRepository->findByWalletId($wallet->id, $page, $pageSize),
            'total' => $this->transactionRepository->countByWalletId($wallet->id),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function getFreezeRecords(int $dealerId, int $status = null, int $page = 1, int $pageSize = 20): array
    {
        $this->assertCanViewWallet($dealerId);
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            throw new WalletException("钱包不存在：经销商ID【{$dealerId}】");
        }
        return [
            'items' => $this->freezeRecordRepository->findByWalletId($wallet->id, $status, $page, $pageSize),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function reconcileFreezeRecords(int $dealerId): array
    {
        $this->assertCanViewWallet($dealerId);
        return $this->reconciliationService->reconcileFreezeRecords($dealerId);
    }

    public function reconcileBalance(int $dealerId): array
    {
        $this->assertCanViewWallet($dealerId);
        return $this->reconciliationService->reconcileBalance($dealerId);
    }

    public function fixWalletInconsistency(int $dealerId, string $operator = 'system'): array
    {
        if (!$this->permissionService->hasPermission(PermissionService::PERM_WALLET_FIX)) {
            throw WalletPermissionException::forScopeDenied('修复钱包异常数据', PermissionService::PERM_WALLET_FIX);
        }
        return $this->reconciliationService->fixWalletInconsistency($dealerId, $operator);
    }

    private function executeBalanceChange(
        int $dealerId,
        float $amount,
        int $txType,
        bool $isIncrease,
        array $options,
        array $context,
        bool $checkAvailable = false
    ): array {
        return $this->executeTransaction(function () use ($dealerId, $amount, $txType, $isIncrease, $options, $checkAvailable) {
            $wallet = $isIncrease
                ? $this->getOrCreateWalletForUpdate($dealerId)
                : $this->getWalletForUpdate($dealerId);

            if ($checkAvailable) {
                $actionName = $txType === TransactionType::WITHDRAW ? '提现' : '消费';
                $this->assertSufficientAvailable($wallet, $amount, $actionName);
            }

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozenAmount;
            $balanceAfter = $isIncrease
                ? (float)bcadd((string)$balanceBefore, (string)$amount, 2)
                : (float)bcsub((string)$balanceBefore, (string)$amount, 2);

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $transition = $stateMachine->applyToWallet($wallet, $balanceAfter, $frozenBefore);

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, $txType, $amount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozenAmount,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
                'related_no' => $options['related_no'] ?? '',
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            return $result;
        }, $context);
    }

    private function findFreezeRecordForUpdate(string $freezeNo, string $actionName): FreezeRecord
    {
        $freezeRecord = $this->freezeRecordRepository->findByFreezeNo($freezeNo);
        if (!$freezeRecord) {
            throw new WalletException("冻结记录不存在：冻结单号【{$freezeNo}】，请核对单号是否正确。");
        }
        if ($freezeRecord->status !== FreezeStatus::FROZEN) {
            throw new WalletException(
                "冻结记录状态异常：当前状态【" . FreezeStatus::getName($freezeRecord->status) . "】，" .
                "仅【冻结中】的记录允许{$actionName}。如需重新操作请创建新的冻结单。"
            );
        }
        return $freezeRecord;
    }

    private function assertCanViewWallet(int $dealerId): void
    {
        if (!$this->permissionService->canViewWallet($dealerId)) {
            $currentDealerId = $this->permissionService->getCurrentDealerId();
            if ($currentDealerId !== null) {
                throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
            }
            throw WalletPermissionException::forAdminRequired('查询钱包信息');
        }
    }

    private function assertCanOperateWallet(int $dealerId, string $operationName): void
    {
        if ($this->permissionService->isAdmin()) {
            return;
        }
        if (!$this->permissionService->canViewWallet($dealerId)) {
            $currentDealerId = $this->permissionService->getCurrentDealerId();
            if ($currentDealerId !== null) {
                throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
            }
            throw WalletPermissionException::forAdminRequired($operationName);
        }
    }

    private function assertCanCreateWallet(int $dealerId): void
    {
        if ($this->permissionService->isAdmin()) {
            return;
        }
        $currentDealerId = $this->permissionService->getCurrentDealerId();
        if ($currentDealerId === null) {
            throw WalletPermissionException::forAdminRequired('创建钱包');
        }
        if ($dealerId !== $currentDealerId) {
            throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
        }
    }

    private function assertSufficientAvailable(Wallet $wallet, float $amount, string $action): void
    {
        if ($wallet->availableAmount < $amount - 0.001) {
            throw new InsufficientBalanceException(
                "可用余额不足：当前可用 ¥{$wallet->availableAmount}，需{$action} ¥{$amount}。" .
                "建议：先充值或解冻部分冻结资金。"
            );
        }
    }

    private function executeTransaction(callable $callback, array $operationContext = [])
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = 'unknown';
        $internalMethods = ['executeTransaction', 'executeBalanceChange'];
        for ($i = 1; $i < count($trace); $i++) {
            $func = $trace[$i]['function'] ?? '';
            if (!in_array($func, $internalMethods, true) && $func !== '') {
                $caller = $func;
                break;
            }
        }
        $operationName = $operationContext['operation_name'] ?? $this->mapMethodToOperationName($caller);
        $operationType = $operationContext['operation_type'] ?? $this->mapMethodToOperationType($caller);
        $dealerId = $operationContext['dealer_id'] ?? null;

        $walletBefore = null;
        if ($dealerId) {
            try {
                $walletBefore = $this->walletRepository->findByDealerId($dealerId);
            } catch (\Exception $e) {
            }
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();

            $rollbackInfo = $this->buildRollbackInfo($e, $operationName, $operationType, $dealerId, $walletBefore, $operationContext);
            $retryInfo = $this->buildRetryInfo($e, $operationName, $operationContext);

            if ($e instanceof WalletException) {
                $e->setRollbackInfo($rollbackInfo);
                $e->setRetryInfo($retryInfo);
            }

            throw $e;
        }
    }

    private function mapMethodToOperationName(string $method): string
    {
        $map = [
            'recharge' => '钱包充值',
            'withdraw' => '余额提现',
            'freeze' => '资金冻结',
            'unfreeze' => '资金解冻',
            'consume' => '余额消费',
            'refund' => '消费退款',
            'deductFrozen' => '冻结资金扣除',
            'executeBalanceChange' => '余额变更',
        ];
        return $map[$method] ?? $method;
    }

    private function mapMethodToOperationType(string $method): string
    {
        $map = [
            'recharge' => 'balance_increase',
            'withdraw' => 'balance_decrease',
            'freeze' => 'freeze_increase',
            'unfreeze' => 'freeze_decrease',
            'consume' => 'balance_decrease',
            'refund' => 'balance_increase',
            'deductFrozen' => 'freeze_decrease',
            'executeBalanceChange' => 'balance_change',
        ];
        return $map[$method] ?? 'unknown';
    }

    private function buildRollbackInfo(\Exception $e, string $operationName, string $operationType, ?int $dealerId, $walletBefore, array $context): array
    {
        $info = [
            'rollback_success' => true,
            'rollback_time' => date('Y-m-d H:i:s'),
            'operation_name' => $operationName,
            'operation_type' => $operationType,
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'rollback_message' => "【回滚提示】{$operationName}操作失败，所有变更已回滚。",
            'rollback_details' => [],
        ];

        if ($dealerId) {
            $info['dealer_id'] = $dealerId;
        }
        if (isset($context['amount'])) {
            $info['operation_amount'] = number_format((float)$context['amount'], 2, '.', '');
            $info['rollback_details'][] = "操作金额 ¥{$info['operation_amount']} 未实际生效";
        }
        if (isset($context['freeze_no'])) {
            $info['freeze_no'] = $context['freeze_no'];
            $info['rollback_details'][] = "冻结单号【{$context['freeze_no']}】状态未变更";
        }

        if ($walletBefore instanceof Wallet) {
            $info['wallet_snapshot'] = [
                'status_before' => $walletBefore->status,
                'status_before_name' => WalletStatus::getName($walletBefore->status),
                'balance_before' => number_format($walletBefore->balance, 2, '.', ''),
                'frozen_amount_before' => number_format($walletBefore->frozenAmount, 2, '.', ''),
                'available_amount_before' => number_format($walletBefore->availableAmount, 2, '.', ''),
                'version_before' => $walletBefore->version,
            ];
            array_unshift($info['rollback_details'], sprintf(
                '钱包状态已恢复至：%s，余额 ¥%s，冻结 ¥%s，可用 ¥%s',
                WalletStatus::getName($walletBefore->status),
                number_format($walletBefore->balance, 2, '.', ''),
                number_format($walletBefore->frozenAmount, 2, '.', ''),
                number_format($walletBefore->availableAmount, 2, '.', '')
            ));
        }

        if ($e instanceof InsufficientBalanceException) {
            $info['failure_reason'] = '可用余额不足，本次操作未执行';
            $info['rollback_details'][] = '失败原因：可用余额不足，本次操作未执行';
        } elseif ($e instanceof WalletStateException) {
            $info['failure_reason'] = '钱包状态流转校验失败，数据异常或操作不合法';
            $info['rollback_details'][] = '失败原因：钱包状态校验失败，已自动回滚保护数据一致性';
        } elseif ($e instanceof WalletPermissionException) {
            $info['failure_reason'] = '权限校验失败，操作被拒绝';
        } else {
            $info['failure_reason'] = $e->getMessage();
            $info['rollback_details'][] = "失败原因：" . mb_substr($e->getMessage(), 0, 100);
        }

        return $info;
    }

    private function buildRetryInfo(\Exception $e, string $operationName, array $context): array
    {
        $retryParams = [];
        if (isset($context['dealer_id'])) {
            $retryParams['dealer_id'] = $context['dealer_id'];
        }
        if (isset($context['amount'])) {
            $retryParams['amount'] = (float)$context['amount'];
        }
        if (isset($context['freeze_no'])) {
            $retryParams['freeze_no'] = $context['freeze_no'];
        }

        $info = [
            'retryable' => true,
            'retry_strategy' => 'immediate',
            'max_retries' => 3,
            'retry_delay_ms' => 0,
            'retry_entry' => [
                'operation_name' => $operationName,
                'can_retry' => true,
                'retry_button_text' => '重新提交',
                'retry_hint' => '请根据错误提示调整后再操作',
                'retry_params' => $retryParams,
            ],
            'suggestions' => [
                '请检查参数是否正确',
                '如问题持续，请联系技术支持',
            ],
        ];

        if ($e instanceof InsufficientBalanceException) {
            $info['retryable'] = false;
            $info['retry_entry']['can_retry'] = false;
            $info['retry_entry']['retry_button_text'] = '无法重试';
            $info['suggestions'] = [
                '请先充值或解冻部分冻结资金后再试',
                '可适当减小操作金额',
            ];
        } elseif ($e instanceof WalletStateException) {
            $info['retryable'] = false;
            $info['retry_entry']['can_retry'] = false;
            $info['retry_entry']['retry_button_text'] = '无法重试';
            $info['suggestions'] = [
                '请调整操作金额，确保冻结金额不超过余额',
                '如有异常冻结单，请先处理异常冻结单',
                '可联系管理员进行数据修复',
            ];
        } elseif ($e instanceof WalletPermissionException) {
            $info['retryable'] = false;
            $info['retry_entry']['can_retry'] = false;
            $info['retry_entry']['retry_button_text'] = '无法重试';
            $info['suggestions'] = [
                '请使用具有相应权限的账号进行操作',
                '联系管理员为您分配所需的操作权限',
            ];
        } elseif (strpos($e->getMessage(), '乐观锁冲突') !== false) {
            $info['retryable'] = true;
            $info['retry_strategy'] = 'exponential_backoff';
            $info['retry_delay_ms'] = 500;
            $info['retry_entry']['retry_button_text'] = '重新提交';
            $info['retry_entry']['retry_hint'] = '建议 500ms 后重试，使用指数退避策略';
            $info['suggestions'] = [
                '并发操作冲突，请稍后重试',
                '可手动刷新页面后重新提交',
            ];
        } elseif (strpos($e->getMessage(), '不存在') !== false) {
            $info['retryable'] = false;
            $info['retry_entry']['can_retry'] = false;
            $info['retry_entry']['retry_button_text'] = '无法重试';
            $info['suggestions'] = [
                '请核对相关单号或ID是否正确',
            ];
        }

        return $info;
    }

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new WalletException("金额非法：金额必须大于 0，当前值 ¥{$amount}。");
        }
    }

    private function recordTransaction(Wallet $wallet, int $type, float $amount, float $balanceBefore, float $balanceAfter, array $extra = []): void
    {
        $this->transactionRepository->create([
            'wallet_id' => $wallet->id,
            'dealer_id' => $wallet->dealerId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'frozen_before' => $extra['frozen_before'] ?? $wallet->frozenAmount,
            'frozen_after' => $extra['frozen_after'] ?? $wallet->frozenAmount,
            'related_no' => $extra['related_no'] ?? '',
            'operator' => $extra['operator'] ?? '',
            'remark' => $extra['remark'] ?? '',
        ]);
    }

    private function refreshAndReturn(Wallet $wallet): array
    {
        $this->refreshWallet($wallet);
        return $wallet->toArray();
    }

    private function refreshWallet(Wallet $wallet): void
    {
        $fresh = $this->walletRepository->findById($wallet->id);
        if ($fresh) {
            $this->syncWalletProperties($wallet, $fresh);
        }
    }

    private function syncWalletProperties(Wallet $target, Wallet $source): void
    {
        $target->balance = $source->balance;
        $target->frozenAmount = $source->frozenAmount;
        $target->availableAmount = $source->availableAmount;
        $target->status = $source->status;
        $target->version = $source->version;
        $target->createdAt = $source->createdAt;
        $target->updatedAt = $source->updatedAt;
    }

    private function getWalletForUpdate(int $dealerId): Wallet
    {
        $wallet = $this->walletRepository->findByDealerIdForUpdate($dealerId);
        if (!$wallet) {
            throw new WalletException("钱包不存在：经销商ID【{$dealerId}】，请先为该经销商创建钱包。");
        }
        return $wallet;
    }

    private function getOrCreateWalletForUpdate(int $dealerId): Wallet
    {
        $wallet = $this->walletRepository->findByDealerIdForUpdate($dealerId);
        if (!$wallet) {
            $this->walletRepository->create($dealerId);
            $wallet = $this->walletRepository->findByDealerIdForUpdate($dealerId);
        }
        return $wallet;
    }

    private function updateWallet(Wallet $wallet): void
    {
        $maxRetries = 3;
        $retry = 0;
        $retryDelays = [0, 100, 300];
        while ($retry < $maxRetries) {
            if ($this->walletRepository->update($wallet)) {
                $this->refreshWallet($wallet);
                return;
            }
            if ($retry < $maxRetries - 1) {
                usleep($retryDelays[$retry] * 1000);
            }
            $retry++;
            $freshWallet = $this->walletRepository->findById($wallet->id);
            if (!$freshWallet) {
                throw new WalletException("钱包更新失败：钱包ID【{$wallet->id}】不存在。");
            }
            $this->syncWalletProperties($wallet, $freshWallet);
        }

        $exception = new WalletException("钱包更新失败：乐观锁冲突，已重试{$maxRetries}次。请稍后重试。");
        $exception->setRetryInfo([
            'retryable' => true,
            'retry_strategy' => 'exponential_backoff',
            'max_retries' => 3,
            'retry_delay_ms' => 500,
            'retry_entry' => [
                'operation_name' => '钱包更新',
                'can_retry' => true,
                'retry_button_text' => '重新提交',
                'retry_hint' => '建议 500ms 后重试，使用指数退避策略',
            ],
            'suggestions' => [
                '并发操作冲突，请稍后重试',
                '可手动刷新页面后重新提交',
            ],
        ]);
        throw $exception;
    }
}
