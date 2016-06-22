<?php
	include_once("lib/payment/gateway.php");
	
	class paymentGateway_psbank extends paymentGateway
	{
    	public function getRequestUrl() 
		{
        	return $this->paymentSystem->getParameterByName('query.url')->value;
    	}
		
    	public function getRequestType() 
		{
    		return $this->paymentSystem->getParameterByName('query.type')->value;
    	}
    	
		protected function translateCurrency($currency)
		{
			switch($currency)
			{
				case 'RUR': return 'RUB';
				default: return $currency;
			}
		}
		
		private function convertFromGMT($dateString)
		{
			$dateTime = new DateTime($dateString, new DateTimeZone('GMT'));
			$dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));
			return $dateTime->format('Y-m-d H:i:s');
		}
		
    	public function generateRequestParameters($order, $env) 
		{
			// ordered array of parameters involved in the formation of the signature
			$keyParameters = array('AMOUNT', 'CURRENCY', 'ORDER', 'MERCH_NAME', 'MERCHANT', 'TERMINAL', 'EMAIL', 'TRTYPE', 'TIMESTAMP', 'NONCE', 'BACKREF');
			
			$key = $this->paymentSystem->getParameterByName('gateway.key')->value;
				
			$parameters["AMOUNT"] = $order->getTotalSum();
			$parameters["CURRENCY"] = $this->translateCurrency($order->currency);
			$parameters["ORDER"] = sprintf("%06s", $order);
			
			$parameters["DESC"] = "Заказ на сайте ".$order->site->name;
			$parameters["TERMINAL"] = $this->paymentSystem->getParameterByName('gateway.terminal')->value;
			
			// only payment operation support 
			$parameters["TRTYPE"] = 1;
			
			$parameters["MERCH_NAME"] = $order->site->name;
			$parameters["MERCHANT"] = $this->paymentSystem->getParameterByName('gateway.merchant')->value;
			
			// get email from special attribute 
			if ($order->market->hasOwner() && $order->market->owner->group->hasAttributeBySpecialRole('email'))
			{
				$mailAttribute = $order->market->owner->group->getAttributeBySpecialRole('email');
				if($mailAttribute !== null && $order->market->owner->hasAttributeValueByAttribute($mailAttribute->getId()))
				{
					$email = $order->market->owner->getAttributeValueByAttribute($mailAttribute->getId());
					$parameters["EMAIL"] = $email->string;
				}
			}
				
			$parameters["TIMESTAMP"] = gmdate('YmdHis');
			
			// generate random 16-base number 
			$parameters["NONCE"] = sprintf("%X", rand(4096, 39321)).sprintf("%X", rand(4096, 39321)).sprintf("%X", rand(4096, 39321)).sprintf("%X", rand(4096, 39321));
			$parameters["BACKREF"] =  "http://".$_SERVER['HTTP_HOST'].$order->getPublishedPath().'?page.content.order.payment.info.display=1';
			
			// signature generate 
			foreach($keyParameters as $value)
				if (isset($parameters[$value]) && strlen($parameters[$value]) > 0) /* if parameter in key array */
					$string .= strlen($parameters[$value]).$parameters[$value];
				else
					$string .= "-";
			
			$parameters["DATA"] = $string;
			$parameters["P_SIGN"] = hash_hmac('sha1', $string, pack('H*', $key));
			
			return $parameters;
		}
    	
    	public function processResponse($env, db $database) 
		{
			// ordered array of parameters involved in the formation of the signature of answer
			$keyParameters = array('AMOUNT', 'CURRENCY', 'ORDER', 'MERCH_NAME', 'MERCHANT', 'TERMINAL', 'EMAIL', 'TRTYPE', 'TIMESTAMP', 'NONCE', 'BACKREF', 'RESULT', 'RC', 'RCTEXT', 'AUTHCODE', 'RRN', 'INT_REF');
			
			// get key form paymetSystem parameters
			$key = $this->paymentSystem->getParameterByName('gateway.key')->value;
			
			// signature generate 
			foreach($keyParameters as $value)
				if ($env->hasParameter($value) && strlen($env->getParameter($value)->getValue()) > 0)
					$string .= strlen($env->getParameter($value)->getValue()).$env->getParameter($value)->getValue();
				else
					$string .= "-";
			
			$hmac = strtoupper(hash_hmac('sha1', $string, pack('H*', $key)));
			$psign = $env->getParameter('P_SIGN')->getValue();
			
			// signature check failed 
			if($psign === null || strcmp($hmac, $psign) != 0) return;
			
			$orderId = $env->getParameter('ORDER')->getValue();

			// get database for getting order object 
			try
			{
				$order = $database->getSite()->getMarket()->getOrderById($orderId);	
			}
			catch(dbClassActionException $e) 
			{ 
				throw new httpStatusException(
					$e->getUserMessage($database), 
					httpStatusException::OBJECT_NOT_FOUND, 
					$e
				); 
			}
				
			// order succesful finded 
			if($order !== null)
			{
				// getting info about operation 
				$resultCode = $env->getParameter('RESULT')->getValue();
				$resultText = $env->getParameter('RCTEXT')->getValue();
				$operationCode = $env->getParameter('AUTHCODE')->getValue();
				$operationDatetime = $this->convertFromGMT($operationDatetime);
				
				// put this info into specific attributes for all languages (use odb::languageSwitcher iteration function)
				$this->database->languageSwitch->eachLanguage(function() use ($order, $operationCode, $operationDatetime) {
					$order->setAttributeValue('paymentOperationCode', $operationCode);
					$order->setAttributeValue('paymentOperationDate', $operationDatetime);
				});
				
				/* create and fill paymentOperation object */
				$paymentOperation = ($this->paymentSystem->hasPaymentOperationByCode($operationCode)) ? $this->paymentSystem->getPaymentOperationByCode($operationCode) : $this->paymentSystem->createPaymentOperation();
				$paymentOperation->creationTime = $operationDatetime;
				$paymentOperation->modificationTime = $operationDatetime;
				$paymentOperation->code = $operationCode;
				$paymentOperation->status = $resultCode;
				$paymentOperation->description = $resultText;
				$paymentOperation->order = $order;
				$paymentOperation->post();
				
				// 0 is success result, 1,2,3 - errors. order switch to next state 
				if($resultCode == 0)
					$this->paymentSystem->onPaymentSuccess($order);
				else
					$this->paymentSystem->onPaymentError($order);
			}
    	}
    	
	}

?>