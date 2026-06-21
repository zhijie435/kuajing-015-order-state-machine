<?php

namespace Dealer\Wallet\Exception;

class WalletException extends \RuntimeException
{
    private array $rollbackInfo = [];
    private array $retryInfo = [];

    public function setRollbackInfo(array $info): void
    {
        $this->rollbackInfo = $info;
    }

    public function getRollbackInfo(): array
    {
        return $this->rollbackInfo;
    }

    public function hasRollbackInfo(): bool
    {
        return !empty($this->rollbackInfo);
    }

    public function setRetryInfo(array $info): void
    {
        $this->retryInfo = $info;
    }

    public function getRetryInfo(): array
    {
        return $this->retryInfo;
    }

    public function hasRetryInfo(): bool
    {
        return !empty($this->retryInfo);
    }

    public function getFullContext(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'rollback' => $this->rollbackInfo,
            'retry' => $this->retryInfo,
        ];
    }
}
