<?php

namespace Logistiq\LogistiqShipping\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use \Exception as Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Convert\Order;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Shipping\Model\ShipmentNotifier;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;


class LogistiqSubmitObserver implements ObserverInterface
{

    protected $scopeConfig;

    protected $_curl;

    protected $shipmentFactory;

    protected $orderModel;

    protected $trackFactory;

    protected $messageManager;

    protected $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $_curl
     * @param Order $orderModel
     * @param TrackFactory $trackFactory
     * @param ShipmentNotifier $shipmentFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl  $_curl,
        Order $orderModel,
        TrackFactory  $trackFactory,
        ShipmentNotifier $shipmentFactory,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->_curl = $_curl;
        $this->shipmentFactory = $shipmentFactory;
        $this->orderModel = $orderModel;
        $this->trackFactory = $trackFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return $this|void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        try {
            $this->logger->info('=== Entering LogistiqSubmitObserver execute function ===');
            $store_scope = ScopeInterface::SCOPE_STORE;
            # $carrier = $this->scopeConfig->getValue("carriers/logistiqshipping/name", $store_scope);
            $carriers = $this->scopeConfig->getValue("carriers", $store_scope);
            $invoice = $observer->getInvoice();
            $order = $invoice->getOrder();
            $json = json_encode($order->getData());
            $this->logger->info($json);
            $shippingMethod = $order->getShippingMethod();
            $this->logger->debug($order->getIncrementId());
            $this->logger->debug($shippingMethod);
            if (!$order->canShip()) {
                throw new LocalizedException(
                    __('You can\'t create an shipment.')
                );
            }
            if (strcmp($shippingMethod, 'logistiqshipping_logistiqshipping') == 0 &&
                array_key_exists("logistiqshipping", $carriers)) {
                $this->logger->info('Logistiq process this order');
                $logistiqCarriersConfig = $carriers["logistiqshipping"];

                $logistiqBookOrderDetails = $this->processBookOrderWithLogistiq(
                    $order,
                    $logistiqCarriersConfig,
                    $invoice
                );

                if (!empty($logistiqBookOrderDetails) && array_key_exists("status", $logistiqBookOrderDetails)
                    && $logistiqBookOrderDetails["status"]) {
                    if (count($logistiqBookOrderDetails["data"]) > 0 && $logistiqBookOrderDetails["data"][0]["status"]
                        && !empty($logistiqBookOrderDetails["data"][0]["cp_awb"])) {
                        $this->logisticMagentoCreateShipment(
                            $logistiqBookOrderDetails["data"][0]["cp_awb"],
                            $order,
                            $logistiqCarriersConfig,
                            $logistiqBookOrderDetails["data"][0]["url"]
                        );
                    } else {
                        if (count($logistiqBookOrderDetails["data"]) > 0) {
                            throw new LocalizedException(
                                __($logistiqBookOrderDetails["data"][0]["message"])
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
            } else {
                $this->logger->info('Logistiq Skipping to process the order ' . $order->getIncrementId()
                    . 'because current shipping method is: ' . $shippingMethod);
            }
            $this->logger->info('=== Exiting LogistiqSubmitObserver execute function ===');

        } catch (Exception $e) {
            $this->logger->debug('Exception occurred ' . $e->getMessage());
            $this->messageManager->addError($e->getMessage());
            throw new LocalizedException(
                __("Logistiq: Please contact support team")
            );
        }
        return $this;
    }

    /**
     * @param $order
     * @param $logistiqCarriersConfig
     * @param $invoice
     * @return mixed|string
     */
    private function processBookOrderWithLogistiq($order, $logistiqCarriersConfig, $invoice)
    {
        $this->logger->info('Inside processBookOrderWithLogistiq ' . $order->getIncrementId());
        $map_logsitqi_order_booking_request = $this->mapTheDataToOrderBookWithLogistiq($order, $invoice);
//        return $this->invokeOrderBooking($map_logsitqi_order_booking_request, $logistiqCarriersConfig);
        return '';
    }

    /**
     * @param $order
     * @param $invoice
     * @return false|string
     */
    private function mapTheDataToOrderBookWithLogistiq($order, $invoice)
    {
        $shipping_address = $order->getShippingAddress();
        $this->logger->info(json_encode($shipping_address->getData()));
        $sku_and_descriptions = $this->getSKUFromItems($order->getItems());
        $name = $this->getName($shipping_address);
        $_data_array = [
            "customer_email" => $shipping_address->getEmail(),
            "customer_name" => $name,
            "customer_address" => $this->getAddress($shipping_address),
            "customer_city" => $shipping_address->getCity(),
            "customer_phone" => $shipping_address->getTelephone(),
            "invoice_value" => $invoice->getBaseGrandTotal(),
            "cod_value" => "0",
            "invoice_number" => $invoice->getIncrementId(),
            "order_type" => "PREPAID",
            "order_ref_number" => $order->getIncrementId(),
            "sku_description" => $sku_and_descriptions[1],
            "sku" => $sku_and_descriptions[0],
            "qty" => $order->getTotalQtyOrdered(),
            "delivery_type" => "FORWARD",
            "invoice_date" => date_format(date_create($invoice->getCreatedAt()), "d/m/Y"),
            "vendor_code" => $order->getId(),
            "order_date" => date("Y-m-d\TH:i:s", strtotime($order->getCreatedAt())),
            "weight" => round($order->getWeight(), 2)
        ];
        $json = json_encode($_data_array);
        $this->logger->info($json);
        return $json;
    }

    /**
     * @param $items
     * @return string[]
     */
    private function getSKUFromItems($items): array
    {
        $sku = "";
        $desc = "";
        foreach ($items as $item) {
            $sku != "" && $sku .= ",";
            $sku .= $item['sku'];

            $desc != "" && $desc .= ",";
            $desc .= $item['name'];
        }
        return [$sku, $desc];
    }

    /**
     * @param $map_logsitqi_order_booking_request
     * @param $logistiqCarriersConfig
     * @return mixed|string
     */
//    private function invokeOrderBooking(
//        $map_logsitqi_order_booking_request,
//        $logistiqCarriersConfig
//    ) {
//        $this->logger->info("Processing invokeOrderBooking");
//        try {
//            $uri = $logistiqCarriersConfig["url"];
//            $uID = $logistiqCarriersConfig["user_id"];
//            $uPwd = $logistiqCarriersConfig["u_pwd"];
//            $loginAPIRes = $this->invokeLoginAPI($uri, $uID, $uPwd);
//            if (!empty($loginAPIRes) && array_key_exists("status", $loginAPIRes) && $loginAPIRes["status"]) {
//                $token = $loginAPIRes['token'];
//                $orderBookRes = $this->invokeBookOrderAPI($uri, $map_logsitqi_order_booking_request, $token);
//                if (!empty($orderBookRes) && array_key_exists("status", $orderBookRes) && $orderBookRes["status"]) {
//                    $this->logger->debug("Order Booking Successfully");
//                    $this->logger->debug(json_encode($orderBookRes));
//                    return $orderBookRes;
//                } elseif (!empty($orderBookRes) && array_key_exists("status", $orderBookRes) && !$orderBookRes["status"]){
//                    throw new LocalizedException(
//                        __($orderBookRes['detail'])
//                    );
//                } else {
//                    $this->logger->debug("Unable to Book the Order with Logistiq:");
//                    $this->logger->debug(json_encode($orderBookRes));
//                    return "";
//                }
//            } else {
//                $this->logger->debug("unable to get the Token from Logistiq");
//                $this->logger->debug(json_encode($loginAPIRes));
//                return "";
//            }
//        } catch (\Exception $exception) {
//            $this->logger->error($exception->getMessage());
//            throw new \Magento\Framework\Exception\LocalizedException(
//                __($exception->getMessage())
//            );
//        }
//    }

    /**
     * @param $carriers
     * @return string
     */
//    public function invokeLoginAPI($uri, $uID, $uPwd)
//    {
//        try {
//            $url = "$uri/auth/api/v1/accounts/login";
//            $headers = ["Content-Type" => "application/json"];
//            $payload = [
//                "email" => $uID,
//                "password" => $uPwd
//            ];
//            $this->_curl->setHeaders($headers);
//            $this->_curl->post($url, json_encode($payload));
//            $response = $this->_curl->getBody();
//            $statusCode = $this->_curl->getStatus();
//            if (!empty($response) && $statusCode == 200) {
//                return json_decode($response, true);
//            }
//            return "";
//
//        } catch (\Exception $exception) {
//            $this->logger->error("invokeLoginAPI Exception Occurred" . $exception->getMessage());
//            throw new \Magento\Framework\Exception\LocalizedException(
//                __($exception->getMessage())
//            );
//        }
//    }

    /**
     * @param $uri
     * @param $map_logsitqi_order_booking_request
     * @param string $token
     * @return mixed|string
     */
//    private function invokeBookOrderAPI($uri, $map_logsitqi_order_booking_request, string $token)
//    {
//        try {
//            $url = "$uri/auth/api/v1/orders/order-create";
//            $headers = ["Content-Type" => "application/json", "Authorization" => 'Bearer ' . $token];
//            $this->_curl->setHeaders($headers);
//            $this->_curl->post($url, $map_logsitqi_order_booking_request);
//            $response = $this->_curl->getBody();
//            $statusCode = $this->_curl->getStatus();
//            if ($statusCode == 200 && !empty($response)) {
//                return json_decode($response, true);
//            } elseif ($statusCode == 400 && !empty($response)) {
//                return json_decode($response, true);
//            } else {
//                return "";
//            }
//        } catch (\Exception $exception) {
//            $this->logger->error("invokeBookOrderAPI Exception Occurred " . $exception->getMessage());
//            throw new \Magento\Framework\Exception\LocalizedException(
//                __($exception->getMessage())
//            );
//        }
//    }

    /**
     * @param $awb
     * @param $order
     * @param $logistiqCarriersConfig
     * @param $pdfURL
     * @return void
     * @throws LocalizedException
     */
    private function logisticMagentoCreateShipment($awb, $order, $logistiqCarriersConfig, $pdfURL)
    {
        $shipment = $this->orderModel->toShipment($order);
        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }
            $qtyShipped = $orderItem->getQtyToShip();
            $shipmentItem = $this->orderModel->itemToShipmentItem($orderItem)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $track = $this->trackFactory->create();
        $track->setNumber($awb);
        $track->setCarrierCode("logistiqshipping");
        $track->setTitle("Logistiq Tracking Number");
        $track->setDescription("Logistiq Shipment Tracking");
        # $track->setUrl($logistiqCarriersConfig["track_url"]."/#/order/tracking?awb=".$awb);
        $shipment->addTrack($track);

        $shipment->register();
        $shipment->getOrder()->setIsInProcess(true);
        $shipment->getOrder()->addCommentToStatusHistory("Successfully Booked the order and To
        download waybill " . $pdfURL);
        try {
            // Save created Order Shipment
            $shipment->save();
            $shipment->getOrder()->save();
//            // Send Shipment Email
//            $this->shipmentFactory->notify($shipment);
//            $shipment->save();
            $this->messageManager->addSuccess(__('Shipment Generated SuccessFully'));
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage())
            );
        }
    }

    /**
     * @param $shipping_address
     * @return string
     */
    private function getName($shipping_address): string
    {
        return $shipping_address->getFirstname() . " " . $shipping_address->getMiddlename()
            . " " . $shipping_address->getLastname();
    }

    /**
     * @param $shipping_address
     * @return string
     */
    public function getAddress($shipping_address): string
    {
        return $shipping_address->getStreet()[0] . ", " . $shipping_address->getPostcode()
            . ", " . $shipping_address->getCity();
    }
}
