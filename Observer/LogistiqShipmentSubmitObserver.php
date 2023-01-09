<?php

namespace Logistiq\LogistiqShipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface;
use \Exception as Exception;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\ResourceConnection;


class LogistiqShipmentSubmitObserver implements ObserverInterface
{
    protected $scopeConfig;
    protected $_curl;
    protected $messageManager;
    protected $logger;
    protected $orderModel;
    protected $trackFactory;
    protected $resourceConnection;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $_curl
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     * @param Order $orderModel
     * @param TrackFactory $trackFactory
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ScopeConfigInterface $scopeConfig,
                                Curl                 $_curl,
                                ManagerInterface     $messageManager,
                                LoggerInterface      $logger,
                                Order                $orderModel,
                                TrackFactory         $trackFactory,
                                ResourceConnection   $resourceConnection
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->_curl = $_curl;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->orderModel = $orderModel;
        $this->trackFactory = $trackFactory;
        $this->resourceConnection = $resourceConnection;
    }


    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        try {
            $shipment = $observer->getEvent()->getShipment();
            $order = $shipment->getOrder();
            $store_scope = ScopeInterface::SCOPE_STORE;
            $carriers = $this->scopeConfig->getValue("carriers", $store_scope);
            $shippingMethod = $order->getShippingMethod();

            if (strcmp($shippingMethod, 'logistiqshipping_logistiqshipping') == 0 &&
                array_key_exists("logistiqshipping", $carriers)) {
                $logistiqCarriersConfig = $carriers["logistiqshipping"];
                $this->processBookOrder($logistiqCarriersConfig, $shipment, $order);
            } else {
                $this->logger->info('Logistiq Skipping to process the order ' . $order->getIncrementId()
                    . 'because current shipping method is: ' . $shippingMethod);
            }
        } catch (Exception $e) {
            $this->logger->debug('Exception occurred ' . $e->getMessage());
            $this->messageManager->addError($e->getMessage());
            throw new LocalizedException(
                __("Logistiq: Please contact support team")
            );
        }
    }

    /**
     * @throws LocalizedException
     */
    private function processBookOrder($logistiqCarriersConfig, $shipment, $order)
    {
        $order_booking_request = $this->mapTheData($order);
        $logistiqOrderbookingResponse = $this->invokeOrderBookingService($order_booking_request, $logistiqCarriersConfig);
        if (!empty($logistiqOrderbookingResponse) && array_key_exists("status", $logistiqOrderbookingResponse)
            && $logistiqOrderbookingResponse["status"]) {
            if (count($logistiqOrderbookingResponse["data"]) > 0 && $logistiqOrderbookingResponse["data"][0]["status"]
                && !empty($logistiqOrderbookingResponse["data"][0]["cp_awb"])) {
                $this->addTrackingInfo(
                    $logistiqOrderbookingResponse["data"][0]["cp_awb"],
                    $order,
                    $logistiqCarriersConfig,
                    $logistiqOrderbookingResponse["data"][0]["url"],
                    $shipment
                );
            } else {
                if (count($logistiqOrderbookingResponse["data"]) > 0) {
                    throw new LocalizedException(
                        __($logistiqOrderbookingResponse["data"][0]["message"])
                    );
                } else {
                    throw new LocalizedException(
                        __("Unable to Book the order with Logistiq")
                    );
                }
            }
        } else {
            throw new LocalizedException(
                __("Please try Some other time")
            );
        }
    }

    private function mapTheData($order)
    {
        $totalItemQty = $this->totalQty($order);
        $shipping = $order->getShippingAddress();
        $order = $this->orderModel->load($order->getId());
        $sku_and_descriptions = $this->getSKUDescription($order);
        $orderTypeAndTotal = $this->getOrderType($order);
        $invoice = $this->getInvoiceInfo($order);
        $params = [
            "customer_email" =>($shipping) ? $shipping->getEmail() : '',
            "customer_name" => ($shipping) ? $shipping->getName() : '',
            "customer_address" => $this->getAddressData($shipping),
            "customer_city" => ($shipping) ? $shipping->getData('city') : '',
            "customer_phone" => ($shipping) ? $shipping->getData('telephone') : '',
            "sku_description" => $sku_and_descriptions[1],
            "sku" => $sku_and_descriptions[0],
            "weight" => round($order->getWeight(), 2),
            "order_ref_number" => $order->getIncrementId(),
            "vendor_code" => $order->getId(),
            "order_date" => date("Y-m-d\TH:i:s", strtotime($order->getCreatedAt())),
            "qty" => $totalItemQty,
            "order_type" => $orderTypeAndTotal[0],
            "invoice_value" => $orderTypeAndTotal[1],
            "cod_value" => $orderTypeAndTotal[0] == 'PREPAID' ? 0 : $orderTypeAndTotal[1],
            "delivery_type" => "FORWARD",
            "invoice_date" => ($invoice) ? date_format(date_create($invoice->getData('created_at')), "d/m/Y") : date("d/m/Y"),
            "invoice_number" => ($invoice) ? $invoice->getIncrementId() : $order->getIncrementId()
        ];
        return json_encode($params);
    }

    private function getAddressData($shipping): string
    {
        if ($shipping) {
            return $shipping->getData('street') . ", " . $shipping->getData('city') . ", " . $shipping->getData('postcode');
        }
         else {
             return '';
         }
    }

    private function getSKUDescription($order): array
    {
        $desc = '';
        $sku = '';
        foreach ($order->getAllVisibleItems() as $itemName) {
            if ($itemName->getQtyOrdered() > $itemName->getQtyShipped()) {
                $sku != "" && $sku .= ",";
                $sku .= $itemName->getId();
                $desc != "" && $desc .= ",";
                $desc .= trim($itemName->getName());
            }
        }
        return [$sku, $desc];
    }

    /**
     * @param $order
     */
    private function totalQty($order)
    {
        $totalItems = 0;
        $orderItems = $order->getAllItems();
        foreach ($orderItems as $item) {
            $totalItems = $totalItems + $item->getQtyOrdered();
        }
        return intval($totalItems);
    }

    private function getOrderType(Order $order): array
    {
        $type = 'PREPAID';
        $total_amnt = 0;
        $isOffline = $order->getPayment()->getMethodInstance()->isOffline();
        if ($isOffline) {
            $type = "COD";
            $total_amnt = $order->getData('grand_total');
        }
        return [$type, $total_amnt];
    }

    private function getInvoiceInfo(Order $order)
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            return $invoice;
        }
    }

    private function invokeOrderBookingService(
        $order_booking_request,
        $logistiqCarriersConfig
    ) {
        $this->logger->info("Processing invokeOrderBookingService");
        try {
            $uri = $logistiqCarriersConfig["url"];
            $uID = $logistiqCarriersConfig["user_id"];
            $uPwd = $logistiqCarriersConfig["u_pwd"];
            $loginAPIRes = $this->invokeLoginAPIService($uri, $uID, $uPwd);
            if (!empty($loginAPIRes) && array_key_exists("status", $loginAPIRes) && $loginAPIRes["status"]) {
                $token = $loginAPIRes['token'];
                $orderBookRes = $this->invokeBookOrderAPIService($uri, $order_booking_request, $token);
                if (!empty($orderBookRes) && array_key_exists("status", $orderBookRes) && $orderBookRes["status"]) {
                    $this->logger->debug("Order Booking Successfully");
                    $this->logger->debug(json_encode($orderBookRes));
                    return $orderBookRes;
                } elseif (!empty($orderBookRes) && array_key_exists("status", $orderBookRes) && !$orderBookRes["status"]){
                    if(!empty($orderBookRes['data']) and count($orderBookRes['data']) > 0) {
                        throw new LocalizedException(
                            __($orderBookRes['data'][0]['message'])
                        );
                    } else {
                        throw new LocalizedException(
                            __($orderBookRes['detail'])
                        );
                    }
                } else {
                    $this->logger->debug("Unable to Book the Order with Logistiq:");
                    $this->logger->debug(json_encode($orderBookRes));
                    return "";
                }
            } else {
                $this->logger->debug("unable to get the Token from Logistiq");
                $this->logger->debug(json_encode($loginAPIRes));
                return "";
            }
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(
                __($exception->getMessage())
            );
        }
    }

    public function invokeLoginAPIService($uri, $uID, $uPwd)
    {
        try {
            $url = "$uri/auth/api/v1/accounts/login";
            $headers = ["Content-Type" => "application/json"];
            $payload = [
                "email" => $uID,
                "password" => $uPwd
            ];
            $this->_curl->setHeaders($headers);
            $this->_curl->post($url, json_encode($payload));
            $response = $this->_curl->getBody();
            $statusCode = $this->_curl->getStatus();
            if (!empty($response) && $statusCode == 200) {
                return json_decode($response, true);
            }
            return "";

        } catch (\Exception $exception) {
            $this->logger->error("invokeLoginAPIService Exception Occurred" . $exception->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(
                __($exception->getMessage())
            );
        }
    }

    /**
     * @throws LocalizedException
     */
    private function invokeBookOrderAPIService($uri, $order_booking_request, string $token)
    {
        try {
            $url = "$uri/auth/api/v1/orders/order-create";
            $headers = ["Content-Type" => "application/json", "Authorization" => 'Bearer ' . $token];
            $this->_curl->setHeaders($headers);
            $this->_curl->post($url, $order_booking_request);
            $response = $this->_curl->getBody();
            $statusCode = $this->_curl->getStatus();
            if ($statusCode == 200 && !empty($response)) {
                return json_decode($response, true);
            } elseif ($statusCode == 400 && !empty($response)) {
                return json_decode($response, true);
            } else {
                return "";
            }
        } catch (\Exception $exception) {
            $this->logger->error("invokeBookOrderAPIService Exception Occurred " . $exception->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(
                __($exception->getMessage())
            );
        }
    }

    private function addTrackingInfo($awb, $order, $logistiqCarriersConfig, $pdfURL, $shipment)
    {
        $track = $this->trackFactory->create();
        $track->setNumber($awb);
        $track->setCarrierCode("logistiqshipping");
        $track->setTitle("Logistiq Tracking Number");
        $track->setDescription("Logistiq Shipment Tracking Info");
        # $track->setUrl($logistiqCarriersConfig["track_url"]."/#/order/tracking?awb=".$awb);
        $shipment->addTrack($track);
        $shipment->getOrder()->addCommentToStatusHistory("Successfully Booked the order and To
        download waybill " . $pdfURL);
        try {
            // Save created Order Shipment
            $shipment->save();
            $shipment->getOrder()->save();
            $this->messageManager->addSuccess(__('SuccessFully booked order with Logistiq'));
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage())
            );
        }
    }


}
