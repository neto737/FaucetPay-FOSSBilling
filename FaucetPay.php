<?php

class Payment_Adapter_FaucetPay implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private array $config = [];

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(array $config)
    {
        $this->config = $config;
        foreach (['username'] as $key) {
            if (!isset($this->config[$key])) {
                throw new \Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PAYEER', ':missing' => $key], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description'                => 'FaucetPay Payment Gateway',
            'logo'                       => [
                'logo'   => '/FaucetPay/faucetpay-logo.png',
                'height' => '50px',
                'width'  => '50px',
            ],
            'form'                       => [
                'username' => [
                    'text',
                    [
                        'label' => 'Username:',
                    ],
                ],
                'currencies' => [
                    'text', 
                    [
                        'label' => 'Accepted currencies (eg.: BTC;LTC;USDT):'
                    ]
                ]
            ]
        ];
    }

    public function getHtml($api_admin, $invoice_id): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $post = $data['post'];

        $token = $post['token'];
  
        $payment_info = file_get_contents("https://faucetpay.io/merchant/get-payment/" . $token);
        $payment_info = json_decode($payment_info, true);
        
        if ($this->config['username'] == $payment_info['merchant_username'] && $payment_info['valid']) {
            $transaction = $this->di['db']->findOne("Transaction", "txn_id = :txn_id", [":txn_id" => $payment_info['transaction_id']]);
            if (!$transaction) {
                $transaction = $this->di['db']->getExistingModelById('Transaction', $id);
            }

            if ($this->isIpnDuplicate($payment_info)) {
                return;
            };

            $invoice = $this->di['db']->findOne('Invoice', 'hash = :hash', [':hash' => $payment_info['custom']]);

            $invoiceService = $this->di['mod_service']('Invoice');

            if ($payment_info['currency1'] != 'USDT' || $payment_info['amount1'] != $invoiceService->getTotalWithTax($invoice)) {
                return;
            }

            $transaction->invoice_id = $invoice->id;
            $transaction->type = 'transaction';
            $transaction->txn_id = $payment_info['transaction_id'];
            $transaction->txn_status = 'complete';
            $transaction->amount = $payment_info['amount1'];
            $transaction->currency = 'USD';

            $bd = [
                'amount'      => $transaction->amount,
                'description' => 'FaucetPay transaction ' . $payment_info['transaction_id'],
                'type'        => 'transaction',
                'rel_id'      => $transaction->id,
            ];

            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
            $clientService = $this->di['mod_service']('client');
            $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

            if ($transaction->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            } else {
                $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
            }

            $transaction->status = 'processed';
            $transaction->ipn = json_encode(array_merge($post, $payment_info));
            $transaction->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($transaction);

            return;
        }

        http_response_code(403);
        return;
    }

    protected function _generateForm(Model_Invoice $invoice): string
    {
        $invoiceService = $this->di['mod_service']('Invoice');
    
        $invoice_number_padding = $this->di['mod_service']('System')->getParamValue('invoice_number_padding');
        $invoice_number_padding = $invoice_number_padding !== null && $invoice_number_padding !== '' ? $invoice_number_padding : 5;
    
        $select = '';
        if (!empty($this->config['currencies'])) {
            $select = '<label class="form-label">Currency</label>';
            $select .='<select class="form-select mb-3" name="currency2">';
        
            foreach (explode(';', $this->config['currencies']) as $row) {
                $select .= '<option value="' . $row . '">' . $row . '</option>';
            }
    
            $select .= '</select>';
        }
    
        return '<form action="https://faucetpay.io/merchant/webscr" method="post">
            <input type="hidden" name="merchant_username" value="' . $this->config['username'] . '">
            <input type="hidden" name="item_description" value="' . 'Order #'.$invoice->serie.sprintf('%0'.$invoice_number_padding.'s', $invoice->nr) . '">
            <input type="hidden" name="amount1" value="' . $invoiceService->getTotalWithTax($invoice) . '">
            <input type="hidden" name="currency1" value="USD">
            <input type="hidden" name="custom" value="' . $invoice->hash . '">
            <input type="hidden" name="callback_url" value="' . $this->config['notify_url'] . '">
            <input type="hidden" name="success_url" value="' . $this->config['thankyou_url'] . '">
            <input type="hidden" name="cancel_url" value="' . $this->config['cancel_url'] . '">' .
            ($select ? $select : '') .
            '<input class="btn btn-primary" type="submit" name="submit" value="Make Payment">
        </form>';
    }

    public function isIpnDuplicate(array $ipn): bool
    {
        $transaction = $this->di['db']->findOne('Transaction', 'txn_id = :txn_id and amount = :amount', [
            ':txn_id' => $ipn['transaction_id'],
            ':amount' => $ipn['amount1']
        ]);
        if ($transaction) {
            return $transaction->status == 'processed';
        }
        return false;
    }
}
