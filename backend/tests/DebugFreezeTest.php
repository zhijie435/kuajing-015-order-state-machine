<?php

use PHPUnit\Framework\TestCase;
use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Repository\FreezeRecordRepository;

class DebugFreezeTest extends TestCase
{
    public function testDebugFindAllByWalletId()
    {
        \PermissionService::setOperatorContext('admin_1', 'super_admin');
        $service = new WalletService();
        $freezeRepo = new FreezeRecordRepository();

        $rechargeResult = $service->recharge(999, 1000.00, ['operator' => 'admin']);
        $walletId = $rechargeResult['id'];
        echo "\nWallet ID: $walletId\n";

        $freezeResult = $service->freeze(999, 100.00, ['operator' => 'admin', 'reason' => 'test']);
        $freezeNo = $freezeResult['freeze_no'];
        echo "Freeze no: $freezeNo\n";

        $record1 = $freezeRepo->findByFreezeNo($freezeNo);
        echo "findByFreezeNo found: " . ($record1 ? 'yes' : 'no') . "\n";
        if ($record1) {
            echo "  wallet_id: " . $record1->walletId . "\n";
            echo "  freeze_no: " . $record1->freezeNo . "\n";
        }

        $records = $freezeRepo->findAllByWalletId($walletId);
        echo "findAllByWalletId count: " . count($records) . "\n";

        $db = \Dealer\Wallet\Config\Database::getConnection();
        $sql = "SELECT * FROM dealer_wallet_freeze_record WHERE wallet_id = :wallet_id ORDER BY id ASC";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':wallet_id', $walletId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        echo "Direct query count: " . count($rows) . "\n";
        foreach ($rows as $row) {
            echo "  Row: " . json_encode($row) . "\n";
        }

        echo "Last SQL: " . $db->lastSql . "\n";
        echo "Last Params: " . json_encode($db->lastParams) . "\n";

        $this->assertTrue(true);
    }
}
