<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Validator;
use App\Models\Payee;
use App\Models\SwiftCode;
use App\Models\Country;

use Illuminate\Http\Request;

class PayeeAPIController extends Controller
{   /**
    * 
    * @return type
    * Get All Payee Records Api
    */
    public function allPayeeRecords()
    {
        $allRecords = Payee::with('country')->orderBy('id', 'desc')->get();
        return json_encode(['status' => 200, 'data' => $allRecords]);
    }
    /**
    * 
    * @return type
    * API to add Payee Record
    */

    public function addPayeeAPI(Request $request)
    {
        if ($request->_token) {
            $validator = Validator::make($request->all(), [
                'payee_name' => 'required',
                'input_payee_alias' => 'required',
                'account_number' => 'required|alpha_num',
                'currency_code' => 'required',
                'address' => 'required',
                'state_id' => 'required',
                'country_id' => 'required',
                'swift_code' => 'required|min:8|max:11',
                'bank_name' => 'required',
                'us_routing_number' => 'max:9',
                'us_ach_routing_number' => 'required',
                'us_wire_routing_number' => 'required',
                'ca_routing_number' => 'max:8',
                'in_ifsc_code' => 'max:11',
                'url' => 'nullable|regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
                'email' => 'required',
                'description' => 'required',
                'country_id' => [
                    'required',
                    function ($attribute, $value, $fail) use ($request) {
                        $code = SwiftCode::where('bank_country', '=', $request->country_name)
                            ->where('swift_code', '=', $request->swift_code)->where('bank_name', '=', $request->bank_name)->get();

                        if (!$code) {
                            $fail('Swift code is not valid for this country.');
                        }
                    },
                ],
            ]);
        } else {
            $validator = Validator::make($request->all(), [
                'payee_name' => 'required',
                'input_payee_alias' => 'required',
                'account_number' => 'required|alpha_num',
                'currency_code' => 'required',
                'address' => 'required',
                'swift_code' => 'required|max:11',
                'bank_name' => 'required',
                'us_routing_number' => 'max:9',
                'ca_routing_number' => 'max:8',
                'in_ifsc_code' => 'max:11',
                'url' => 'regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
                'description' => 'required',
            ]);
        }
        if ($validator->fails()) {
            return json_encode($validator->errors(), 422);
        } else {
            $swiftCodeLen = strlen($request->swift_code);
            if ($swiftCodeLen > 8) {
                $swift_code = rtrim($request->swift_code, 'XXX');
            } else {
                $swift_code = $request->swift_code;
            }
            if ($request->flagged == '') {
                $request->flagged = 0;
                $request->merge([
                    'flagged' => $request->flagged
                ]);
            }
            $payeeRecord = Payee::create($request->all());
            return json_encode(['status' => 'ok', 'message' => 'Payee Record sccessfully saved!', 'data' => $payeeRecord]);
        }
    }

    public function updatePayeeAPI(Request $request, $payeeId)
    {
        if ($request->_token) {
            $validator = Validator::make($request->all(), [
                'payee_name' => 'required',
                'input_payee_alias' => 'required',
                'account_number' => 'required|alpha_num',
                'currency_code' => 'required',
                'address' => 'required',
                'state_id' => 'required',
                'country_id' => 'required',
                'swift_code' => 'required|min:8|max:11',
                'bank_name' => 'required',
                'us_routing_number' => 'max:9',
                'ca_routing_number' => 'max:8',
                'in_ifsc_code' => 'max:11',
              
                'url' => 'nullable|regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
                'email' => 'required',
                'description' => 'required',
                'country_id' => 'required',
                'country_id' => [
                    'required',
                    function ($attribute, $value, $fail) use ($request) {
                        $code = SwiftCode::where('bank_country', '=', $request->country_name)
                            ->where('swift_code', '=', $request->swift_code)->where('bank_name', '=', $request->bank_name)->get();

                        if (!$code) {
                            $fail('Swift code is not valid for this country.');
                        }
                    },
                ],
            ]);
        } else {
            $validator = Validator::make($request->all(), [
                'payee_name' => 'required',
                'input_payee_alias' => 'required',
                'account_number' => 'required|alpha_num',
                'currency_code' => 'required',
                'address' => 'required',
                'swift_code' => 'required|max:11',
                'bank_name' => 'required',
                'us_routing_number' => 'max:9',
                'ca_routing_number' => 'max:8',
                'in_ifsc_code' => 'max:11',
                'url' => 'regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
                'description' => 'required',
            ]);
        }
        if ($validator->fails()) {
            return json_encode($validator->errors(), 422);
        } else {
            $swiftCodeLen = strlen($request->swift_code);
            if ($swiftCodeLen > 8) {
                $swift_code = rtrim($request->swift_code, 'XXX');
            } else {
                $swift_code = $request->swift_code;
            }
            if ($request->flagged == '') {
                $request->flagged = 0;
                $request->merge([
                    'flagged' => $request->flagged
                ]);
            }
            $updatePayeeRecord =  Payee::findOrFail($payeeId);
            $updatePayeeRecord->update($request->all());
            return json_encode(['status' => 'ok', 'message' => 'Payee Record sccessfully updated!', 'data' => $updatePayeeRecord]);
        }
    }

    public function deletePayeeAPI($payeeId)
    {
        $payeeRecord = Payee::destroy($payeeId);
        if ($payeeRecord == 1) {
            return json_encode(['status' => 'ok', 'message' => 'Record deleted sccessfully!']);
        } else {
            return json_encode(['status' => 'failed', 'message' => 'Record not found!']);
        }
    }
}
