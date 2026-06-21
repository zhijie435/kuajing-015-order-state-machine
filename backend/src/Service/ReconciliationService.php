<?php

namespace Dealer\Wallet\Service;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Model\FreezeRecord;
use Dealer\Wallet\Model\Transaction;
use Dealer\Wallet\Model\Wallet;
use Dealer\Wallet\Repository\FreezeRecordRepository;
use Dealer\Wallet\Repository\TransactionRepository;
use Dealer\Wallet\Repository\WalletRepository;
use PDO;

class ReconciliationService
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    private WalletRepository $walletRepository;
    private TransactionRepository $transactionRepository;
    private FreezeRecordRepository $freezeRecordRepository;
    /** @var PDO|MockDatabase */
    private $pdo;

    public function __construct()
    {
        $this->walletRepository = new WalletRepository();
        $this->transactionRepository = new TransactionRepository();
        $this->freezeRecordRepository = new FreezeRecordRepository();
        $this->pdo = Database::getConnection();
    }

    public function reconcileFreezeRecords(int $dealerId): array
    {
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            return $this->buildResult(false, [], ['钱包不存在']);
        }

        $issues = [];
        $freezeRecords = $this->freezeRecordRepository->findAllByDealerId($dealerId);
        $transactions = $this->transactionRepository->findAllByDealerIdOrdered($dealerId);

        $frozenSum = 0.0;
        $freezeTxSum = 0.0;
        $unfreezeTxSum = 0.0;

        foreach ($freezeRecords as $record) {
            if ($record->status === FreezeStatus::FROZEN || $record->status === FreezeStatus::PARTIALLY_UNFROZEN) {
                $frozenSum = (float)bcadd((string)$frozenSum, (string)$record->remainingAmount, 2);
            }
        }

        foreach ($transactions as $tx) {
            if ($tx->type === TransactionType::FREEZE) {
                $freezeTxSum = (float)bcadd((string)$freezeTxSum, (string)$tx->amount, 2);
            } elseif ($tx->type === TransactionType::UNFREEZE || $tx->type === TransactionType::DEDUCT_FROZEN) {
                $unfreezeTxSum = (float)bcadd((string)$unfreezeTxSum, (string)$tx->amount, 2);
            }
        }

        $expectedFrozen = (float)bcsub((string)$freezeTxSum, (string)$unfreezeTxSum, 2);
        if (abs($frozenSum - $expectedFrozen) > 0.001) {
            $issues[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'FREEZE_SUM_MISMATCH',
                'message' => sprintf(
                    '冻结汇总不一致：冻结记录剩余合计 ¥%s，交易流水冻结-解冻 ¥%s，差额 ¥%s',
                    number_format($frozenSum, 2),
                    number_format($expectedFrozen, 2),
                    number_format($frozenSum - $expectedFrozen, 2)
                ),
            ];
        }

        if (abs($wallet->frozenAmount - $frozenSum) > 0.001) {
            $issues[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'WALLET_FROZEN_MISMATCH',
                'message' => sprintf(
                    '钱包冻结金额不一致：钱包 frozen_amount ¥%s，冻结记录剩余合计 ¥%s，差额 ¥%s',
                    number_format($wallet->frozenAmount, 2),
                    number_format($frozenSum, 2),
                    number_format($wallet->frozenAmount - $frozenSum, 2)
                ),
            ];
        }

        return $this->buildResult(empty($issues), $issues, [
            'dealer_id' => $dealerId,
            'wallet_frozen' => $wallet->frozenAmount,
            'freeze_record_sum' => $frozenSum,
            'freeze_transactions_sum' => $freezeTxSum,
            'unfreeze_transactions_sum' => $unfreezeTxSum,
            'freeze_record_count' => count($freezeRecords),
            'freeze_transaction_count' => count(array_filter($transactions, fn($t) => $t->type === TransactionType::FREEZE)),
        ]);
    }

    public function reconcileBalance(int $dealerId): array
    {
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            return $this->buildResult(false, [], ['钱包不存在']);
        }

        $issues = [];
        $transactions = $this->transactionRepository->findAllByDealerIdOrdered($dealerId);

        $runningBalance = 0.0;
        $increaseTypes = [TransactionType::RECHARGE, TransactionType::REFUND];
        $decreaseTypes = [TransactionType::WITHDRAW, TransactionType::CONSUME, TransactionType::DEDUCT_FROZEN];

        foreach ($transactions as $tx) {
            if (in_array($tx->type, $increaseTypes, true)) {
                $runningBalance = (float)bcadd((string)$runningBalance, (string)$tx->amount, 2);
            } elseif (in_array($tx->type, $decreaseTypes, true)) {
                $runningBalance = (float)bcsub((string)$runningBalance, (string)$tx->amount, 2);
            }
        }

        if (abs($wallet->balance - $runningBalance) > 0.001) {
            $issues[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'BALANCE_CHAIN_MISMATCH',
                'message' => sprintf(
                    '余额流水链不一致：钱包 balance ¥%s，按交易流水累计 ¥%s，差额 ¥%s',
                    number_format($wallet->balance, 2),
                    number_format($runningBalance, 2),
                    number_format($wallet->balance - $runningBalance, 2)
                ),
            ];
        }

        $expectedAvailable = (float)bcsub((string)$wallet->balance, (string)$wallet->frozenAmount, 2);
        if (abs($wallet->availableAmount - $expectedAvailable) > 0.001) {
            $issues[] = [
                'severity' => self::SEVERITY_WARNING,
                'code' => 'AVAILABLE_AMOUNT_MISMATCH',
                'message' => sprintf(
                    '可用金额计算不一致：钱包 available_amount ¥%s，余额-冻结计算 ¥%s，差额 ¥%s',
                    number_format($wallet->availableAmount, 2),
                    number_format($expectedAvailable, 2),
                    number_format($wallet->availableAmount - $expectedAvailable, 2)
                ),
            ];
        }

        return $this->buildResult(empty($issues), $issues, [
            'dealer_id' => $dealerId,
            'wallet_balance' => $wallet->balance,
            'wallet_frozen' => $wallet->frozenAmount,
            'wallet_available' => $wallet->availableAmount,
            'calculated_balance' => $runningBalance,
            'calculated_available' => $expectedAvailable,
            'transaction_count' => count($transactions),
        ]);
    }

    public function fixWalletInconsistency(int $dealerId, string $operator = 'system'): array
    {
        $freezeResult = $this->reconcileFreezeRecords($dealerId);
        $balanceResult = $this->reconcileBalance($dealerId);

        $issuesToFix = array_merge($freezeResult['issues'] ?? [], $balanceResult['issues'] ?? []);
        if (empty($issuesToFix)) {
            return $this->buildResult(true, [], ['message' => '钱包数据一致，无需修复。']);
        }

        $this->pdo->beginTransaction();
        try {
            $wallet = $this->walletRepository->findByDealerId($dealerId);
            if (!$wallet) {
                throw new \Exception("钱包不存在：经销商ID【{$dealerId}】");
            }

            $frozenSum = $this->freezeRecordRepository->sumFrozenByDealerId($dealerId);
            $transactions = $this->transactionRepository->findAllByDealerIdOrdered($dealerId);

            $increaseTypes = [TransactionType::RECHARGE, TransactionType::REFUND];
            $decreaseTypes = [TransactionType::WITHDRAW, TransactionType::CONSUME, TransactionType::DEDUCT_FROZEN];
            $balance = 0.0;
            foreach ($transactions as $tx) {
                if (in_array($tx->type, $increaseTypes, true)) {
                    $balance = (float)bcadd((string)$balance, (string)$tx->amount, 2);
                } elseif (in_array($tx->type, $decreaseTypes, true)) {
                    $balance = (float)bcsub((string)$balance, (string)$tx->amount, 2);
                }
            }

            $available = (float)bcsub((string)$balance, (string)$frozenSum, 2);
            if ($available < 0) {
                $available = 0.0;
            }

            $wallet->balance = $balance;
            $wallet->frozenAmount = $frozenSum;
            $wallet->availableAmount = $available;
            $wallet->calculateAvailable();

            $this->walletRepository->update($wallet);

            $this->pdo->commit();

            return $this->buildResult(true, [], [
                'message' => '钱包数据修复成功。',
                'fixed_balance' => number_format($balance, 2, '.', ''),
                'fixed_frozen' => number_format($frozenSum, 2, '.', ''),
                'fixed_available' => number_format($available, 2, '.', ''),
                'issues_fixed' => count($issuesToFix),
            ]);
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            return $this->buildResult(false, [[
                'severity' => self::SEVERITY_ERROR,
                'code' => 'FIX_FAILED',
                'message' => $e->getMessage(),
            ]], []);
        }
    }

    private function buildResult(bool $success, array $issues, array $extra = []): array
    {
        return array_merge([
            'success' => $success,
            'issues' => $issues,
            'reconciled_at' => date('Y-m-d H:i:s'),
        ], $extra);
    }
}
