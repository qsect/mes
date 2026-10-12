<?php declare(strict_types=1);

namespace App\Core;

/**
 * 密码策略：统一维护"初始密码 / 强度规则 / 哈希 / 宽松校验"
 *
 * 强度规则（非管理员必须满足）：
 *   1. 长度不少于 8 位
 *   2. 同时包含大写字母
 *   3. 同时包含小写字母
 *   4. 同时包含数字
 *   5. 同时包含特殊字符（非字母数字）
 * 管理员账号不受强度规则约束。
 */
class PasswordPolicy
{
    /** 新建用户 / 被重置后的初始密码 */
    public const DEFAULT_PASSWORD = '123456';

    /** 最小长度 */
    public const MIN_LENGTH = 8;

    /** 强度规则说明（供前端展示与错误提示复用） */
    public const RULE_TEXT = '密码必须不少于 8 位，且同时包含大写字母、小写字母、数字和特殊字符';

    /**
     * 是否满足强度要求
     */
    public static function isStrong(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        if (self::len($password) < self::MIN_LENGTH) {
            return false;
        }
        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return false;
        }
        return true;
    }

    /**
     * 返回不满足强度要求的具体原因（已满足则返回空字符串）
     */
    public static function describe(string $password): string
    {
        if ($password === '') {
            return '密码不能为空';
        }
        if (self::len($password) < self::MIN_LENGTH) {
            return '密码长度不得少于 ' . self::MIN_LENGTH . ' 位';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return '密码必须包含小写字母';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return '密码必须包含大写字母';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return '密码必须包含数字';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return '密码必须包含特殊字符（如 !@#$%^&*）';
        }
        return '';
    }

    /**
     * 生成密码哈希
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * 判断存储的口令是否需要升级为现代哈希（bcrypt / argon2）
     * md5 与历史明文都会被识别为需要升级
     */
    public static function needsRehash(string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        $info = password_get_info($stored);
        return (($info['algoName'] ?? 'unknown') === 'unknown');
    }

    /**
     * 宽松比对：兼容 bcrypt / md5 / 历史明文口令
     */
    public static function verify(string $password, string $stored): bool
    {
        if ($stored === '' || $password === '') {
            return false;
        }
        if (password_verify($password, $stored)) {
            return true;
        }
        if ($stored === md5($password)) {
            return true;
        }
        // 历史明文口令兜底（仅用于登录校验，登录后会被强制升级为哈希）
        return $stored === $password;
    }

    /**
     * 按字符数计算长度（兼容多字节）
     */
    private static function len(string $password): int
    {
        return function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    }
}
