<?php

namespace paymentGateway
{
	include_once("lib/payment/gateway.php");

	use \DateTime;
	use \str;
	use \db;

	class exception extends \Exception { }
	
	/**
	 * QIWI Wallet payment system
	 */
	class qiwi extends \paymentGateway
	{
		/**
		 * Returns an URL for requests to the gateway
		 */
		public function getRequestUrl() 
		{
			// Берем URL из параметров платежной системы
			return $this->paymentSystem->getParameterByName('query.url')->value;
		}

		/**
		 * Returns a request type (GET, POST, PUT)
		 */
		public function getRequestType() 
		{
			// Get the request type from the payment system's parameter
			return $this->paymentSystem->getParameterByName('query.type')->value;
		}

		/**
		 * Makes a bill for the QIWI system using a phone number
		 * 
		 * @param object $order Order number
		 */
		private function bill($order)
		{
			// Get order and gateway parameters
			$orderId = sprintf("%05s", $order);
			$shopId = $this->paymentSystem->getParameterByName('gateway.shop.id')->value;
			$gatewayApplicationId = $this->paymentSystem->
				getParameterByName('gateway.application.id')->value;
			$gatewayApplicationPassword = $this->paymentSystem->
				getParameterByName('gateway.application.password')->value;

			// Build URL for bill request
			$baseBillUrl = $this->paymentSystem->getParameterByName('gateway.bill.url')->value;
			$billUrl = "{$baseBillUrl}{$shopId}/bills/{$orderId}";

			// Get phone from customer attributes
			if ($order->customer->group->hasAttributeBySpecialRole('phone'))
			{
				$phoneAttribute = $order->customer->group
					->getAttributeBySpecialRole('phone');
				if($order->customer->hasAttributeValueByAttribute($phoneAttribute))
				{
					$customerPhone = $order->customer
						->getAttributeValueByAttribute($phoneAttribute)->string;
				}
			}

			// Make bill lifetime (current datetime + config lifetime)
			$lifetime = $this->paymentSystem->getParameterByName('orders.lifetime')->value;
			$currentDatetime = new DateTime('NOW');
			$currentDatetime->modify('+'.$lifetime);

			// Request headers description
			$headers = [
				'Accept: application/json',
				'Content-Type: application/x-www-form-urlencoded; charset=utf-8'
			];

			// Query parameters
			$data = http_build_query([
				'user' => 'tel:'.$customerPhone,
				'amount' => $order->getTotalSum(),
				'ccy' => (string)$this->translateCurrency($order->currency),
				'comment' => str::liteFormat(
					$this->paymentSystem->getParameterByName('gateway.bill.comment')->value,
					['siteName' => $order->site->name]
				),
				'lifetime' => $currentDatetime->format(DateTime::ISO8601)
			]);

			// Build query with curl library
			$ch = curl_init();

			// Basic url parameters
			curl_setopt($ch, CURLOPT_URL, $billUrl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

			// Authorization url parameters
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, $gatewayApplicationId.':'.$gatewayApplicationPassword);

			curl_exec($ch);
		}

		/**
		 * Generates request parameters for querying a payment gateway
		 * 
		 * @param object $order
		 * @param object $env
		 * @return array
		 */
		public function generateRequestParameters($order, $env) 
		{
			// Make a bill for customer
			$this->bill($order);

			// Get order and gateway parameters
			$orderId = sprintf("%05s", $order);
			$shopId = $this->paymentSystem->getParameterByName('gateway.shop.id')->value;

			// Fill required parameters
			$hostName = $env->getRoot()->hostName;
			$parameters = [];
			$parameters["shop"] = $shopId;
			$parameters["transaction"] = $orderId;
			$parameters["successUrl"] = "http://".$hostName.$order->getPublishedPath()
				.'?page.content.order.payment.info.display=1';
			$parameters["failUrl"] =  "http://".$hostName.$order->getPublishedPath()
					.'?page.content.order.payment.info.display=1&page.content.order.payment.error=1';

			return $parameters;
		}

		/**
		 * Processes a resopnse of a payment gateway
		 * 
		 * @param type $env
		 * @param db $database
		 * @return type
		 * @throws exception
		 */
		public function processResponse($env, db $database)
		{
			// Ordered array of parameters involved in the formation of the signature
			$keyParameters = [
				$env->getParameter('amount')->getValue(), 
				$env->getParameter('bill_id')->getValue(),
				$env->getParameter('ccy')->getValue(),
				$env->getParameter('command')->getValue(),
				$env->getParameter('comment')->getValue(),
				$env->getParameter('error')->getValue(),
				$env->getParameter('prv_name')->getValue(),
				$env->getParameter('status')->getValue(),
				$env->getParameter('user')->getValue()
			];

			// Get secret key from the payment system parameters
			$key = $this->paymentSystem->getParameterByName('gateway.key')->value;

			// Take the response signature out of the special HTTP header
			if(isset($_SERVER['HTTP_X_API_SIGNATURE']))
				$sign = $_SERVER['HTTP_X_API_SIGNATURE'];
			else throw new exception("Header 'X-Api-Signature' is not found in request");

			// Generate the signature
			$string = implode('|', $keyParameters);
			$hash = base64_encode(hash_hmac('sha1', $string, $key, true));

			// Check that the generated signature matches the recieved signature
			if(strcmp($sign, $hash) != 0)
				throw new exception("Request authentication error, wrong signature");

			// Get the order number from the response parameter
			$orderId = $env->getParameter('bill_id')->getValue();

			// Get the order object
			try
			{
				$order = $database->getSite()->getMarket()->getOrderById($orderId);
			}
			catch(exception $e) 
			{ 
				throw new exception("The requested order is not found");
			}

			// Get info about operation 
			$operationStatus = $env->getParameter('status')->getValue();
			$operationCode = uniqid($orderId);
			$currentDatetime = new DateTime('NOW');
			$operationDatetime = $currentDatetime->format('Y-m-d H:i:s');

			// Put this info into specific attributes (by system system name)
			$database->languageSwitch->eachLanguage(
				function() use ($order, $operationCode, $operationDatetime)
				{
					$order->setAttributeValue('paymentOperationCode', $operationCode);
					$order->setAttributeValue('paymentOperationDate', $operationDatetime);
				}
			);

			// Create and fill the paymentOperation object
			$paymentOperation = $this->paymentSystem->hasPaymentOperationByCode($operationCode) ?
				$this->paymentSystem->getPaymentOperationByCode($operationCode) :
				$this->paymentSystem->createPaymentOperation();
			$paymentOperation->creationTime = $operationDatetime;
			$paymentOperation->modificationTime = $operationDatetime;
			$paymentOperation->code = $operationCode;
			$paymentOperation->status = ($operationStatus == 'paid') ? 0 : 1;
			$paymentOperation->order = $order;
			$paymentOperation->post();

			// Check the response status
			if($operationStatus == 'paid')
				// On success call the success payment handler
				$this->paymentSystem->onPaymentSuccess($order);
			else
				// On error call the success error handler
				$this->paymentSystem->onPaymentError($order);

			// Return the success status for the QIWI Wallet system
			$xml = '<?xml version="1.0"?> <result><result_code>0</result_code></result>';
			return [$xml, 'text/xml'];
		}
	}
}
