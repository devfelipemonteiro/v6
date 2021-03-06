<?php
/**
 * CubeCart v6
 * ========================================
 * CubeCart is a registered trade mark of CubeCart Limited
 * Copyright CubeCart Limited 2017. All rights reserved.
 * UK Private Limited Company No. 5323904
 * ========================================
 * Web:   http://www.cubecart.com
 * Email:  sales@cubecart.com
 * License:  GPL-3.0 https://www.gnu.org/licenses/quick-guide-gplv3.html
 */

/**
 * Password class
 *
 * @author Martin Purcell
 * @author Al Brookbanks
 * @since 5.0.0
 */
class Request
{
    private $_curl;
    private $_fp;

    private $_request_url;
    private $_request_port;

    private $_proxy_host   = false;
    private $_proxy_port   = false;
    private $_proxy_username  = false;
    private $_proxy_password  = false;

    private $_send_headers   = true;

    private $_request_cache   = false;
    private $_request_hash   = null;

    private $_request_headers  = false;
    private $_add_request_headers  = array();
    private $_custom_request_headers = array();
    private $_request_body   = null;
    private $_request_return  = true;
    private $_request_auth   = null;

    private $_request_method  = 'post';
    private $_request_protocol  = 'http';
    private $_fsock_protocol = 'tcp';
    private $_request_http_version = '1.0';
    private $_request_timeout  = 15;

    private $_request_useragent;
    private $_request_return_headers;

    private $_curl_options  = array();
    private $_debug    = array();
    private $_log    = true;

    ##############################################

    public function __construct($url, $path = '/', $port = 80, $return_headers = false, $return_transfer = true, $timeout = 15, $cache = false)
    {
        ## Is cURL available?
        $this->_curl   = (function_exists('curl_init')) ? curl_init() : false;

        $data = (strstr($url, '/')) ? explode('/', $url, 2) : false;
        $this->_request_url    = preg_replace('#^[\w]+://#iu', '', (is_array($data)) ? $data[0] : $url);
        $this->_request_path   = (is_array($data) && $path = '/') ? $path.$data[1] : $path;

        $this->_request_port   = (int)$port;
        $this->_request_return_headers = (bool)$return_headers;
        $this->_request_return   = (bool)$return_transfer;
        $this->_request_timeout   = (int)$timeout;
        $this->_request_cache   = (bool)$cache;

        if ($this->_curl) {
            $this->_curl_options[CURLOPT_HEADER]    = $this->_request_return_headers;
            $this->_curl_options[CURLOPT_RETURNTRANSFER]  = $this->_request_return;
            $this->_curl_options[CURLOPT_VERBOSE]    = false;
            $this->_curl_options[CURLOPT_FAILONERROR]   = true;
            /*$this->_curl_options[CURLOPT_FOLLOWLOCATION] 	= true;*/
        }
    }

    public function __destruct()
    {
        if ($this->_curl) {
            curl_close($this->_curl);
        } else {
            fclose($this->_fp);
        }
    }

    //=====[ Public ]=======================================

    /**
     * Appand headers into request
     *
     * @param string $header
     */
    public function appendHeaders($header)
    {
        $this->_add_request_headers[] = $header;
    }

    /**
     * Authentication for request
     *
     * @param string $username
     * @param string $password
     */
    public function authenticate($username, $password)
    {
        if ($this->_curl) {
            $this->_curl_options[CURLOPT_USERPWD] = $username.':'.$password;
        } else {
            $this->_request_headers[] = 'Authorization: Basic '.base64_encode($username.':'.$password);
        }
    }

    /**
     * Authentication for request
     *
     * @param bool $status
     * @return bool
     */
    public function cache($status = null)
    {
        if (!is_null($status)) {
            $this->_request_cache = (bool)$status;
        }
        return $this->_request_cache;
    }

    /**
     * Add headers REMOVING default ones
     *
     * @param text $header
     */
    public function customHeaders($header)
    {
        $this->_custom_request_headers[] = $header;
    }

    /**
     * Add cURL options
     *
     * @param string $optionName
     * @param string $optionValue
     */
    public function customOption($optionName, $optionValue)
    {
        $this->_curl_options[$optionName] = $optionValue;
    }

    /**
     * Log the request and response
     *
     * @param string $request
     * @param string $result
     * @param string $error
     * @return bool
     */
    private function log($request, $result, $error = '')
    {
        if (!$this->_log) {
            return false;
        }
        $data = array(
            'request_url'  	=> $this->_request_protocol.'://'.$this->_request_url.$this->_request_path,
            'request' => (!empty($request)) ? $this->mask_cc($request) : "No Data",
            'result'    	=> $this->mask_cc($result),
            'error'   		=> $error
        );
        $log_days = $GLOBALS['config']->get('config', 'r_request');
        if (ctype_digit((string)$log_days) &&  $log_days > 0) {
            $GLOBALS['db']->insert('CubeCart_request_log', $data);
            $GLOBALS['db']->delete('CubeCart_request_log', 'time < DATE_SUB(NOW(), INTERVAL '.$log_days.' DAY)');
        } elseif (empty($log_days) || !$log_days) {
            $GLOBALS['db']->insert('CubeCart_request_log', $data);
        }
    }

    /**
     * Mask credit card from request
     *
     * @param string $string
     * @param string $mask_char
     * @return string
     */
    private function mask_cc($string, $mask_char = '*')
    {
        if (preg_match('/([0-9]{12,16})/', $string, $matches)) {
            $replacement = preg_replace('/(?!^.?)[0-9](?!(.){0,3}$)/', $mask_char, $matches[0]);
            return preg_replace('/'.$matches[0].'/', $replacement, $string);
        } else {
            return $string;
        }
    }

    /**
     * Set the request data
     *
     * @param array $dataArray
     */
    public function setData($dataArray = null)
    {
        if (is_array($dataArray)) {
            $this->_request_body = strip_tags(http_build_query($dataArray, '', '&'));
        } else {
            $this->_request_body = $dataArray;
        }
        ## Generate headers
        $this->_request_headers = null;
        $this->_request_http_version = (!empty($this->_proxy_username) && !empty($this->_proxy_password)) ? 1.1 : $this->_request_http_version;
        if ($this->_send_headers) {
            if ($this->_request_method == 'post') {
                $this->_request_headers[] = sprintf('POST %s HTTP/%s', $this->_request_path, $this->_request_http_version);
                $this->_request_headers[] = 'Content-Type: application/x-www-form-urlencoded';
                if (!empty($this->_request_body)) {
                    $this->_request_headers[] = 'Content-Length: '.strlen($this->_request_body);
                }
            } else {
                $this->_request_headers[] = sprintf('GET %s HTTP/%s', (!empty($this->_request_body) ? $this->_request_path.'?'.$this->_request_body : $this->_request_path), $this->_request_http_version);
            }
            $this->_request_headers[]  = 'Host: '.$this->_request_url;
            $this->_request_headers[]  = 'Connection: Close';

            if (count($this->_custom_request_headers)) {
                $this->_request_headers  = $this->_custom_request_headers;
            } elseif (count($this->_add_request_headers)) {
                $this->_request_headers  = array_merge($this->_request_headers, $this->_add_request_headers);
            }
            $this->_request_hash   = md5($this->_request_url.$this->_request_body.implode('', $this->_request_headers));
        } else {
            $this->_request_hash   = md5($this->_request_url.$this->_request_body);
        }
    }

    /**
     * Send request via cURL or fsock
     *
     * @param int $timeout
     * @param string $mask_char
     * @return string/false
     */
    public function send($timeout = null)
    {
        
        // if $_request_hash is still null then setData method hasn't been run
        if ($this->_request_hash === null) {
            $this->setData();
        }

        if (!empty($timeout)) {
            $this->_request_timeout = (int)$timeout;
        }

        if ($this->_request_cache && $GLOBALS['cache']->exists('request.'.$this->_request_hash)) {
            return $GLOBALS['cache']->read('request.'.$this->_request_hash);
        } elseif ($this->_curl) {
            ## Use cURL
            if ($this->_request_method == 'post') {
                $this->_curl_options[CURLOPT_POST] = true;
                if (!empty($this->_request_body)) {
                    $this->_curl_options[CURLOPT_POSTFIELDS] = $this->_request_body;
                }
            } elseif (!empty($this->_request_body)) {
                $this->_request_path .= '?'.$this->_request_body;
            }
            if ($this->_send_headers) {
                $this->_curl_options[CURLOPT_HTTPHEADER] = $this->_request_headers;
            }
            $this->_curl_options[CURLOPT_PORT]     = $this->_request_port;
            $this->_curl_options[CURLOPT_URL]     = $this->_request_protocol.'://'.$this->_request_url.$this->_request_path;
            $this->_curl_options[CURLOPT_TIMEOUT]    = $this->_request_timeout;
            $this->_curl_options[CURLOPT_CONNECTTIMEOUT]  = $this->_request_timeout;

            ## Some hosts disable curl and curl_exec spits out a warning so we need to supress it and detect if it returns false
            curl_setopt_array($this->_curl, $this->_curl_options);

            $return = curl_exec($this->_curl);
            $error = curl_error($this->_curl);

            if ($return) {
                if ($this->_request_cache) {
                    $GLOBALS['cache']->write($return, 'request.'.$this->_request_hash);
                }
                $this->log($this->_request_body, $return);
                return $return;
            } else {
                $error_no = @curl_errno($this->_curl);
                if (!$error_no && !$error) {
                    $error_no = "NA";
                    $error = "cURL is installed but may be disabled by the host. cURL exec returns false.";
                }
                $error = sprintf('cURL Error (%d): %s', $error_no, $error);
                $this->log($this->_request_body, $return, $error);
            }
        } else {
            ## Fallback to fsockopen
            $this->_fp = fsockopen(($this->_proxy_host) ? $this->_fsock_protocol.'://'.$this->_proxy_host : $this->_fsock_protocol.'://'.$this->_request_url, ($this->_proxy_port) ? $this->_proxy_port : $this->_request_port, $error_no, $error_str, $this->_request_timeout);

            if (!empty($error_no) || !empty($error_str)) {
                trigger_error(sprintf('fsockopen Error (%d): %s', $error_no, $error_str));
            }
            if ($this->_fp) {
                fwrite($this->_fp, implode("\r\n", $this->_request_headers)."\r\n\r\n".$this->_request_body);
                $return = "";
                while (!feof($this->_fp)) {
                    $return .= fread($this->_fp, 8024);
                }

                if (!empty($return)) {
                    if (!$this->_request_return_headers) {
                        list($header, $return) = preg_split("/\R\R/", $return, 2);
                    }
                    if ($this->_request_cache) {
                        $GLOBALS['cache']->write($return, 'request.'.$this->_request_hash);
                    }
                    $this->log($this->_request_body, $return);
                    return $return;
                }
            }
        }
        return false;
    }

    /**
     * Send request headers
     *
     * @param bool $bool
     */
    public function sendHeaders($bool = true)
    {
        $this->_send_headers =  $bool;
    }

    /**
     * Set HTTP protocol version
     *
     * @param float (as a string) $version
     */
    public function setHTTPVersion($version = '1.0')
    {
        $this->_request_http_version = $version;
    }

    /**
     * Set request method of post or get
     *
     * @param string $method
     * @return bool
     */
    public function setMethod($method = 'post')
    {
        switch (strtolower($method)) {
        case 'get':
        case 'post':
            $this->_request_method = strtolower($method);
            break;
        default:
            return false;
        }
        return true;
    }

    /**
     * Set up proxy server route if it exists (rare)
     *
     * @param string $proxy_host
     * @param int $proxy_port
     * @param string $username
     * @param string $password
     */
    public function setProxy($proxy_host, $proxy_port = 80, $username = null, $password = null)
    {
        if ($this->_curl) {
            if (!empty($username) && !empty($password)) {
                $this->_curl_options[CURLOPT_PROXYUSERPWD] = $username.':'.$password;
            }
            $this->_curl_options[CURLOPT_HTTPHEADER] = array('Host: '.$this->_request_url);
            $this->_curl_options[CURLOPT_PROXY] = $proxy_host.':'.$proxy_port;
        } else {
            $this->_proxy_host = $proxy_host;
            $this->_proxy_port = $proxy_port;
            if (!empty($username) && !empty($password)) {
                $this->_proxy_username  = $password;
                $this->_proxy_password  = $username;
                $this->_request_headers[] = 'Proxy-Authorization: Basic '.base64_encode($username.':'.$password);
            }
        }
    }

    /**
     * Set protocol/port for SSL
     *
     * @param string $proxy_host
     * @param int $proxy_port
     * @param string $username
     * @param string $password
     */
    public function setSSL($verify_peer = false, $verify_host = false, $cert = null)
    {
        
        ## Some systems use custom ports, so only redefine it if not already specified e.g. https://dev.psigate.com:7989
        if ($this->_request_port == 80) {
            $this->_request_port = 443;
        }
        if ($this->_curl) {
            $this->_request_protocol = 'https';
            $this->_curl_options[CURLOPT_SSL_VERIFYPEER] = $verify_peer;
            if (!empty($cert) && file_exists($cert)) {
                if (is_dir($cert)) {
                    $this->_curl_options[CURL_SSL_CAPATH] = $cert;
                } else {
                    $this->_curl_options[CURL_SSL_CAINFO] = $cert;
                }
            }
            $this->_curl_options[CURLOPT_SSL_VERIFYHOST] = $verify_host;
        } else {
            $this->_fsock_protocol = 'ssl';
        }
    }
    
    /**
     * Set request useragent
     *
     * @param string $user_agent
     */
    public function setUserAgent($user_agent)
    {
        if ($this->_curl) {
            $this->_curl_options[CURLOPT_USERAGENT] = $user_agent;
        } else {
            $this->_request_headers[] = 'User-Agent: '.$user_agent;
        }
    }

    /**
     * Use to prevent request logging
     *
     * @param bool $bool
     * @return bool
     */
    public function skiplog($bool = false)
    {
        if ($bool) {
            $this->_log = false;
            return true;
        }
        return false;
    }
}
