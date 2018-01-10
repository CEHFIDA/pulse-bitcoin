<?php

namespace Selfreliance\PulseBitcoin;

use Illuminate\Http\Request;
use Config;
use Route;
use Log;

use Illuminate\Foundation\Validation\ValidatesRequests;

use Selfreliance\PulseBitcoin\Events\PulseBitcoinPaymentIncome;
use Selfreliance\PulseBitcoin\Events\PulseBitcoinPaymentCancel;

use Selfreliance\PulseBitcoin\PulseBitcoinInterface;
use Selfreliance\PulseBitcoin\Exceptions\PulseBitcoinException;
use GuzzleHttp\Client;
use App\Models\Users_History;

class PulseBitcoin implements PulseBitcoinInterface
{
	use ValidatesRequests;
	public $client;

	public function __construct(){
		$this->client = new Client([
		    'base_uri' => Config::get('pulsebitcoin.base_uri'),
			'form_params' => [
		        'key' => Config::get('pulsebitcoin.secret_key')
		    ]		    
		]);
	}

	function balance($currency = 'BTC'){
		$response = $this->client->request('POST', 'getbalance');
		$body     = $response->getBody();
		$code     = $response->getStatusCode();
		$resp     = json_decode($body->getContents());
		

		if(property_exists($resp, 'code')){
			throw new \Exception($resp->res->msg);
		}
		$unconfirmed = 0;
		if(property_exists($resp, 'unconfirmed')){
			$unconfirmed = $resp->result->unconfirmed;
		}
		return $resp->result->confirmed+$unconfirmed;
	}

	function form($payment_id, $sum, $units='BTC'){
		$sum = number_format($sum, 2, ".", "");
		$response = $this->client->request('POST', 'createnewaddress', [
			'form_params' => [
				'key'      => Config::get('pulsebitcoin.secret_key'),
		    ]
		]);
		$body     = $response->getBody();
		$code     = $response->getStatusCode();
		$resp     = json_decode($body->getContents());	
		$PassData = new \stdClass();

		if(property_exists($resp, 'code')){
			$PassData->error = $resp->res->msg;
		}else{
			$PassData->address = $resp->result;
			$PassData->another_site = false;
		}
		
		return $PassData;
	}

	public function check_transaction(array $request, array $server, $headers = [], $ip = ''){
		Log::info('Pulse Bitcoin IPN', [
			'request' => $request
		]);
		$textReponce = 'Server error pulse bitcoin';
		try{
			$is_complete = $this->validateIPN($request, $server);
			if($is_complete){
				// TODO
				// change seach transaction
				$found_history = false;
				foreach($request['tx']['Address'] as $address){
					$history = Users_History::
						where('type', 'CREATE_DEPOSIT')->
						whereIn('payment_system', [1,2])->
						where('status', 'pending')->
						where('transaction', '')->
						where('data_info->address', $address)->
						value('id');
					dump($history);
					if($history){
						$found_history = $history;
						break;
					}
				}
				if($found_history){
					$PassData                     = new \stdClass();
					$PassData->amount             = $request['tx']['Amount'];
					$PassData->payment_id         = $found_history;
					$PassData->transaction        = $request['tx']['TxID'];
					$PassData->add_info           = [
						"full_data_ipn" => json_encode($request)
					];
					event(new PulseBitcoinPaymentIncome($PassData));
					$textReponce = $request['tx']['_id']."|success";
				}else{
					Log::error('Pulse Bitcoin IPN', [
						'message' => 'Don\'t find history',
						'data'    => $request
					]);
					$textReponce = $request['tx']['_id']."|error_find_history";	
				}
			}else{
				$textReponce = $request['tx']['_id']."|error";	
			}			
		}catch(PulseBitcoinException $e){
			Log::error('Pulse Bitcoin IPN', [
				'message' => $e->getMessage(),
				'data'    => $request
			]);
			
			$textReponce = $request['tx']['_id']."|continue|".$e->getMessage();
		}

		return \Response::json($textReponce, "200");
	}

	public function validateIPN(array $post_data, array $server_data){
		if(!isset($post_data['tx']['Confirmations'])){
			throw new PulseBitcoinException("Missing the required confirmations");
		}

		if($post_data['tx']['Confirmations'] < 6){
			throw new PulseBitcoinException("Missing the required number of confirmations ".$post_data['tx']['Confirmations'].' of 6');
		}

		if(!isset($post_data['tx']['TxID'])){
			throw new PulseBitcoinException("Need transaction");	
		}

		if($post_data['tx']['Amount'] <= 0){
			throw new PulseBitcoinException("Need amount for transaction");	
		}

		if(!isset($post_data['tx']['HashPay'])){
			throw new PulseBitcoinException("The transaction need HashPay");	
		}

		if($post_data['tx']['HashPay'] != md5($post_data['tx']['_id'].Config::get('pulsebitcoin.secret_key'))){
			throw new PulseBitcoinException("The transaction failed to authenticate");	
		}

		// if($post_data['tx']['timestamp'] == false){
		// 	throw new PulseBitcoinException("Need timestamp");	
		// }

		if(count($post_data['tx']['Address']) < 1){
			throw new PulseBitcoinException("Need Address");	
		}

		return true;
	}

	public function validateIPNRequest(Request $request) {
        return $this->check_transaction($request->all(), $request->server(), $request->headers, $request->ip());
    }

	public function send_money($payment_id, $amount, $address, $currency){
		$fee = 0.0003;
		$response = $this->client->request('POST', 'payto', [
			'form_params' => [
				'key'      => (string)Config::get('pulsebitcoin.secret_key'),
				'address'  => $address,
				'amount'   => $amount,
				'fee'      => $fee,
				'password' => 'password'
		    ]
		]);
		// $response = $this->client->request('POST', 'paytomany', [
		// 	'json' => [
		// 		'key'     => (string)Config::get('pulsebitcoin.secret_key'),
		// 		'outputs' => [
		// 			[
		// 				$address,
		// 				0.0001,
		// 			]
		// 		],
		// 		'fee'      => $fee,
		// 		'password' => 'password'
		//     ]
		// ]);
		$body     = $response->getBody();
		$code     = $response->getStatusCode();
		$resp     = json_decode($body->getContents());

		Log::info('PulseBitcoin', [
			'request' => $resp
		]);

		if(property_exists($resp, 'code')){
			throw new \Exception($resp->res->msg);
		}

		if(property_exists($resp, 'result') && $resp->result[0] == true){
			$PassData              = new \stdClass();
			$PassData->transaction = $resp->result[1];
			$PassData->sending     = true;
			$PassData->add_info    = [
				"fee"       => $fee,
				"full_data" => $resp
			];
			return $PassData;
		}else{
			throw new \Exception($resp);	
		}
	}

	public function cancel_payment(Request $request){

	}

	public function history(){
		$response = $this->client->request('POST', 'history', [
			'form_params' => [
				'key'      => Config::get('pulsebitcoin.secret_key'),
		    ]
		]);
		$body     = $response->getBody();
		$code     = $response->getStatusCode();
		$resp     = json_decode($body->getContents());

		return $resp;
	}

	public function balance_address($address){
		$response = $this->client->request('POST', 'getaddressbalance', [
			'form_params' => [
				'key'     => Config::get('pulsebitcoin.secret_key'),
				'address' => $address,
		    ]
		]);
		$body     = $response->getBody();
		$code     = $response->getStatusCode();
		$resp     = json_decode($body->getContents());

		return $resp;
	}
}