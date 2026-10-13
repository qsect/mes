<?php declare(strict_types=1);

namespace App\Core;

/**
 * 人员角色判定：识别「班长 / 组长 / 主管」等一线管理角色。
 *
 * ★ 为什么要抽出来：此前这个判定散落在 DiaryController / AssetsController / SkillController
 *   各自写一份正则，口径还不完全一致（有的带 foreman/leader/supervisor，有的没有）。
 *   新模块再抄一份，就会出现「同一个人在 A 模块能编辑、在 B 模块不能编辑」的割裂。
 *   统一到这里，后续只需维护一处。
 *
 * ★ 判定口径沿用 DiaryController：
 *   角色(role) / 所属组(group_name) / 部门(department) / 班组(team) 任一字段命中即视为管理角色。
 *   这样历史数据里「班组名带班长字样」的账号也能被识别。
 *
 * ★ 只读判定，不涉及数据库：本类不查库，纯看会话里的用户信息，
 *   方便脱离环境做单元测试（backend/tests/UserRoleTest.php）。
 */
class UserRole
{
    /** 管理角色关键词：中文职位 + 常见英文职位名 */
    private const LEADER_PATTERN = '/(班长|组长|主管|领班|主任|部长|科长|经理|foreman|leader|supervisor)/i';

    /**
     * 是否为「班长级」管理角色（含组长 / 主管 / 领班等同类一线管理者）
     *
     * @param array|null $user 会话中的用户信息
     */
    public static function isLeader($user): bool
    {
        if (empty($user) || !is_array($user)) {
            return false;
        }

        $haystack = implode(' ', [
            (string)($user['role'] ?? ''),
            (string)($user['group_name'] ?? ''),
            (string)($user['department'] ?? ''),
            (string)($user['team'] ?? ''),
        ]);

        return (bool)preg_match(self::LEADER_PATTERN, $haystack);
    }

    /**
     * 是否可维护业务数据 = 超级管理员 或 班长级管理角色
     *
     * 用于「列表页档案维护」这类需要人工判断、不适合纯按模块授权放开的写操作。
     */
    public static function canMaintain($user): bool
    {
        if (empty($user) || !is_array($user)) {
            return false;
        }

        $uname = strtolower(trim((string)($user['username'] ?? '')));
        if (!empty($user['is_admin']) || $uname === 'admin') {
            return true;
        }

        return self::isLeader($user);
    }
}
