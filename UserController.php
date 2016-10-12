<?php

namespace app\modules\api\controllers;

use app\helpers\ResponseHelper;
use app\models\LoginForm;
use app\models\PasswordResetRequestForm;
use app\models\Phase;
use app\models\ResetPasswordForm;
use app\models\User;
use app\models\UserAccessTokens;
use app\models\SignupForm;
use app\models\UserPhase;
use ErrorException;
use Yii;
use yii\base\InvalidParamException;
use yii\db\Query;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\rest\ActiveController;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class UserController extends ActiveController
{
    public $modelClass = 'app\models\User';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['contentNegotiator'] = [
            'class' => ContentNegotiator::className(),
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
            ],
        ];

        $behaviors['authenticator'] = [
            'class' => HttpBearerAuth::className(),
            'except' => ['login', 'fb-sign-up', 'fb-login', 'sign-up','request-password-reset']
        ];

        $behaviors['corsFilter'] = [
            'class' => Cors::className(),
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        return $behaviors;
    }

    public function actionFbLogin() {
        $access_token = Yii::$app->request->post('access_token');
        $GraphUser = Yii::$app->facebookAuth->getGraphUser($access_token);
        $email = $GraphUser->getEmail();

        if (!is_object($GraphUser)) {
            return ResponseHelper::error(['facebook-error' => $GraphUser]);
        }
        if (!isset($email) || empty($email)) {
            return ResponseHelper::error(['email' => 'The email field was not returned. This may be because the email was missing, invalid or hasn\'t been confirmed. Please make sure that you didn\'t forget to specify an email address for your facebook account.']);
        }

        $user = User::findByEmail($email);
        if ($user) {
            $userAccessTokens = new UserAccessTokens();
            $user->generateAuthKey();
            $userAccessTokens->saveUserAccessToken($user->getId(), $user->getAuthKey());

            if (!$user->expire_date || $user->expire_date == 0) {
                $user->expire_date = $user->initExpireDate();
                if (!$user->save()) return ResponseHelper::error($user->getErrors());
            }

            $data = $user->getResponseData();
            return ResponseHelper::success($data);
        }
        return ResponseHelper::error(['user' => 'User not exists']);
    }

    public function actionFbSignUp() {
        $access_token = Yii::$app->request->post('access_token');
        $GraphUser = Yii::$app->facebookAuth->getGraphUser($access_token);

        if (!is_object($GraphUser)) {
            return ResponseHelper::error(['facebook-error' => $GraphUser]);
        }

        $email = $GraphUser->getEmail();
        $first_name = $GraphUser->getName();
        $password = Yii::$app->security->generateRandomString(6);
        $SignupForm = new SignupForm();

        if (!isset($email) || empty($email)) {
            return ResponseHelper::error(['email' => 'The email field was not returned. This may be because the email was missing, invalid or hasn\'t been confirmed. Please make sure that you didn\'t forget to specify an email address for your facebook account.']);
        }
        if ($SignupForm->load(['email' => $email, 'username' => $email, 'first_name' => $first_name, 'password' => $password, 'password_repeat' => $password], '')) {
            if ($user = $SignupForm->signup()) {
                if (Yii::$app->getUser()->login($user)) {
                    try {
                        $user->sendEmail($password);
                    } catch (ErrorException $e) {
                    }

                    $userAccessTokens = new UserAccessTokens();
                    Yii::$app->user->identity->generateAuthKey();
                    $userAccessTokens->saveUserAccessToken($user->id, Yii::$app->user->identity->getAuthKey());

                    $userPhase = new UserPhase();
                    $userPhase->saveUserPhase(Phase::getDefaultPhaseId(), $user->id, $user->created_at);

                    $data = $user->getResponseData();
                    return ResponseHelper::success($data);
                }
            }
            return ResponseHelper::error($SignupForm->getErrors());
        }
    }

    /**
     * Logs in a user.
     */

    public function actionLogin() {
        $model = new LoginForm();

        if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->login()) {
            $user = Yii::$app->user->identity;

            $userAccessTokens = new UserAccessTokens();
            $user->generateAuthKey();
            $userAccessTokens->saveUserAccessToken($user->getId(), $user->getAuthKey());

            if (!$user->expire_date || $user->expire_date == 0) {
                $user->expire_date = $user->initExpireDate();
                if (!$user->save()) return ResponseHelper::error($user->getErrors());
            }

            $data = $user->getResponseData();
            return ResponseHelper::success($data);
        } else {
            return ResponseHelper::error($model->getErrors());
        }
    }

    /**
     * Logs out the current user.
     */

    public function actionLogOut() {
        $authHeader = Yii::$app->getRequest()->getHeaders()->get('Authorization');

        if ($authHeader !== null && preg_match("/^Bearer\\s+(.*?)$/", $authHeader, $matches)) {
            (new Query)->createCommand()->delete(UserAccessTokens::tableName(), ['access_token'=>$matches[1]])->execute();
                return ['status' =>1, 'message'=>'Log out'];
        }
    }

    /**
     * Sign Up
     */

    public function actionSignUp() {
        $model = new SignupForm();

        if ($model->load(Yii::$app->getRequest()->getBodyParams(), '')) {
            if ($user = $model->signup()) {
                if (Yii::$app->getUser()->login($user)) {

                    $userAccessTokens = new UserAccessTokens();
                    Yii::$app->user->identity->generateAuthKey();
                    $userAccessTokens->saveUserAccessToken($user->id,Yii::$app->user->identity->getAuthKey());

                    $userPhase = new UserPhase();
                    $userPhase->saveUserPhase(Phase::getDefaultPhaseId(), $user->id, $user->created_at);

                    $data = $user->getResponseData();
                    return ResponseHelper::success($data);
                }
            }
            return ResponseHelper::error($model->getErrors());
        }
    }

    /**
     * Change Name
     * $first_name
     */
    public function actionChangeName() {
        $first_name = Yii::$app->request->post('first_name');
        $user = Yii::$app->user->identity;
        $user->scenario = 'change-name';
        $user->first_name = $first_name;
        if ($user->save()) {
            return ResponseHelper::success(['first_name' => $user->first_name]);
        }
        return ResponseHelper::error($user->getErrors());
    }

    /**
     * Change Email
     * $email
     */

    public function actionChangeEmail() {
        $new_email = Yii::$app->request->post('new_email');
        $user = Yii::$app->user->identity;
        if ($new_email === $user->email) return ResponseHelper::error(['email' => 'This email has already been taken']);

        $user->email = $new_email;

        if ($user->save()) {
            return ResponseHelper::success(['new_email' => $user->email]);
        }

        return ResponseHelper::error($user->getErrors());
    }

    /**
     * Change username
     * $username
     */

    public function actionChangeUsername() {
        $username = Yii::$app->request->post('new_username');
        $user = Yii::$app->user->identity;

        $user->username = $username;

        if ($user->save()) {
            return ResponseHelper::success(['username' => $user->username]);
        }

        return ResponseHelper::error($user->getErrors());
    }

    /**
     * Change Password
     * $email, $new_password
     */
    public function actionChangePassword() {
        $new_password = Yii::$app->request->post('new_password');
        $model = new PasswordResetRequestForm();

        if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
            $password_reset_token = $model->getPasswordResetToken();

            try {
                $ResetPasswordModel = new ResetPasswordForm($password_reset_token);
            } catch (InvalidParamException $e) {
                throw new BadRequestHttpException($e->getMessage());
            }

            $ResetPasswordModel->password = $new_password;
            if ($ResetPasswordModel->validate() && $ResetPasswordModel->resetPassword()) {
                return ResponseHelper::success(['new_password' => 'New password was saved.']);
            }
            return ResponseHelper::error($ResetPasswordModel->getErrors());
        }
        return ResponseHelper::error($model->getErrors());
    }
    /**
     * Password Reset
     * $email
     */
    public function actionRequestPasswordReset() {

        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
            if ($model->sendEmail()) {
                return ResponseHelper::success(['success' => 'Check your email for further instructions.']);
            } else {
                return ResponseHelper::success(['error' => 'Sorry, we are unable to reset password for email provided.']);
            }
        }
        return ResponseHelper::error($model->getErrors());
    }

}
