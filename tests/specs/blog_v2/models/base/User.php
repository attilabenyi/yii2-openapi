<?php

namespace app\models\base;

/**
 * The User
 *
 * @property int $id
 * @property string $login
 * @property string $email
 * @property string $password
 * @property string $role
 * @property string $created_at
 *
 */
abstract class User extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return '{{%v2_users}}';
    }

    public function rules()
    {
        return [
            [['login', 'email', 'password', 'role', 'created_at'], 'trim'],
            [['login', 'email', 'password'], 'required'],
            [['login'], 'unique'],
            [['login'], 'string'],
            [['email'], 'unique'],
            [['email'], 'string'],
            [['email'], 'email'],
            [['password'], 'string'],
            [['role'], 'string'],
            [['role'], 'in', 'range' => ['admin', 'editor', 'reader']],
            [['created_at'], 'datetime'],
        ];
    }

}
