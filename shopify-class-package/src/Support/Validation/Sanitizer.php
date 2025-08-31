<?php

namespace Itmar\ShopifyClassPackage\Support\Validation;

final class Sanitizer
{
    public function id(?string $v): string
    {
        $v = (string) $v;
        $v = trim($v);
        if ($v === '') {
            throw new \InvalidArgumentException('Missing id');
        }
        return $v;
    }

    /** lines: [ ['id'=>'gid://...','quantity'=>1], ... ] */
    public function cartLines($v): array
    {
        if (!is_array($v)) {
            throw new \InvalidArgumentException('lines must be array');
        }
        $out = [];
        foreach ($v as $row) {
            if (!is_array($row)) continue;
            $id  = $this->id($row['id'] ?? '');
            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty < 0) {
                throw new \InvalidArgumentException('quantity invalid');
            }
            $out[] = ['id' => $id, 'quantity' => $qty];
        }
        return $out;
    }
}
