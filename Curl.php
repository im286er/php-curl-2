<?php

/**
 * 面向对象风格的Curl操作库
 * 
 * ```php
 * // 初始化
 * $curl = new Curl();
 * 
 * // get请求
 * $curl->get('http://example.com');
 * $curl->get(array('http://example.com/search', 'keywords' => 'grass'));
 * 
 * // post请求
 * $curl->post('http://www.example.com/login/', array(
 *     'username' => 'admin',
 *     'password' => '123456',
 * ));
 * 
 * // 其他请求
 * $curl->put('http://api.example.com/user/', array(
 *     'name' => 'Grass',
 * ));
 * 
 * // 获取结果
 * $curl->error_code;
 * $curl->error_message;
 * $curl->request_url;
 * $curl->request_header;
 * $curl->request_body;
 * $curl->request_cookie;
 * $curl->response;
 * $curl->response_info;
 * $curl->response_header;
 * $curl->response_code;
 * 
 * ```
 */
class Curl
{
    /**
     * curl资源句柄
     * @var resource
     */
    public $curl = null;
    /**
     * 错误码
     * @see https://curl.haxx.se/libcurl/c/libcurl-errors.html
     * @var integer
     */
    public $error_code = 0;
    public $error_message = '';
    public $verify_ssl = false;

    /**
     * request 相关
     */
    public $request_url = null;
    public $request_header = array();
    public $request_body = array();
    public $request_cookie = array();
    
    /**
     * response 相关
     */
    public $response = null;
    public $response_info = array();
    public $response_header = array();
    public $response_code = 0;
    
    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \ErrorException('cURL扩展尚未安装');
        }
        $this->init();
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function instance()
    {
        return new static();
    }

    public function init()
    {
        $this->curl = curl_init();
        $this->setOpt(CURLOPT_RETURNTRANSFER, true);
        $this->setOpt(CURLINFO_HEADER_OUT, true);
        $this->setOpt(CURLOPT_TIMEOUT, 10);
        $this->setOpt(CURLOPT_AUTOREFERER, true);
        $this->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->setOpt(CURLOPT_MAXREDIRS, 10);
        if (!$this->verify_ssl) {
            $this->setOpt(CURLOPT_SSL_VERIFYPEER, false);
            $this->setOpt(CURLOPT_SSL_VERIFYHOST, 0);
        }
        $this->setOpt(CURLOPT_HEADERFUNCTION, array($this, 'addResponseHeader'));
        return $this;
    }
    
    public function get($url)
    {
        return $this->request($url, 'GET');
    }
    
    public function post($url, $data = array())
    {
        return $this->request($url, 'POST', $data);
    }

    public function put($url, $data = array())
    {
        return $this->request($url, 'PUT', $data);
    }
    
    public function patch($url, $data = array())
    {
        return $this->request($url, 'PATCH', $data);
    }

    public function delete($url, $data = array())
    {
        return $this->request($url, 'DELETE', $data);
    }
    
    public function options($url, $data = array())
    {
        return $this->request($url, 'OPTIONS', $data);
    }
    
    public function request($url, $method = 'GET', $data = array())
    {
        $method = strtoupper($method);
        if ($method === 'GET') {
            $this->setOpt(CURLOPT_HTTPGET, true);
        } elseif ($method === 'POST') {
            $this->setOpt(CURLOPT_POST, true);
            $this->setOpt(CURLOPT_POSTFIELDS, $this->prepareData($data));
        } else {
            $this->setOpt(CURLOPT_CUSTOMREQUEST, $method);
            $this->setOpt(CURLOPT_POSTFIELDS, $this->prepareData($data));
        }
        $this->request_url = $this->buildUrl($url);
        $this->request_body = $data;
        $this->setOpt(CURLOPT_URL, $this->request_url);
        $this->exec();
        return $this;
    }
    
    public function exec()
    {
        $this->response_header = array();
        $this->response = curl_exec($this->curl);
        $this->error_code = curl_errno($this->curl);
        $this->error_message = curl_error($this->curl);
        $this->response_info = curl_getinfo($this->curl);
        $this->response_code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $this->request_header = array_filter(explode("\r\n", curl_getinfo($this->curl, CURLINFO_HEADER_OUT)));
        return $this;
    }

    public function setOpt($option, $value)
    {
        curl_setopt($this->curl, $option, $value);
        return $this;
    }
    
    public function setHeader($key, $value)
    {
        $this->request_header[$key] = $key.': '.$value;
        $this->setOpt(CURLOPT_HTTPHEADER, array_values($this->request_header));
        return $this;
    }

    public function setCookie($key, $value)
    {
        $this->request_cookie[$key] = $value;
        $this->setOpt(CURLOPT_COOKIE, http_build_query($this->request_cookie, '', '; '));
        return $this;
    }

    public function close()
    {
        if (is_resource($this->curl)) {
            curl_close($this->curl);
        }
        return $this;
    }
    
    /**
     * 编译url
     * @param  string|array $url url字符串，或者数组形式：[url, 'param1'=>'value'...]
     * @return string 处理好的urk
     */
    public function buildUrl($url)
    {
        if (is_array($url)) {
            $url_str = array_shift($url);
            return $url_str . (strpos($url_str, '?') ? '&' : '?') . http_build_query($url);
        }
        return $url;
    }
    
    /**
     * 预处理一下请求数据
     * @param  array $data 待处理的数组数据
     * @return string 处理好的字符串数据
     */
    public function prepareData($data)
    {
        if (is_array($data) || is_object($data)) {
            return http_build_query($data);
        }
        return $data;
    }
    
    /**
     * 设置响应头的回调函数
     * @param resource $curl cURL的资源句柄
     * @param string $header  header 数据
     * @return integer 返回已写入的数据大小
     */
    protected function addResponseHeader($curl, $header)
    {
        $trimmed_header = trim($header, "\r\n");
        if (!($trimmed_header === "" || strtolower($trimmed_header) === 'http/1.1 100 continue')) {
            $this->response_header[] = $trimmed_header;
        }
        return strlen($header);
    }
}
