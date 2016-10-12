<?php

namespace app\models;

use app\helpers\CommonHelper;
use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "user".
 *
 * @property integer $id
 * @property string $username
 * @property string $first_name
 * @property string $email
 * @property string $password
 * @property string $password_reset_token
 * @property integer $status
 * @property integer $purchased
 * @property integer $expire_date
 * @property integer $created_at
 * @property integer $updated_at
 */
class User extends \yii\db\ActiveRecord implements \yii\web\IdentityInterface
{

    private $access_token;
    public $role;

    const STATUS_DELETED = 0;
    const STATUS_ACTIVE = 10;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'user';
    }
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => 'yii\behaviors\TimestampBehavior',
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
                    ActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
                ]
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['username', 'email'], 'required'],
            [['first_name'], 'required', 'on' => 'change-name'],
            [['created_at', 'updated_at', 'purchased', 'expire_date'], 'integer'],
            [['username', 'email'], 'string', 'max' => 255],
            [['first_name', 'role'], 'string', 'max' => 50],
            [['username'], 'unique'],
            [['email'], 'unique'],
            [['email'], 'email'],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DELETED]],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'User',
            'username' => 'Username',
            'first_name' => 'First Name',
            'email' => 'Email',
            'password' => 'Password',
            'status' => 'Status',
            'purchased' => 'Purchased',
            'expire_date' => 'Expire Date',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }


    public static function findByUsername($username)
    {
        return static::findOne(['username' => $username]);
    }

    public static function findByEmail($email)
    {
        return static::findOne(['email' => $email]);
    }

    /**
     * @inheritdoc
     */
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id]);
    }

    /**
     * @inheritdoc
     */

    public static function findIdentityByAccessToken($token, $type = null)
    {
        if (($userAccessTokens = UserAccessTokens::findOne(['access_token' => $token])) !== null) {
            return static::findOne(['id' => $userAccessTokens->user_id]);
        } else {
            return null;
        }
    }

    public function initExpireDate() {
        $full_date = time();
        $get_date = Yii::$app->formatter->asDate($full_date);
        $time_suffix = ' 23:59:59';
        $static_time = Yii::$app->formatter->asTimestamp($get_date.$time_suffix);

        return CommonHelper::addDays($static_time, Yii::$app->customSettings->getTrialLimit());
    }

    /**
     * @inheritdoc
     */

    public function getId()
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */

    public function getAuthKey()
    {
        return $this->access_token;
    }

    /**
     * @inheritdoc
     */
    public function validateAuthKey($authKey)
    {
        return $this->access_token === $authKey;
    }
    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return boolean if password provided is valid for current user
     */

    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password);
    }

    /**
     * Generates password hash from password and sets it to the model
     *
     * @param string $password
     */

    public function setPassword($password)
    {
        $this->password = Yii::$app->security->generatePasswordHash($password);
    }

    /**
     * Generates "remember me" authentication key
     */

    public function generateAuthKey()
    {
        $this->access_token = Yii::$app->security->generateRandomString();
    }


    /**
     * Finds user by password reset token
     *
     * @param string $token password reset token
     * @return static|null
     */
    public static function findByPasswordResetToken($token)
    {
        if (!static::isPasswordResetTokenValid($token)) {
            return null;
        }

        return static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Finds out if password reset token is valid
     *
     * @param string $token password reset token
     * @return boolean
     */
    public static function isPasswordResetTokenValid($token)
    {
        if (empty($token)) {
            return false;
        }

        $timestamp = (int) substr($token, strrpos($token, '_') + 1);
        $expire = Yii::$app->params['user.passwordResetTokenExpire'];
        return $timestamp + $expire >= time();
    }

    /**
     * Generates new password reset token
     */
    public function generatePasswordResetToken()
    {
        $this->password_reset_token = Yii::$app->security->generateRandomString() . '_' . time();
    }

    /**
     * Removes password reset token
     */
    public function removePasswordResetToken()
    {
        $this->password_reset_token = null;
    }

    public function isEndExpireDate() {
        if (Yii::$app->formatter->asDate(time()) > Yii::$app->formatter->asDate($this->expire_date))
            return true;
        return false;
    }

    public function getResponseData($remove_fields = []) {
        if (!in_array('access_token', $remove_fields))
            $response['access_token'] = $this->getAuthKey();

        $response['first_name'] = $this->first_name;
        $response['email'] = $this->email;
        $response['username'] = $this->username;
        $response['purchased'] = $this->purchased ? true : false;
        $response['expire_date'] = $this->expire_date;
        $response['phase'] = [
            'phase_id' => isset($this->userPhase) ? $this->userPhase->current_phase_id : '',
            'phase_day' => isset($this->userPhase) ? $this->userPhase->getCurrentPhaseDay() : '',
            'phase_amount_days' => isset($this->userPhase) && isset($this->userPhase->phase) ? $this->userPhase->phase->days : '',
            'date_started' => isset($this->userPhase) ? $this->userPhase->date_started : ''
        ];
        return $response;
    }

    public function sendEmail($password = '') {
		try {
			$message = \Yii::$app->mailer->compose(['html'=>'facebookSignUp-html'], ['user' => $this, 'password' => $password]);
			$message->setFrom([\Yii::$app->params['supportEmail'] => 'Your Company Name']);
			$message->setTo($this->email);
			$message->setSubject('Welcome to ' . \Yii::$app->name);
			return $message->send();
		} catch(ErrorException $e) {
			return false;
        }
    }

    public function getUserPhase() {
        return $this->hasOne(UserPhase::className(), ['user_id' => 'id']);
    }

    public function getAssignmentRole() {
        return $this->hasOne(AuthAssignment::className(), ['user_id' => 'id']);
    }

}
