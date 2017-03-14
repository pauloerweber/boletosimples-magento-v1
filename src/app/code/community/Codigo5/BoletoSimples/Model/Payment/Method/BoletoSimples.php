<?php

class Codigo5_BoletoSimples_Model_Payment_Method_BoletoSimples extends Mage_Payment_Model_Method_Abstract
{
    const CODE = 'boletosimples';

    protected $_code = self::CODE;

    protected $_canUseInternal         = true;
    protected $_canUseCheckout         = true;
    protected $_canUseForMultishipping = true;
    protected $_isInitializeNeeded     = true;

    public function initialize($action, $stateObject)
    {
        if ($action === 'init') {
            $status = $this->getConfigData('order_status');

            if ($status) {
                $stateObject->setStatus($status);

                $helper = Mage::helper('codigo5_boletosimples/payment');
                $state = $helper->getStateByStatus($status);

                $stateObject->setState($state);
                $stateObject->setIsNotified(true);
            }
        }

        return $this;
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('codigo5_boletosimples/payment/request');
    }

    public function process(Mage_Sales_Model_Order $order)
    {
        $helper = Mage::helper('codigo5_boletosimples/payment');
        $helper->ensureLibrariesLoad();

        $builder = Mage::getModel('codigo5_boletosimples/order_builder')->build($order);
        $bankBillet = BoletoSimples\BankBillet::create($builder->getData());

        // TODO: Handle errors from BoletoSimples API in a better way
        if (!$bankBillet->isPersisted()) {
            $fieldsErrors = array_filter($bankBillet->response_errors);
            $fieldsErrors = array_map(function($errors, $key) {
                if (is_array($errors)) {
                    $errors = implode(', ', $errors);
                }
                return "'{$key}' {$errors}";
            }, $fieldsErrors, array_keys($fieldsErrors));

            throw new Codigo5_BoletoSimples_Exception(implode(' / ', $fieldsErrors));
        }

        $paymentMethod = $helper->getPaymentMethod($order->getStoreId());
        $newStatus = 'boletosimples_waiting_payment';

        $order
            ->setBoletosimplesBankBilletId($bankBillet->id)
            ->setBoletosimplesBankBilletUrl($bankBillet->shorten_url)
            ->setState(
               $helper->getStateByStatus($newStatus),
               $newStatus,
               $helper->__('Bank billet has been created'),
               false
            )
            ->save();
    }

    public function handleWebhook(array $webhook)
    {
        switch ($webhook['event_code']) {
            case 'bank_billet.paid':
                $orderId = @$webhook['object']['meta']['order_id'];
                $order = Mage::getModel('sales/order')->load($orderId);
                $newStatus = 'boletosimples_paid';

                if ($order->getId() && $order->getStatus() !== $newStatus) {
                    $helper = Mage::helper('codigo5_boletosimples/payment');

                    $order->setState(
                       $helper->getStateByStatus($newStatus),
                       $newStatus,
                       $helper->__('Bank billet has been paid'),
                       true
                   );
                   $order->save();
                }
        }
    }
}
