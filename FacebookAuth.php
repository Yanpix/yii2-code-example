<?php
namespace app\components;

use app\helpers\ResponseHelper;
use app\models\Settings;
use Facebook\Facebook;
use Facebook\Exceptions\FacebookAuthenticationException;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use yii\base\Component;
use Yii;

class FacebookAuth extends Component {

    public $app_id = '';
    public $app_secret = '';

    private $fb;

    public function init() {
        $this->createFacebookInstance();
        parent::init();
    }

    private function createFacebookInstance() {
        $this->fb = new Facebook([
            'app_id' => $this->app_id,
            'app_secret' => $this->app_secret,
            'default_graph_version' => 'v2.6',
        ]);
    }

    public function getGraphUser($access_token) {
        try {
            $response = $this->fb->get('/me?fields=id,name,email', $access_token);
            $me = $response->getGraphUser();
        } catch(FacebookResponseException $e) {
            // When Graph returns an error
            return $e->getMessage();
        } catch(FacebookSDKException $e) {
            // When validation fails or other local issues
            return $e->getMessage();
        }
        return $me;
    }

}