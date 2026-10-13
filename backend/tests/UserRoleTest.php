<?php
declare(strict_types=1);

/**
 * App\Core\UserRole 单元测试
 *
 * 覆盖「班长级管理角色」判定与「可维护数据」判定。
 * 该判定被老化车档案维护等写操作用于鉴权，判错就是越权或误伤，必须锁死口径。
 */

require_once __DIR__ . '/../app/Core/UserRole.php';

use App\Core\UserRole;

test('空用户 / 非数组不应被判为班长', function () {
    assert_false(UserRole::isLeader(null));
    assert_false(UserRole::isLeader([]));
    assert_false(UserRole::isLeader('班长'));
});

test('role 含「班长」应判定为班长', function () {
    assert_true(UserRole::isLeader(['role' => '装配班长']));
    assert_true(UserRole::isLeader(['role' => '班长']));
});

test('role 含组长/主管/领班等同类管理角色也应判定为班长', function () {
    foreach (['调试组长', '包装主管', '装配领班', '车间主任', '生产经理'] as $role) {
        assert_true(UserRole::isLeader(['role' => $role]), "role={$role}");
    }
});

test('英文职位名 foreman/leader/supervisor 也应生效', function () {
    assert_true(UserRole::isLeader(['role' => 'Team Leader']));
    assert_true(UserRole::isLeader(['role' => 'supervisor']));
});

test('普通作业员角色不应被判定为班长', function () {
    foreach (['作业员', '装配工', '调试员', '实习学生', 'technician'] as $role) {
        assert_false(UserRole::isLeader(['role' => $role]), "role={$role}");
    }
});

test('role 为空但班组/部门带班长字样时兜底生效', function () {
    assert_true(UserRole::isLeader(['role' => '', 'team' => '装配班长组']));
    assert_true(UserRole::isLeader(['role' => '', 'department' => '包装班长']));
});

test('超级管理员恒具备维护权限（即便 role 不是班长）', function () {
    assert_true(UserRole::canMaintain(['username' => 'admin', 'is_admin' => 0, 'role' => '系统管理员']));
    assert_true(UserRole::canMaintain(['username' => 'zhangsan', 'is_admin' => 1, 'role' => '']));
});

test('班长具备维护权限', function () {
    assert_true(UserRole::canMaintain(['username' => 'lisi', 'is_admin' => 0, 'role' => '装配班长']));
});

test('普通作业员不具备维护权限', function () {
    assert_false(UserRole::canMaintain(['username' => 'wangwu', 'is_admin' => 0, 'role' => '装配工']));
    assert_false(UserRole::canMaintain([]));
    assert_false(UserRole::canMaintain(null));
});
