<?php

namespace app\models;

class User extends \yii\base\BaseObject implements \yii\web\IdentityInterface
{
    public $id;
    public $username;
    public $password;
    public $authKey;
    public $accessToken;
    public $is_admin;

    private static $users = [
        '100' => [
            'id' => '100',
            'username' => YIIMP_ADMIN_USER,
            'password' => YIIMP_ADMIN_PASS,
            'is_admin' => true,
        ],
    ];


    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id)
    {
        return isset(self::$users[$id]) ? new static(self::$users[$id]) : null;
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        // The public upstream example token is not an authentication method.
        return null;
    }

    /**
     * Finds user by username
     *
     * @param string $username
     * @return static|null
     */
    public static function findByUsername($username)
    {
        foreach (self::$users as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return new static($user);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKey()
    {
        return hash_hmac('sha256', YIIMP_ADMIN_USER . ':' . (defined('YIIMP_ADMIN_PASS_HASH') ? YIIMP_ADMIN_PASS_HASH : YIIMP_ADMIN_PASS), YIIMP_COOKIE_VALIDATION_KEY);
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthKey($authKey)
    {
        return is_string($authKey) && hash_equals($this->getAuthKey(), $authKey);
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
        if (!is_string($password)) return false;
        if (defined('YIIMP_ADMIN_PASS_HASH')) return password_verify($password, YIIMP_ADMIN_PASS_HASH);
        return is_string($this->password) && $this->password !== '' && hash_equals($this->password, $password);
    }
}
