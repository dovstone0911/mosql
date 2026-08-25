<?php

namespace Dovstone\MoSQL\Uid;

class UidGenerator
{
    private int $length;
    private string $charset = '0123456789';

    public function __construct(int $length = 8)
    {
        $this->length = max(8, min(10, $length));
    }

    public function generate(): string
    {
        $uid = '';
        for ($i = 0; $i < $this->length; $i++) {
            $uid .= $this->charset[random_int(0, 9)];
        }
        return $uid;
    }

    public function isValid(string $uid): bool
    {
        return preg_match('/^[0-9]{8,10}$/', $uid) === 1;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): self
    {
        $this->length = max(8, min(10, $length));
        return $this;
    }
}
