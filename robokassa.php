<?php

namespace paymentGateway
{
	include_once("lib/payment/gateway.php");

	use \DateTime;
	use \str;
	use \db;
	
	/**
	 * Robokassa payment system
	 */
	class robokassa extends \paymentGateway
	{
		/**
		 * Returns an URL for requests to the gateway
		 */
		public function getRequestUrl() 
		{
			// Get the URL from the payment system's parameter
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
		 * Generates request parameters for querying a payment gateway
		 * 
		 * @param object $order
		 * @param object $env
		 * @return array
		 */
		public function generateRequestParameters($order, $env) 
		{
			// Get order's id
			$orderId = sprintf("%05s", $order);

			// Get the login and the password from the payment system's parameters
			$gatewayLogin = $this->paymentSystem
				->getParameterByName('gateway.login')->value;
			$gatewayFirstPassword = $this->paymentSystem
				->getParameterByName('gateway.firstPassword')->value;
			
			// Check if necessary parameters are set
			if($gatewayLogin == null || $gatewayFirstPassword == null)
				throw new \Exception("Login or password are not set for the Robokassa merchant");
			
			// Get the order's total sum
			$orderSum = $order->getTotalSum();
			
			// Convert the order's sum to the russian ruble (RUB) currency,
			// which is the only currency the Robokassa operates in.
			// Use the market's source of exchange rates
			if($order->currency != 'RUB')
			{
				if($order->market->exchangeRatesSource !== null)
					$orderSum = $order->market->exchangeRatesSource
						->convertValue($order->getTotalSum(), $order->currency, 'RUB');
				else
					throw new \Exception("Can't convert the currency for the Robokassa merchant:"
						." an exchange rates source is not specified for the market"
					);
			}
			
			// Fill the required query parameters
			$parameters = [];
			$parameters["MerchantLogin"] = $gatewayLogin;
			$parameters["OutSum"] = $orderSum;
			$parameters["InvId"] = $orderId;
			$parameters["InvDesc"] = str::liteFormat(
				$this->paymentSystem->getParameterByName('gateway.bill.comment')->value,
				['siteName' => $order->site->name]
			);
			$parameters["Encoding"] = 'utf-8';
			$marketName = $order->market->getName();
			$parameters["Shp_marketName"] = $marketName;
			$parameters["SignatureValue"] = md5("$gatewayLogin:$orderSum:"
				."$orderId:$gatewayFirstPassword:Shp_marketName=$marketName"
			);

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
			// Get the second password from the payment system's parameters
			$gatewaySecondPassword = $this->paymentSystem
				->getParameterByName('gateway.secondPassword')->value;
			
			// Get the parameter values from the response
			$gatewaySignature = $env->getParameter('SignatureValue')->getValue();
			$gatewayOrderId = $env->getParameter('InvId')->getValue();
			
			// Ordered array of parameters involved in the formation of the signature
			$keyParameters = [
				$env->getParameter('OutSum')->getValue(), 
				$gatewayOrderId,
				$gatewaySecondPassword,
				"Shp_marketName=".$env->getParameter('Shp_marketName')->getValue()
			];
			
			// Generate the signature
			$signature = md5(implode(':', $keyParameters));
			
			// Check that the generated signature matches the recieved signature
			if(strcasecmp($signature, $gatewaySignature) != 0)
				throw new \Exception("Request authentication error, wrong signature");
			
			// Get the corresponding order
			try
			{
				$order = $database->getSite()->getMarket()->getOrderById($gatewayOrderId);
			}
			catch(exception $e) 
			{ 
				throw new \Exception("The requested order is not found");
			}

			// Get the info about the payment operation
			$operationStatus = 'Ok';
			$operationCode = uniqid($gatewayOrderId);
			$currentDatetime = new DateTime('NOW');
			$operationDatetime = $currentDatetime->format('Y-m-d H:i:s');

			// Put this info into the specific attributes
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
			$paymentOperation->status = $operationStatus;
			$paymentOperation->order = $order;
			$paymentOperation->post();
			
			// Call the success payment handler
			$this->paymentSystem->onPaymentSuccess($order);
			
			// Return the success status for the QIWI Wallet system (required format is "OK$orderNumber")
			return ["OK$gatewayOrderId\n", 'text/plain'];
		}
	}
}
