<?php
namespace yii\base {
    class BaseObject { public function __construct(array $values = []) { foreach ($values as $key => $value) $this->$key = $value; } }
}
namespace yii\web { interface IdentityInterface {} }
namespace {
    define('YIIMP_ADMIN_USER', 'admin');
    define('YIIMP_ADMIN_PASS', '');
    define('YIIMP_ADMIN_PASS_HASH', password_hash('public-test-password-never-used-for-login', PASSWORD_DEFAULT));
    define('YIIMP_COOKIE_VALIDATION_KEY', str_repeat('public-test-key-', 4));
    require __DIR__ . '/../../yiimp2/models/User.php';
    $user = \app\models\User::findByUsername('admin');
    function check($condition, $label) { if (!$condition) throw new \RuntimeException($label); }
    check($user !== null, 'admin identity resolves');
    check($user->validatePassword('public-test-password-never-used-for-login'), 'valid hashed password');
    check(!$user->validatePassword('wrong'), 'invalid password rejected');
    check(!$user->validatePassword(''), 'empty password rejected');
    check(!$user->validatePassword(null), 'non-string rejected');
    check(\app\models\User::findIdentityByAccessToken('100-token') === null, 'upstream token rejected');
    check($user->validateAuthKey($user->getAuthKey()), 'private session auth key');
    check(!$user->validateAuthKey(str_repeat('0', 64)), 'forged auth key rejected');
    echo "Admin authentication regressions passed.\n";
}
