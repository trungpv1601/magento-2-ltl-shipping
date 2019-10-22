<?php

namespace Trungpv1601\Magento2LTLShipping\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Framework\Xml\Security;

class Shipping extends \Magento\Shipping\Model\Carrier\AbstractCarrierOnline implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    const RATE_SERVICE_URL = 'https://api.rlcarriers.com/1.0.3/RateQuoteService.asmx?WSDL';

    private $apiKey = false;

    protected $_code = 'ltl';

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionModel;

    /**
     * @var \Magento\Framework\Webapi\Soap\ClientFactory
     */
    protected $clientFactory;

    /**
     * Undocumented function
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array $data
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Directory\Model\RegionFactory $regionModel
     * @param \Magento\Framework\Webapi\Soap\ClientFactory $clientFactory
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        array $data = [],
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Directory\Model\RegionFactory $regionModel,
        \Magento\Framework\Webapi\Soap\ClientFactory $clientFactory
    ) {
        $this->encryptor = $encryptor;
        $this->regionModel = $regionModel;
        $this->clientFactory = $clientFactory;
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }

    /**
     * get allowed methods
     * @return array
     */
    public function getAllowedMethods()
    { }



    public function processAdditionalValidation(\Magento\Framework\DataObject $request)
    {
        return true;
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
    public function collectRates(RateRequest $request)
    {
        // Dest Location
        $destLocation = $this->loadDestLocation($request);

        if (!$this->getConfigFlag('active') || !$destLocation) {
            return false;
        }

        try {
            $result = $this->_rateFactory->create();

            $this->apiKey = $this->encryptor->decrypt($this->getConfigData('api_key'));

            $weight = $request->getPackageWeight();

            // Orig
            $orig_country_id = $request->getOrigCountryId();
            $orig_city = $request->getOrigCity();
            $orig_postcode = $request->getOrigPostcode();
            $orig_region_id = $request->getOrigRegionId();
            $regionOrig = $this->regionModel->create()->load($orig_region_id);
            $orig_region_code = $regionOrig->getCode();
            $origLocation = [
                'orig_country_id' => $orig_country_id,
                'orig_city' => $orig_city,
                'orig_postcode' => $orig_postcode,
                'orig_region_id' => $orig_region_id,
                'orig_region_code' => $orig_region_code,
                'weight' => $weight,
                'apiKey' => $this->apiKey
            ];

            $data = array_merge($destLocation, $origLocation);

            $shippingQuote =  $this->getRateQuoteService($data);
            $shippingMethods = $this->getShippingRates($shippingQuote);

            if ($shippingMethods) {
                $shippingMethod = $shippingMethods[0][0];
                // Remove api provided '$' from string
                $price = ltrim($shippingMethod->NetCharge, '$');

                // * @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method 
                $method = $this->_rateMethodFactory->create();

                $method->setCarrier($this->_code);
                $method->setCarrierTitle($this->getConfigData('title'));

                $method->setMethod($shippingMethod->Code);

                if (isset($shippingMethod->HourlyWindow)) {
                    $method->setMethodTitle($shippingMethod->Title
                        . ': Between ' .
                        $shippingMethod->HourlyWindow->Start
                        . ' - ' .
                        $shippingMethod->HourlyWindow->End);
                } else {
                    $method->setMethodTitle($shippingMethod->Title);
                }

                $method->setPrice($price);
                $method->setCost($price);

                $result->append($method);

                return $result;
            }
        } catch (\Exception $e) {
            $this->_logger->critical('Error message', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Load Dest Location
     */
    private function loadDestLocation($request)
    {
        $result = false;
        $dest_country_id = $request->getDestCountryId();
        $dest_city = $request->getDestCity();
        $dest_postcode = $request->getDestPostcode();
        $dest_region_code = $request->getDestRegionCode();
        if ($dest_country_id != null && $dest_postcode != null && $dest_region_code != null) {
            $result = [
                'dest_country_id' => $dest_country_id,
                'dest_city' => $dest_city,
                'dest_postcode' => $dest_postcode,
                'dest_region_code' => $dest_region_code
            ];
        }

        return $result;
    }

    /**
     * Undocumented function
     *
     * @param [type] $payload
     * @return void
     */
    private function getRateQuoteService($payload)
    {
        $client = $this->clientFactory->create(self::RATE_SERVICE_URL);

        $ratesRequest = [
            'APIKey' => $payload['apiKey'],
            'request' => [
                'QuoteType' => 'Domestic',
                'CODAmount' => 0.0, // decimal
                'Origin' => [
                    'City' => $payload['orig_city'],
                    'StateOrProvince' => $payload['orig_region_code'],
                    'ZipOrPostalCode' => $payload['orig_postcode'],
                    'CountryCode' => $payload['orig_country_id'],
                ],
                'Destination' => [
                    'City' => $payload['dest_city'],
                    'StateOrProvince' => $payload['dest_region_code'],
                    'ZipOrPostalCode' => $payload['dest_postcode'],
                    'CountryCode' => $payload['dest_country_id'],
                ],
                'Items' => [
                    [
                        'Class' => 200.0,
                        'Weight' => 100,
                        'Width' => 0,
                        'Height' => 0,
                        'Length' => 0,
                    ]
                ],
                'DeclaredValue' => 0.0, // decimal
                'Accessorials' => [],
                'OverDimensionList' => [], // int
                'Pallets' => []
            ]
        ];

        return $client->GetRateQuote($ratesRequest);
    }

    /**
     *
     * Capture shipping method options
     *
     * @param object $shippingMethods
     * @return array|bool
     */
    protected function getShippingRates($shippingRates)
    {
        if (!$shippingRates->GetRateQuoteResult->WasSuccess) {
            return false;
        }

        $messages = $shippingRates->GetRateQuoteResult->Result->Messages;
        $shippingRates = $shippingRates->GetRateQuoteResult->Result->ServiceLevels->ServiceLevel;

        return [$shippingRates, $messages];
    }

    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    { }
}
