<?php
/**
 * @package        Akaunting
 * @copyright      2017-2020 Akaunting Inc, akaunting.com
 * @license        GNU/GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 */

require_once('connector.php');

class AkauntingHelper
{
    public $url;
    public $company;
    public $company_id;
    public $connector;

    public function __construct($url, $company_id, $email, $password)
    {
        $this->url = rtrim($url, '/');
        $this->company_id = $company_id;

        $this->connector = new AkauntingConnector($email, $password);
    }

    public function check()
    {
        $url = $this->url . '/api/woocommerce/check?company_id=' . $this->company_id;

        $response = json_decode($this->connector->get($url));

        if (empty($response)) {
            return false;
        }

        if (isset($response->status_code)) {
            switch ($response->status_code) {
                case '401':
                    return 'auth';
                    break;
                case '404':
                    return 'install';
                    break;
                default;
                    return 'connect';
                    break;
            }
        }

        $this->company = $response->data;

        return true;
    }

    public function storeProduct($data)
    {
        $data['company_id'] = $this->company_id;

        $url = $this->url . '/api/woocommerce/products';

        return $this->connector->post($url, $data);
    }

    public function storeCustomer($data)
    {
        $data['company_id'] = $this->company_id;

        $url = $this->url . '/api/woocommerce/customers';

        return $this->connector->post($url, $data);
    }

    public function storeOrder($data)
    {
        $data['date_created'] = $data['date_created']->date_i18n();

        if (empty($data['date_created'])) {
            $data['date_created'] = $data['date_modified']->date_i18n();
        }

        $data['invoice_number'] = 'WOO-' . $data['id'];

        $address = $data['billing']['address_1'];

        if (!empty($data['billing']['address_2'])) {
            $address .= "\n" . $data['billing']['address_2'];
        }

        if (!empty($data['billing']['city'])) {
            $address .= "\n" . $data['billing']['city'];
        }

        $address .= "\n" . wc()->countries->countries[$data['billing']['country']];

        $data['address'] = $address;

        $data['company_id'] = $this->company_id;

        if (!empty($data['customer_id'])) {
            $user = get_user_by('id', $data['customer_id']);
            $customer = $this->getResource($user->user_email, 'customers');
            
            if (is_object($customer) && !empty($customer->id)) {
                $data['customer_id'] = $customer->id;
            } else {
                $data['customer_id'] = 0;
            }
        }
        
        if (empty($data['customer_id'])) {
            $this->storeCustomer([
                'meta' => [
                    'billing_first_name' => [$data['billing']['first_name']],
                    'billing_last_name' => [$data['billing']['last_name']],
                    'billing_phone' => [$data['billing']['phone']],
                ],
                'email' => $data['billing']['email'],
                'address' => $address,
            ]);
            
            $customer = $this->getResource($data['billing']['email'], 'customers');
        }

        $data['customer_id'] = $customer->id;

        $sub_total = 0;

        $data['items'] = array();
        foreach ($data['line_items'] as $product) {
            $p = $product->get_data();
            $p['price'] = $p['subtotal'] / $p['quantity'];

            $wc_product = wc_get_product($p['product_id']);
            if (!empty($wc_product)) {
                $p_data = $wc_product->get_data();
                $p['sku'] = $p_data['sku'];
            } else {
                $p['sku'] = 'product-' . $p['product_id'];
            }

            $data['items'][] = $p;

            $sub_total += $p['subtotal'];
        }

        $data['totals'] = array();

        $sort_order = 1;

        // Add subtotal first
        $t = [
            'code'                  =>  'sub_total',
            'name'                  =>  'invoices.sub_total',
            'amount'                =>  $sub_total,
            'sort_order'            =>  $sort_order,
            'company_id'            =>  $this->company_id,
        ];

        $data['totals'][] = $t;

        $sort_order++;

        // add shipping
        foreach ($data['shipping_lines'] as $shipping_line) {
            $shipping = $shipping_line->get_data();

            $t = [
                'code'                  =>  'shipping',
                'name'                  =>  'Shipping',
                'amount'                =>  $shipping['total'],
                'sort_order'            =>  $sort_order,
                'company_id'            =>  $this->company_id,
            ];

            $data['totals'][] = $t;

            $sort_order++;
        }

        // Add tax
        foreach ($data['tax_lines'] as $tax_line) {
            $tax = $tax_line->get_data();

            $t = [
                'code'                  =>  'tax',
                'name'                  =>  $tax['label'],
                'amount'                =>  $tax['tax_total'] + $tax['shipping_tax_total'],
                'sort_order'            =>  $sort_order,
                'company_id'            =>  $this->company_id,
            ];

            $data['totals'][] = $t;

            $sort_order++;
        }

        // Add total
        $t = [
            'code'                  =>  'total',
            'name'                  =>  'invoices.total',
            'amount'                =>  $data['total'],
            'sort_order'            =>  $sort_order,
            'company_id'            =>  $this->company_id,
        ];

        $data['totals'][] = $t;

        $url = $this->url . '/api/woocommerce/orders';

        return $this->connector->post($url, $data);
    }

    public function getResource($id, $type)
    {
        static $list = array(
            'products' => array(),
            'customers' => array(),
            'orders' => array(),
        );

        if (!isset($list[$type][$id])) {
            $url = $this->url . '/api/woocommerce/'. $type .'/' . $id;

            $url .= '?company_id=' . $this->company_id;

            $response = $this->connector->get($url);

            if (empty($response)) {
                $list[$type][$id] = null;
            } else {
                $obj = json_decode($response);

                if (is_object($obj) && isset($obj->data)) {
                    $list[$type][$id] = $obj->data;
                } else {
                    $list[$type][$id] = null;
                }
            }
        }

        return $list[$type][$id];
    }
}