<?php
	/**
	 * Payment gataway abstract class
	 * 
	 */
	abstract class paymentGateway
	{
		// Payment system for this gateway
		protected $paymentSystem;

		/**
		 * Constructs a new payment gateway
		 * 
		 * @param object $paymentSystem
		 */
		public function __construct($paymentSystem)
		{
			// Set the payment system
			$this->paymentSystem = $paymentSystem;
		}

		/**
		 * Returns an URL for requests to the gateway
		 */
		abstract protected function getRequestUrl();
		
		/**
		 * Returns a request type (GET, POST, PUT)
		 */
		abstract protected function getRequestType();

		/**
		 * Генерирует и возвращает параметры запроса к шлюзу
		 * Generates and returns parameters of a request to the gateway
		 */
		abstract protected function generateRequestParameters($order, $env);
		
		/**
		 * Translates gataway currency format to standard currency format
		 * 
		 * @param string $currency Gataway currency
		 * @return string Standard currency
		 */
		protected function translateCurrency($currency)
		{
			return $currency;
		}
		
		/**
		 * Processes a response from a gateway
		 * 
		 * @param object $env Environment parameters
		 * @param db $database Database
		 */
		abstract protected function processResponse($env, db $database);
	}
