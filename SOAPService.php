<?php
namespace app\components;

use SoapFault;
use yii\base\Component;
use SoapClient;
use Yii;

class SOAPService extends Component
{
    public $host = '';
    public $api_user = '';
    public $api_key = '';

    /**
     * @var SoapClient
     */
    private $client;
    private $session;

    public function init()
    {
        $this->createSoapClient();
        parent::init();
    }

    protected function createSoapClient() {
        ini_set("soap.wsdl_cache_enabled", "0");
        $this->client = new SoapClient("http://" . $this->host . "/api/soap/?wsdl", ['trace' => 1]);
        try {
            $this->session = $this->client->login($this->api_user, $this->api_key);
        } catch (SoapFault $fault) {
            return $fault->faultstring;
        }
    }

    public function getProductImages($product_id) {
        try {
        $productListing = $this->client->call($this->session, 'catalog_product_attribute_media.list', $product_id);
            return $productListing;
        } catch (SoapFault $fault) {
            return $fault->faultstring;
        }
    }
}