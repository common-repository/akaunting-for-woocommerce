<?php
/**
 * @package        Akaunting
 * @copyright      2017-2020 Akaunting Inc, akaunting.com
 * @license        GNU/GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 */

class AkauntingConnector
{
    public $email;
    public $password;

    public function __construct($email, $password)
    {
        $this->email = $email;
        $this->password = $password;
    }

    public function get($url)
    {
        return $this->request($url);
    }

    public function post($url, $data)
    {
        return $this->request($url, array('body' => $data), 'post');
    }

    public function patch($url, $data)
    {
        return $this->request($url, array('body' => $data), 'patch');
    }

    protected function request($url, $args = array(), $method = 'get')
    {
        $args['headers'] =  array(
            'Authorization' => 'Basic ' . base64_encode($this->email . ':' . $this->password)
        );

        $args['timeout'] = 30;

        switch ($method) {
            case 'post':
                $response = wp_remote_post($url, $args);
                
                $content = wp_remote_retrieve_headers($response);

                if (!is_array($content)) {
                    $content = $content->getAll();
                }

                break;
            case 'patch':
                $args['method'] = 'PATCH';
                $response = wp_remote_request($url, $args);

                $content = wp_remote_retrieve_headers($response);

                if (!is_array($content)) {
                    $content = $content->getAll();
                }

                break;
            case 'get':
            default:
                $response = wp_remote_get($url, $args);

                $content = wp_remote_retrieve_body($response);

                break;
        }

        return $content;
    }
}