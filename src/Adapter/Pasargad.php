<?php
namespace Tartan\Larapay\Adapter;

use Illuminate\Support\Facades\Log;
use Tartan\Larapay\Adapter\Pasargad\Helper;
use Tartan\Larapay\Adapter\Pasargad\RSAKeyType;
use Tartan\Larapay\Adapter\Pasargad\RSAProcessor;

class Pasargad extends AdapterAbstract implements AdapterInterface
{

	protected $endPoint = 'https://pep.shaparak.ir/gateway.aspx';
	protected $checkTransactionUrl = 'https://pep.shaparak.ir/CheckTransactionResult.aspx';
	protected $verifyUrl = 'https://pep.shaparak.ir/VerifyPayment.aspx';
	protected $refundUrl = 'https://pep.shaparak.ir/doRefund.aspx';


	protected $testEndPoint = 'http://banktest.ir/gateway/pasargad/gateway';
	protected $testCheckTransactionUrl = 'http://banktest.ir/gateway/pasargad/CheckTransactionResult';
	protected $testVerifyUrl = 'http://banktest.ir/gateway/pasargad/VerifyPayment';
	protected $testRefundUrl = 'http://banktest.ir/gateway/pasargad/doRefund';


	protected function generateForm()
	{
		$this->checkRequiredParameters([
			'amount',
			'order_id',
			'redirect_url'
		]);

		$processor = new RSAProcessor(config('larapay.pasargad.certificate_path'), RSAKeyType::XMLFile);

		$url           = $this->getEndPoint();
		$redirectUrl   = $this->redirect_url;
		$invoiceNumber = $this->order_id;
		$amount        = $this->amount;
		$terminalCode  = config('larapay.pasargad.terminalId');
		$merchantCode  = config('larapay.pasargad.merchantId');
		$timeStamp     = date("Y/m/d H:i:s");
		$invoiceDate   = date("Y/m/d H:i:s");
		$action        = 1003; // sell code

		$data          = "#" . $merchantCode . "#" . $terminalCode . "#" . $invoiceNumber . "#" . $invoiceDate . "#" . $amount . "#" . $redirectUrl . "#" . $action . "#" . $timeStamp . "#";
		$data          = sha1($data, true);
		$data          = $processor->sign($data); // امضاي ديجيتال
		$sign          = base64_encode($data); // base64_encode

		return view('larapay::pasargad-form')->with(compact(
			'url',
			'redirectUrl',
			'invoiceNumber',
			'invoiceDate',
			'amount',
			'terminalCode',
			'merchantCode',
			'timeStamp',
			'action',
			'sign'
		));
	}

//	public function inquiryTransaction ()
//	{
//
//	}

	protected function verifyTransaction()
	{
		$this->checkRequiredParameters([
			'iN',
			'iD',
			'tref',
		]);

		// update transaction reference number
		if (!empty($this->tref)) {
			$this->setInvoiceReferenceId($this->tref); // update transaction reference id
		}

		$processor = new RSAProcessor(config('larapay.pasargad.certificate_path'), RSAKeyType::XMLFile);

		$terminalCode  = config('larapay.pasargad.terminalId');
		$merchantCode  = config('larapay.pasargad.merchantId');
		$invoiceNumber = $this->iN;
		$invoiceDate   = $this->iD;
		$amount        = $this->getTransaction()->getAmount();
		$timeStamp     = date("Y/m/d H:i:s");

        $data          = "#" . $merchantCode . "#" . $terminalCode . "#" . $invoiceNumber . "#" . $invoiceDate . "#" . $amount . "#" . $timeStamp . "#";
        Log::debug('pasargad generated sign string: ' . $data);
        $data          = sha1($data, true);
        $data          = $processor->sign($data); // امضاي ديجيتال
        $sign          = base64_encode($data); // base64_encode
        Log::debug('pasargad generated hash: ' . $sign);

		$parameters = compact(
			'terminalCode',
			'merchantCode',
			'invoiceNumber',
			'invoiceDate',
			'amount',
			'timeStamp',
			'sign'
		);

		$result = Helper::post2https($parameters , $this->getVerifyUrl());

		$array  = Helper::parseXML($result, [
			'invoiceNumber' => $this->iN,
			'invoiceDate'   => $this->iD
		]);

		Log::debug('pasargad verify parseXML result', $array);

		if ($array['actionResult']['result'] != "True") {
			throw new Exception('larapay::larapay.verification_failed');
		} else {
			$this->getTransaction()->setVerified();
			return true;
		}
	}

	protected function reverseTransaction()
	{
		$this->checkRequiredParameters([
			'iN',
			'iD',
			'tref',
		]);

		// update transaction reference number
		if (!empty($this->tref)) {
			$this->setInvoiceReferenceId($this->tref); // update transaction reference id
		}

		$processor = new RSAProcessor(config('larapay.pasargad.certificate_path'), RSAKeyType::XMLFile);

		$terminalCode  = config('larapay.pasargad.terminalId');
		$merchantCode  = config('larapay.pasargad.merchantId');
		$invoiceNumber = $this->iN;
		$invoiceDate   = $this->iD;
		$amount        = $this->getTransaction()->getAmount();
		$timeStamp     = date("Y/m/d H:i:s");
		$action        = 1004; // reverse code

		$data          = "#" . $merchantCode . "#" . $terminalCode . "#" . $invoiceNumber . "#" . $invoiceDate . "#" . $amount . "#" . $action . "#" . $timeStamp . "#";
		$data          = sha1($data, true);
		$data          = $processor->sign($data); // امضاي ديجيتال
		$sign          = base64_encode($data); // base64_encode

		$parameters = compact(
			'terminalCode',
			'merchantCode',
			'invoiceNumber',
			'invoiceDate',
			'amount',
			'timeStamp',
			'action',
			'sign'
		);

		$result = Helper::post2https($parameters , $this->getRefundUrl());
		$array  = Helper::parseXML($result, [
			'invoiceNumber' => $this->iN,
			'invoiceDate'   => $this->iD
		]);

		Log::debug('pasargad refund parseXML result', $array);

		if ($array['actionResult']['result'] != "True") {
			throw new Exception('larapay::larapay.reversed_failed');
		} else {
			$this->getTransaction()->setReversed();
			return true;
		}
	}

	protected function getVerifyUrl()
	{
		if (config('larapay.mode') == 'production') {
			return $this->verifyUrl;
		} else {
			return $this->testVerifyUrl;
		}
	}

	protected function getRefundUrl()
	{
		if (config('larapay.mode') == 'production') {
			return $this->refundUrl;
		} else {
			return $this->testRefundUrl;
		}
	}

	protected function getInquiryUrl()
	{
		if (config('larapay.mode') == 'production') {
			return $this->checkTransactionUrl;
		} else {
			return $this->testCheckTransactionUrl;
		}
	}

    /**
     * @return bool
     */
    public function canContinueWithCallbackParameters()
    {
        if (!empty($this->getParameter('tref'))) {
            return true;
        }
        return false;
    }


    public function getGatewayReferenceId()
    {
        $this->checkRequiredParameters([
            'tref',
        ]);

        return $this->tref;
    }
}