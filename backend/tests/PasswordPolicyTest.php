<?php
declare(strict_types=1);

/**
 * 口令策略单元测试
 *
 * 覆盖 App\Core\PasswordPolicy：强度规则、错误描述、哈希、宽松校验、重哈希判定。
 * 这些都是纯函数，不依赖数据库/会话，可脱离运行环境直接验证。
 */

require_once __DIR__ . '/../app/Core/PasswordPolicy.php';

use App\Core\PasswordPolicy;

// ---------- 强度规则 ----------

test('口令强度：合法强口令应通过', function () {
    assert_true(PasswordPolicy::isStrong('Abc12345!'));
    assert_true(PasswordPolicy::isStrong('P@ssw0rd'));
    assert_true(PasswordPolicy::isStrong('aB3$efgh'));
});

test('口令强度：长度不足 8 位应拒绝', function () {
    assert_false(PasswordPolicy::isStrong('Ab1!'));
    assert_false(PasswordPolicy::isStrong('Abc123!'));   // 7 位
});

test('口令强度：缺少任一类字符应拒绝', function () {
    assert_false(PasswordPolicy::isStrong('abcdefg1!'), '缺大写字母');
    assert_false(PasswordPolicy::isStrong('ABCDEFG1!'), '缺小写字母');
    assert_false(PasswordPolicy::isStrong('Abcdefgh!'), '缺数字');
    assert_false(PasswordPolicy::isStrong('Abcdefg1'), '缺特殊字符');
});

test('口令强度：空口令应拒绝', function () {
    assert_false(PasswordPolicy::isStrong(''));
});

test('口令强度：describe 返回的原因与缺失项一致', function () {
    assert_eq('密码不能为空', PasswordPolicy::describe(''));
    assert_contains('长度', PasswordPolicy::describe('Ab1!'));
    assert_contains('小写', PasswordPolicy::describe('ABC12345!'));
    assert_contains('大写', PasswordPolicy::describe('abc12345!'));
    assert_contains('数字', PasswordPolicy::describe('Abcdefgh!'));
    assert_contains('特殊字符', PasswordPolicy::describe('Abcdefg1'));
    assert_eq('', PasswordPolicy::describe('Abc12345!'), '合规口令应返回空字符串');
});

// ---------- 哈希与校验 ----------

test('口令哈希：bcrypt 可被 verify 校验通过、错误口令被拒', function () {
    $hash = PasswordPolicy::hash('Abc12345!');
    assert_true(PasswordPolicy::verify('Abc12345!', $hash));
    assert_false(PasswordPolicy::verify('Wrong123!', $hash));
});

test('口令校验：兼容历史 md5 与明文（登录后会被升级为哈希）', function () {
    assert_true(PasswordPolicy::verify('secret', md5('secret')));
    assert_true(PasswordPolicy::verify('secret', 'secret'));
    assert_false(PasswordPolicy::verify('secret', 'other'));
    assert_false(PasswordPolicy::verify('', 'secret'));
    assert_false(PasswordPolicy::verify('secret', ''));
});

test('needsRehash：md5 / 明文识别为需升级，bcrypt 不需要', function () {
    assert_true(PasswordPolicy::needsRehash(md5('secret')));
    assert_true(PasswordPolicy::needsRehash('plaintext'));
    assert_false(PasswordPolicy::needsRehash(PasswordPolicy::hash('Abc12345!')));
    assert_false(PasswordPolicy::needsRehash(''));
});

// ---------- 不变式 ----------

test('不变式：初始口令本身不满足强度规则（否则首登强制改密形同虚设）', function () {
    assert_false(
        PasswordPolicy::isStrong(PasswordPolicy::DEFAULT_PASSWORD),
        'DEFAULT_PASSWORD 必须弱于强度规则，才能触发"首次登录强制改密"'
    );
    assert_true(
        strlen(PasswordPolicy::DEFAULT_PASSWORD) < PasswordPolicy::MIN_LENGTH
            || PasswordPolicy::describe(PasswordPolicy::DEFAULT_PASSWORD) !== '',
        '初始口令若长度达标，则必须至少缺一类字符'
    );
});
