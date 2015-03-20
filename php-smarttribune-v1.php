<?php

/**
 * Smart-Tribune Public API
 *
 * @package		API v0.1
 * @author		Smart-tribune
 * @link		https://api.smart-tribune.com
 */

/**
 * @todo create a getReguestType() method to avoid passing the request_type parameter
 */

class SmartTribune
{   
	# Mode debug ? 0 none / 1 errors only / 2 all
	var $debug = 1;

	# Edit with your Smart Tribune Infos
	var $apiKey = ''; 
	var $apiSecret = '';  
	var $apiUrl = 'https://api.smart-tribune.com/v1';
	var $callbackUrl = "";
	var $_request_type = OAUTH_HTTP_METHOD_GET;

	// Constructor function
	public function __construct($apiKey = false, $apiSecret = false)
	{
		if( $apiKey && $apiSecret ) {
			$this->apiKey =$apiKey;
			$this->apiSecret =$apiSecret;
			$this->oauth_client = new Oauth($this->apiKey, $this->apiSecret);
			$this->oauth_client->enableDebug();
			$this->authenticate(); 
		}
	}

	public function setDebug($value){
		if(is_int($value))
			$this->debug = $value;
		else
			$this->debug = 1;
		if(intval($this->debug) > 0) $this->oauth_client->enableDebug();
	}
    
	public function authenticate(){
		/**
		 * if an access token and access secret have already been saved 
		 * either in a session or database you should use it
		 */
		if(!isset($_SESSION)) 
	    { 
	        session_start(); 
	    } 

		if(isset($_SESSION) && isset($_SESSION['accessToken']) && isset($_SESSION['accessSecret'])){
			try{
	    		$this->oauth_client->setToken($_SESSION['accessToken'], $_SESSION['accessSecret']);
		      	$this->sendRequest('index/validate');
		      	if(isset($this->_response->success)){
		    		$this->accessToken = $_SESSION['accessToken'];
		    		$this->accessSecret = $_SESSION['accessSecret'];
		     		return true;
		     		exit;
		      	}
		    } catch (OAuthException $E){
		    }
		}

		try 
		{
			#destroy existing session
			session_unset();
			session_destroy();
			session_write_close();

		   	#Api url and request tokens
		    $info = $this->oauth_client->getRequestToken($this->apiUrl."/index/request_token", $this->callbackUrl);
		    if($info){
		    	$this->requestTokenKey = $info['oauth_token'];
		    	$this->requestTokenSecret = $info['oauth_token_secret'];
			    $this->oauth_client->setToken($this->requestTokenKey, $this->requestTokenSecret);

			    #Verifier
				$curl_params = array(
					'oauth_token' => $this->requestTokenKey,
					'oauth_token_secret' => $this->requestTokenSecret
				);

		    	$ch = curl_init($info['authentification_url']);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
				curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);			 
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($curl_params, '', '&'));
				/* Uncomment following lines if not on production to disable SSL */
				// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

				$response = curl_exec($ch);
				$responseInfo = curl_getinfo($ch);
				curl_close($ch);

				$query = parse_url($responseInfo['redirect_url']);
				parse_str($query['query']);

			    #Access tokens
		    	$accessToken = $this->oauth_client->getAccessToken($this->apiUrl."/index/access_token",null,trim($oauth_verifier));
				
				/**
				 * We put these tokens in a session but you can save it in your database
				 */
				if(!session_id()) session_start();
		    	$_SESSION['accessToken'] = $this->accessToken = $accessToken["oauth_token"];
		    	$_SESSION['accessSecret'] = $this->accessSecret = $accessToken["oauth_token_secret"];
		    }
		}
		catch(OAuthException $E)
		{
		    $this->_response = $E;
		}
	}
    
	public function __call($method,$args) {

    	# params
    	$params = array();

    	if(sizeof($args)){
    		foreach ($args[0] as &$arg) {
    			if (is_array($arg)) {
    				$arg = implode(',', $arg);
    			}
    		}
    		$params = $args[0];
    	}
    	

    	# request method
    	$handle = explode('_', $method);

    	if(ctype_lower($handle[0]) ){
    		$resource = $handle[0];
    	}else{
    		$arr = str_split($handle[0]);
    		$resource = array();
    		foreach ($arr as $char) {
    			if(ctype_lower($char)){
    				$resource[] = $char;
    			}else{
    				$resource[] = '-'.strtolower($char);
    			}
    		}
    		$resource = implode('', $resource);
    		$resource = str_replace(" ", "", $resource);
    	}

    	$this->_request_type = OAUTH_HTTP_METHOD_GET;
    	if (isset($handle[1])) {
	    	switch ($handle[1]) {
	    		case 'update':
	    			$this->_request_type = OAUTH_HTTP_METHOD_POST;
	    			break;
	    		
	    		case 'create':
	    			$this->_request_type = OAUTH_HTTP_METHOD_POST;
	    			break;
	    		
	    		case 'get':
	    			$this->_request_type = OAUTH_HTTP_METHOD_GET;
	    			break;
	    		
	    		case 'delete':
	    			$this->_request_type = OAUTH_HTTP_METHOD_DELETE;
	    			break;
	    		
	    		default:
	    			$this->_request_type = OAUTH_HTTP_METHOD_GET;
	    			break;
	    	}
    	}

		# Make request
		$result = false;
		$result = $this->sendRequest($resource, $params);

		# Return result
		$return = $this->_response;

		/**
		 * Overriding for api documentation
		 */
		if($this->_response instanceOf OAuthException){
			$this->_response_code = $this->_response->getCode();
			$this->_response_error = $this->_response->getMessage();
			http_response_code($this->_response_code);
			return  array(
                'Code'    => $this->_response->getCode(),
                'Message'   => $this->_response->getMessage(),
                'Description' => $this->_response->lastResponse
            );

		}

		
		if( $this->debug >= 2 || ( $this->debug == 1 && $return == false ) ){
			$this->debug();
		}
		
		return $return;
	}

	public function sendRequest($method = false, $params=array()) {
		# Method
		$this->_method = $method;
		# Build request URL
		if(in_array($method, array('login', 'logout'))){
			$method = 'index/'.$method;
			$this->_request_type = OAUTH_HTTP_METHOD_POST;
		} 

		$url = $this->apiUrl.'/'.$method;
		if(array_key_exists('id', $params) ){
			$url .= '/'.$params['id'];
			unset($params['id']);
		} 

		$return = false;
		if($this->_request_type != OAUTH_HTTP_METHOD_GET) {
			$this->_request_post = $params;
		}

		try{
			if(isset($this->accessToken) && isset($this->accessSecret)){
				$this->oauth_client->setToken($this->accessToken, $this->accessSecret);
			}

	      	$this->oauth_client->fetch($url, $params, $this->_request_type);
	     	$this->_response = json_decode($this->oauth_client->getLastResponse());
	     	$response = $this->oauth_client->getLastResponseInfo();
	     	$this->call_url = $response['url'];
	    } catch (OAuthException $E){
	    	$this->call_url = $url;
		    $this->_response = $E;
		    $this->_request_post = false;
	    }

	    $this->_response_code = $this->_response_error = null;
	    $this->_response_code = method_exists($this->_response, 'getCode') ? $this->_response->getCode() : null;
	   	
	   	if(!$this->_response_code) {
	   		$this->_response_error = null;
	   		if(isset($this->_response->error))
	   			$this->_response_error = $this->_response->error ;
	   		else if (isset($this->_response->status) && $this->_response->status == 'failed') {
	   			$this->_response_error = $this->_response->status ;
	   		}
	   	}

	    $return = ($this->_response_error || $this->_response_code) ? false : true;
	    return $return;
	}
	
	public function debug() {
		echo '<style type="text/css">';
		echo '

		#debugger {width: 100%; font-family: arial;}
		#debugger table {padding: 0; margin: 0 0 20px; width: 100%; font-size: 11px; text-align: left;border-collapse: collapse;}
		#debugger th, #debugger td {padding: 2px 4px;}
		#debugger tr.h {background: #999; color: #fff;}
		#debugger tr.Success {background:#90c306; color: #fff;}
		#debugger tr.Error {background:#c30029 ; color: #fff;}
		#debugger tr.Not-modified {background:orange ; color: #fff;}
		#debugger th {width: 20%; vertical-align:top; padding-bottom: 8px;}

		';
		echo '</style>';

		echo '<div id="debugger">';

		if(isset($this->_response_code)) :

			if($this->_response_code == 304) :

				echo '<table>';
				echo '<tr class="Not-modified"><th>Error</th><td></td></tr>';
				echo '<tr><th>Error no</th><td>'.$this->_response_code.'</td></tr>';
				echo '<tr><th>Message</th><td>Not Modified</td></tr>';
				echo '</table>';

			else :

				echo '<table>';
				echo '<tr class="Error"><th>Error</th><td></td></tr>';
				echo '<tr><th>Error no</th><td>'.$this->_response_code.'</td></tr>';
				if(isset($this->_response)) :
					if( is_array($this->_response) OR  is_object($this->_response) ):
						echo '<tr><th>Status</th><td><pre>'.print_r($this->_response,true).'</pre></td></tr>';
					else:
						echo '<tr><th>Status</th><td><pre>'.$this->_response.'</pre></td></tr>';
					endif;
				endif;
				echo '</table>';

			endif;
		elseif(isset($this->_response)) :
			$status  = (isset($this->_response_error)) ? 'Error' : 'Success';
			echo '<table>';
			echo '<tr class="'.$status.'"><th>'.$status.'</th><td></td></tr>';

			if(isset($this->_response)) :
				echo '<tr><th>Response</th><td><pre>'.utf8_decode(print_r($this->_response,1)).'</pre></td></tr>';
			endif;

			echo '</table>';

		endif;

		$call_url = parse_url($this->call_url);

		echo '<table>';
		echo '<tr class="h"><th>API config</th><td></td></tr>';
		echo '<tr><th>Protocole</th><td>'.$call_url['scheme'].'</td></tr>';
		echo '<tr><th>Host</th><td>'.$call_url['host'].'</td></tr>';
		echo '</table>';

		echo '<table>';
		echo '<tr class="h"><th>Call infos</th><td></td></tr>';
		echo '<tr><th>Method</th><td>'.$this->_method.'</td></tr>';
		echo '<tr><th>Request type</th><td>'.$this->_request_type.'</td></tr>';


		if(array_key_exists('query', $call_url)){
			$args = explode("&",$call_url['query']);
			echo '<tr><th>Get Arguments -'.sizeof($args).'-</th><td> ';
			foreach($args as $arg) {
				$arg = explode("=",$arg);
				echo ''.$arg[0].' = <span style="color:#ff6e56;">'.$arg[1].'</span><br/>';
			}
			
			echo '</td></tr>';
		}
		
		if(isset($this->_request_post) && sizeof($this->_request_post)){
			echo '<tr><th>Post Arguments</th><td>';
		
			foreach($this->_request_post as $k=>$v) {
				echo $k.' = <span style="color:#ff6e56;">'.$v.'</span><br/>';
			}
	
			echo '</td></tr>';
		}

		echo '<tr><th>Call url</th><td>'.$this->call_url.'</td></tr>';
		echo '</table>';

		echo '</div>';
	}
}