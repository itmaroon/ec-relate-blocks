<?php

namespace Itmar\ShopifyClassPackage\Support\Security;

final class TokenVault
{
    /** 暗号化して user_meta に保存 */
    public static function saveUserSecret(int $userId, string $metaKey, string $plaintext): void
    {
        $enc = Crypto::encrypt($plaintext);
        update_user_meta($userId, $metaKey, $enc);
    }

    /** 復号して取得（存在しない/壊れている場合は null） */
    public static function getUserSecret(int $userId, string $metaKey): ?string
    {
        $enc = get_user_meta($userId, $metaKey, true);
        if (!$enc || !is_string($enc)) return null;
        return Crypto::decrypt($enc);
    }

    /** 秘密の破棄 */
    public static function deleteUserSecret(int $userId, string $metaKey): void
    {
        delete_user_meta($userId, $metaKey);
    }
}
