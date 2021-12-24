<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Admin\PromoCodesController;
use App\Models\OrderDetail;
use App\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;
use App\Models\Mount;
use App\Models\Jewelry;
use App\Models\Diamond;
use App\Models\Affiliate;
use App\Models\PromoCodeUsage;
use DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use mysql_xdevapi\Exception;
use App\Models\SendEmailError;
use App\Jobs\OrderEmailJob;
use App\Jobs\SenderOrderEmailJob;
Use \Carbon\Carbon;
use View;
class OrdersController extends \App\Http\Controllers\Controller 
{
    public static function calculateCheckoutAmount($subTotal, $isReturnJson=false) {

        $subTotalSelCurrency = currencyConvert($subTotal);
        $amountAfterDiscount = $subTotalSelCurrency;
        $discountAmount      = 0;
        $promoCode           = null;

        $orderObj = Order::where('user_id', Auth::user()->id)->where('status', 0)->first();

        //Calculate discount
        Session::forget('coupon');
        Session::forget('promo_discount');
        Session::forget('after_promo_tax_calulated');
        Session::save();

        if($orderObj && null != $orderObj->promo_code && strlen($orderObj->promo_code) > 0) {
            $retArray = PromoCodesController::validatePromo($orderObj->promo_code, true);
            $promoCodeObj = $retArray[0];

            if(null != $promoCodeObj) {

                $promoCode = $promoCodeObj->code;
                if($promoCodeObj->type == PROMO_TYPE_CASH ) {
                    $discountAmount = currencyConvert($promoCodeObj->cash);
                }
                else {
                    $discountAmount = $subTotalSelCurrency * ($promoCodeObj->percentage / 100);
                }
                $discountAmount = round($discountAmount, 2);

                $orderObj->promo_discount = $discountAmount;
                $orderObj->save();
                $amountAfterDiscount =  round($subTotalSelCurrency - $discountAmount, 2);

                Session::put('coupon', $promoCodeObj->code);
                Session::put('promo_discount', $discountAmount);
                Session::save();
            }

        } //end of: $orderObj->promo_code != null

        //Calculate Tax
        $taxToPay = getTaxToPay($orderObj, $amountAfterDiscount);

        //Calculate final amount
        $totalAmountToPay = (getCurrencyCode() == 'RMB') ? round($amountAfterDiscount + $taxToPay) : round($amountAfterDiscount + $taxToPay, 2);

        if ($orderObj) {
            if(Session::has('currencyCode')) {
                $currencyCode = Session::get('currencyCode');
                $orderObj->currency_code = $currencyCode;
                $orderObj->currency_symbol = currencySymbol();
            }
    
            $orderObj->tax_duty = $taxToPay;
            $orderObj->amount = $totalAmountToPay;
            $orderObj->promo_discount = $discountAmount;
            OrdersController::addAffiliateCommission($orderObj);
            $orderObj->save();    
        }

        // Add Shipping Charge
        $shipping_charge = 0;
        if (getShippingCharges() != 0) {
            $shipping_charge = getShippingCharges();
            $totalAmountToPay = $totalAmountToPay + $shipping_charge;
        }

        if($orderObj){
            $orderObj->tax_duty = $taxToPay;
            $orderObj->shipping_charge = $shipping_charge;
            $orderObj->amount = $totalAmountToPay;
            $orderObj->promo_discount = $discountAmount;
            OrdersController::addAffiliateCommission($orderObj);
            $orderObj->save();
        }


        $totalAmountToPayUsd = getAmountInCurrency( $totalAmountToPay, 'USD');

        Session::put('after_promo_tax_calulated', $taxToPay);

        $returnArray = array(
            'total_amount_to_pay'     => number_format($totalAmountToPay,2),
            'total_amount_to_pay_usd' => number_format($totalAmountToPayUsd,2),
            'tax_to_pay'              => $taxToPay,
            'discount_amount'         => $discountAmount,
            'promo_code'              => $promoCode,
            'shipping_charge'         => $shipping_charge,
            'amount_without_tax'   =>  number_format($totalAmountToPay-$taxToPay,2),
            'amount_without_tax_shipping'   =>  number_format($totalAmountToPay-$taxToPay-$shipping_charge,2),
        );

        if($isReturnJson) {
            echo json_encode([
                'status' => 1,
                'total_amount_to_pay' => $returnArray['total_amount_to_pay'],
                'total_amount_to_pay_usd' => $returnArray['total_amount_to_pay_usd'],
                'tax_to_pay' => $returnArray['tax_to_pay'],
                'discount_amount' => $returnArray['discount_amount'],
                'promo_code' => $returnArray['promo_code'],
                'shipping_charge' => $returnArray['shipping_charge'],
                'amount_without_tax'   => number_format((float)str_replace(",","",$returnArray['total_amount_to_pay'])-(float)str_replace(",","",$returnArray['tax_to_pay']),2),
                'amount_without_tax_shipping' => number_format((float)str_replace(",","",$returnArray['total_amount_to_pay'])-(float)str_replace(",","",$returnArray['tax_to_pay'])-(float)str_replace(",","",$returnArray['shipping_charge']),2)
            ]);
        } else {
            return $returnArray;
        }

    }

    /**
     * Update order info from cart.
     * @param type $amount
     */
    public static function addOrUpdateOrderInfo()
    {
        //check order exist with the cart or not
        $order = Order::where('user_id', Auth::user()->id)->where('status', 0)->first();
        
        //If yes, Then Update info
        if($order)
        {
            $orderObj = $order;
        }
        else //Else create new order
        {
            $orderObj = new Order();
        }

        $orderObj->status = 0;
        $orderObj->user_id = Auth::user()->id;
//        $orderObj->amount = currencyConvert($amount);
//        $orderObj->tax_duty = currencyConvert($tax);
        $orderObj->shipping_date = date('Y-m-d');
//        $orderObj->promo_code = Session::has('coupon') ? Session::get('coupon') : NULL;
//        $orderObj->promo_discount = Session::has('promo_discount') ? Session::get('promo_discount') : NULL;
        $orderObj->created_at = date('Y-m-d h:i:s');
        //Shipping Details
        $orderObj->shipping_company = Auth::user()->shipping_company;
        $orderObj->shipping_firstname = Auth::user()->shipping_firstname;
        $orderObj->shipping_lastname = Auth::user()->shipping_lastname;
        $orderObj->shipping_address = Auth::user()->shipping_address;
        $orderObj->shipping_city = Auth::user()->shipping_city;
        $orderObj->shipping_country = Auth::user()->shipping_country;
        $orderObj->shipping_state = Auth::user()->shipping_state;
        $orderObj->shipping_zip_code = Auth::user()->shipping_zip_code;
        $orderObj->shipping_country_code = Auth::user()->shipping_country_code;
        $orderObj->shipping_phone = Auth::user()->shipping_phone;

        //Billing Details
        $orderObj->billing_company = empty(Auth::user()->billing_company) ? Auth::user()->shipping_company : Auth::user()->billing_company;
        $orderObj->billing_firstname = empty(Auth::user()->billing_firstname) ? Auth::user()->shipping_firstname : Auth::user()->billing_firstname;
        $orderObj->billing_lastname = empty(Auth::user()->billing_lastname) ? Auth::user()->shipping_lastname : Auth::user()->billing_lastname;
        $orderObj->billing_address = empty(Auth::user()->billing_address) ? Auth::user()->shipping_address : Auth::user()->billing_address;
        $orderObj->billing_city = empty(Auth::user()->billing_city) ? Auth::user()->shipping_city : Auth::user()->billing_city;
        $orderObj->billing_country = empty(Auth::user()->billing_country) ? Auth::user()->shipping_country : Auth::user()->billing_country;
        $orderObj->billing_state = empty(Auth::user()->billing_state) ? Auth::user()->shipping_state : Auth::user()->billing_state;
        $orderObj->billing_zip_code = empty(Auth::user()->billing_zip_code) ? Auth::user()->shipping_zip_code : Auth::user()->billing_zip_code;
        $orderObj->billing_country_code = empty(Auth::user()->billing_country_code) ? Auth::user()->shipping_country_code : Auth::user()->billing_country_code;
        $orderObj->billing_phone = empty(Auth::user()->billing_phone) ? Auth::user()->shipping_phone : Auth::user()->billing_phone;

        $orderObj->save();

        OrdersController::_updateOrderDetails($orderObj->id);

        if(null == $orderObj->promo_code) {
            Session::forget('coupon');
        } else {
            Session::put('coupon', $orderObj->promo_code);
        }
        Session::forget('promo_discount');
        Session::save();
    }
    
    private static function _updateOrderDetails($orderId)
    {
        //get all ids to Update or New
        $all_orders_ids = OrderDetail::whereOrderId($orderId)->pluck('id');
        $i = 0;
       // return $orderId;
        //Update Order Details
        $cartInfo = \App\Models\Cart::where('user_id', Auth::user()->id)->get();
        $shippingdays = [];
        foreach($cartInfo as $cartItem)
        {
            $orderDetail = new \App\Models\OrderDetail();

            $orderDetail->order_id = $orderId;
            $orderDetail->type = $cartItem->type;
            $orderDetail->reference_id = $cartItem->reference_id;
            $orderDetail->diamond_id = $cartItem->diamond_id;
            $orderDetail->metal_id = $cartItem->metal_id;
            $orderDetail->price = currencyConvert($cartItem->price);
            $orderDetail->ring_size = $cartItem->ring_size;
            $orderDetail->inscription = $cartItem->inscription;
            $orderDetail->ins_font = $cartItem->ins_font;
            $orderDetail->secondary_metal_id = $cartItem->secondary_metal_id;
            if(!$cartItem->diamond_id && $cartItem->reference_id)
            {
                $jewelry = Jewelry::where('id', $cartItem->reference_id)->first();
                
               $shippingdays[] = $jewelry->shipping_days;
            }
            if($cartItem->diamond_id && $cartItem->reference_id )
            {
                $mount = Mount::where('id', $cartItem->reference_id)->first();
                $shippingdays[] = $mount->shipping_days;
            }
            if($cartItem->diamond_id && !$cartItem->reference_id  )
            {
                $shippingdays[] = 5;
            }
            OrderDetail::updateOrCreate(['id' => (isset($all_orders_ids[$i]) ? $all_orders_ids[$i] : null)], $orderDetail->toArray());
            $order = Order::where('id', $orderId)->first();
            $order->shipping_date = date('Y-m-d', strtotime(deliveryDays(max($shippingdays))));
            $order->save();
            $i++;
        }
    }
    
    /**
     * 
     * @param type $cartId
     * @param type $inscription
     * @param type $font
     * save inscription if auth::user()
     */
    public function updateInscription($cartId, $inscription, $font)
    {
        $cart = \App\Models\Cart::where('id', $cartId)->first();
        $cart->inscription = $inscription;
        $cart->ins_font = $font;
        $cart->save();
        //$cartInfo = \App\Models\Cart::where('user_id', Auth::user()->id)->get();
        //dd($cartInfo);
        $order = Order::where('user_id', Auth::user()->id)->where('status',0)->first();
        
        $this->_updateOrderDetails($order->id);
       
       echo "success"; exit;
    }
    
    public function removeInscription($cartId)
    {
        $cart = \App\Models\Cart::where('id', $cartId)->first();
        $cart->inscription = "";
        $cart->ins_font = "";
        $cart->save();
        $order = Order::where('user_id', Auth::user()->id)->first();
        
       $this->_updateOrderDetails($order->id);
       
       echo "success"; exit;
    }

     /**
     * Stripe Pay request
     * @param type $token
     */
    public function stripePayRequest($token)
    {
        $successErrorMsg = "";
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $order = Order::where('user_id', Auth::user()->id)->where('status', 0)->firstorFail();
        $orderDetail = \App\Models\OrderDetail::where('order_id', $order->id)->get();
        $this->_checkDiamondSoldOrNot($orderDetail);
        try
        {
            $payableAmount = $order->amount;

            if(Session::has('currencyCode'))
            {
                $currencyRate =  strtolower(Session::get('currencyCode'));
            }

            $stripeCharge = \Stripe\Charge::create(array(
                "amount" => sprintf("%.2f", $payableAmount) * 100,
                "currency" => $currencyRate,
                "source" => $token,
                "description" => "Order: " . $order->id
            ));
               
            
            if ($stripeCharge->paid == 'true')
            {
                if($order->promo_code)
                {
                    $this->_currentUsage($order->promo_code);
                    Session::forget('coupon');
                    Session::save();
                }
                
                $order->currency_code = (Session::has('currencyCode')) ? Session::get('currencyCode') : 'USD';
                $order->currency_symbol = currencySymbol();
                $order->save();
                
                //update diamond
               $orderDetail = \App\Models\OrderDetail::where('order_id', $order->id)->get();
                foreach($orderDetail as $detail)
                {
                    if($detail->diamond_id >0 && $detail->type != WISH_TYPE_COMPLETE_EARRING && $detail->type != WISH_TYPE_PAIR_DIAMOND)
                    {
                        $diamond = Diamond::where('id', $detail->diamond_id)->first();
                        $diamond->Is_Sold = 1;
                        $diamond->Is_Sold_Time = date('Y-m-d h:i:s ', time());
                        $diamond->save();
                    }
                    
                    if($detail->diamond_id >0 && $detail->type == WISH_TYPE_PAIR_DIAMOND )
                    {
                        $diamonds = Diamond::where('Pair_Id', $detail->diamond_id)->get();
                        foreach($diamonds as $diamond)
                        {
                            $diamond->Is_Sold = 1;
                            $diamond->Is_Sold_Time = date('Y-m-d h:i:s ', time());
                            $diamond->save();
                        }
                    }
                    
                    if($detail->diamond_id >0 && $detail->type == WISH_TYPE_COMPLETE_EARRING)
                    {
                        $diamonds = Diamond::where('Pair_Id', $detail->diamond_id)->get();
                        foreach($diamonds as $diamond)
                        {
                            $diamond->Is_Sold = 1;
                            $diamond->Is_Sold_Time = date('Y-m-d h:i:s ', time());
                            $diamond->save();
                        }
                    }
                    
                    if($detail->diamond_id >0 )
                    {
                        DB::table('wishlists')->where('diamond_id', $detail->diamond_id )->delete();
                    }
                }

                //Send Order Email
              try {
                     $this->orderEmail($order->id);
                } catch (\Exception $e) {
                    $successErrorMsg = _i("Unable to send order email.");
                    Log::error("Error while sending order email for order: " . $order->id . ", Error: " . $e->getMessage());
                    Log::error($e);
                }


                if(isset($stripeCharge->source)) {
                    if(isset($stripeCharge->source->type)) {
                        $order->payment_type = $stripeCharge->source->type;
                    } else if(isset($stripeCharge->source->brand)) {
                        $order->payment_type = $stripeCharge->source->brand;
                    }
                }

                $order->transaction_id = $stripeCharge->id;
                $order->payment_status = 1;
                $order->status = 1;
                $order->order_date = now();
                $order->pg_full_response = serialize($stripeCharge);
                OrdersController::addAffiliateCommission($order);
                $order->save();

            }

            DB::table("carts")->where('user_id', Auth::user()->id)->delete();

            return json_encode(['status' => 1, 'message' => $successErrorMsg]); exit;
        }
        catch (\Exception $e)
        {
            return json_encode(['status' => 0, 'error' => $e->getMessage()]); exit;
        }
    }

    public function stripeCheckoutCallback(Request $request)
    {
        $response = "";
        try {
            $sourceToken = $request->input('source');

            $response = $this->stripePayRequest($sourceToken);
            $response = json_decode($res, true);

            if ("1" == $res['status']) {
                if(isset($res['message'])) {
                    return redirect('/thank-you?message=' . $res['message']);
                } else {
                    return redirect('/thank-you');
                }
            }

        } catch (ModelNotFoundException $e) {
            return redirect('/failed?error=Unable to generate response token for order model.');
        }catch (\Exception $e) {
            return redirect('/failed?error=Exception: ' . $e->getMessage());
        }

        $errorMsg = json_encode($response);
        if( array_key_exists("error", $response) ) {
            $errorMsg = $res["error"];
        }

        return redirect('/failed?error=' . $errorMsg);
    }
    
    /**
     * Paypal Pay request
     * @param type $nonce
     */
    public function paypalPayRequest($nonce)
    {
        $gateway = new \Braintree_Gateway([
            'accessToken' => PAYPAL_ACCESS_TOKEN,
        ]);
        
        $order = Order::where('user_id', Auth::user()->id)->where('status', 0)->firstorFail();
        
        $payableAmount = ($order->amount + $order->tax_duty) - (int) $order->promo_discount;
        $currency = Session::get('currencyCode');
        $result = $gateway->transaction()->sale(
        [
            "amount" => $payableAmount,
            'merchantAccountId' => $currency,
            "paymentMethodNonce" => $nonce,
            "orderId" => rand(1,1000).'-'.$order->id
        ]);

        if($result->success)
        {
            if($order->promo_code)
            {
                $this->_currentUsage($order->promo_code);
                Session::forget('coupon');
                Session::save();
            }
            
            $order->currency_code = (Session::has('currencyCode')) ? Session::get('currencyCode') : 'USD';
            $order->currency_symbol = currencySymbol();
            $order->save();

            //Send Order Email
          
            $this->orderEmail($order->id);


            
            $order->transaction_id = $result->transaction->id;
            $order->payment_status = 1;
            $order->status = 1;
            $order->order_date = now();
            $order->pg_full_response = serialize($result);
            OrdersController::addAffiliateCommission($order);
            $order->save();

            // change sold diamond status
            $order_details = DB::table('order_details')->where('order_id',$order->id)->get();
            if($order_details){
                foreach ($order_details as $key => $value) {
                    if($value->diamond_id){
                        $diamond = Diamond::where('id', $value->diamond_id)->first();
                        $diamond->Is_Sold = 1;
                        $diamond->Is_Sold_Time = date('Y-m-d h:i:s ', time());
                        $diamond->save();
                    }
                }
            }

            DB::table("carts")->where('user_id', Auth::user()->id)->delete();
            
            echo json_encode(['status' => 1]); exit;
        }
        else
        {
            echo json_encode(['status' => 0, 'error' => $result->message]); exit;
        }
    }
    
        
    /**
     * 
     * @return type
     * Payment failed page after order placed
     */
    public function paymentFailed(Request $request)
    {
        $title = _i("Payment Failed");
        $errorMsg = "";

        if(request()->has('error')) {
            $errorMsg = "Error: " . request()->error;
        }

        return view('Frontend.Orders.failed')->with(compact('title', 'errorMsg'));
    }
    
    public function siPayRequest()
    {    
        $client = new \GuzzleHttp\Client();
        
        $loginRes = $this->_loginToSplitit($client);
        
        if($loginRes['SessionId'])
        {
            $order = Order::where('user_id', Auth::user()->id)->where('status', 0)->firstorFail();
            $orderDetail = \App\Models\OrderDetail::where('order_id', $order->id)->get();
            $this->_checkDiamondSoldOrNot($orderDetail);
            
            $initiateRes = $this->_initiateSplitit($client, $order, $loginRes['SessionId']);
            
            if($initiateRes['ResponseHeader']['Succeeded'])
            {
                $order->emi_plan_number = $initiateRes['InstallmentPlan']['InstallmentPlanNumber'];
                $order->currency_code = 'USD';
                $order->currency_symbol = '$';
                OrdersController::addAffiliateCommission($order);
                $order->save();
                echo json_encode(['status' => 1, 'url' => $initiateRes['CheckoutUrl']]); exit;
            }
            else
            {
                echo json_encode(['status' => 0, 'error' => $initiateRes['ResponseHeader']['Errors'][0]['Message']]); exit;
            }
        }
        else
        {
            echo json_encode(['status' => 0, 'error' => 'Splitit not working, please try again later.']); exit;
        }
        
    }
    
    private function _loginToSplitit($client)
    {
        //Login to SplitIt for SessionID
        $loginURL = SPLITIT_API_URL . '/api/Login?format=json';
        
        $res = $client->get($loginURL, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => '{"UserName": "PrinceSANDBOXapi", "Password": "pT0faASc"}'
        ]);
        
        return json_decode((string) $res->getBody(), true);
    }
    
    private function _initiateSplitit($client, $order, $sessionId)
    {
        //Login to SplitIt for SessionID
        $loginURL = SPLITIT_API_URL . '/api/InstallmentPlan/Initiate?format=json';
        $payableAmount = round(($order->amount + $order->tax_duty) - (int) $order->promo_discount , 2);
        $name = $order->billing_firstname . ' ' . $order->billing_lastname;
        $res = $client->get($loginURL, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => '{
                            "RequestHeader": {
                              "SessionId": "'. $sessionId .'",
                              "ApiKey": '. SPLITIT_API_KEY .'
                            },
                            "PlanData": {
                              "Amount": {"Value": '. $payableAmount .',"CurrencyCode": "USD"},
                              "NumberOfInstallments": 3,    
                              "RefOrderNumber": "'. $order->id .'",
                              "AutoCapture": true
                            },
                            "BillingAddress": {
                              "AddressLine": "'. $order->billing_address .'",
                              "City": "'. $order->billing_city .'",
                              "State": "'. $order->billing_state .'",
                              "Country": "'. $order->billing_country .'",
                              "Zip": "'. $order->billing_zip_code .'"
                            },
                            "ConsumerData": {
                              "FullName": "'. $name .'",
                              "Email": "'. Auth::user()->email .'",
                              "PhoneNumber": "'. $order->billing_phone .'"
                            },
                            "RedirectUrls": {
                              "Succeeded": "'. env('SITE_URL') .'thank-you",
                              "Failed": "'. env('SITE_URL') .'failed",
                              "Canceled": "'. env('SITE_URL') .'failed"
                            }
                        }'
        ]);
        
        return json_decode((string) $res->getBody(), true);
    }   
}
