<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payee;
use App\Models\Country;
use App\Models\State;
use App\Models\SwiftCode;
use App\Models\Currency;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Validator;

class PayeeController extends Controller
{

    private $url = BASE_URL;
    private $token = MASTER_TOKEN;

    public function index(Request $request)
    {
        $check = $request['check'];
        $client = new Client();
        $headers = [
            'Authorization' => "Bearer " . $this->token
        ];
        $url = BASE_URL.'/payee';
        $response = $client->request('GET', $url, [
            'headers' => $headers,
        ]);
        $payees = json_decode($response->getBody(), true);
        return view('pages.payees.index', compact('payees', 'check'));
    }

    public function create(Request $request)
    {
        $payees = new Payee();
        $check = $request['check'];
        $client = new Client();
        $SwiftCodeUrl = BASE_URL.'/swift-codes';
        $countryUrl = BASE_URL.'/country';
        $headers = [
            'Authorization' => "Bearer " . $this->token
        ];
        $SwiftCodeResponse = $client->request('GET', $SwiftCodeUrl, [
            'headers' => $headers,
        ]);

        $CountryResponse = $client->request('GET', $countryUrl, [
            'headers' => $headers,
        ]);

        $swiftCodes = json_decode($SwiftCodeResponse->getBody()->getContents());

        $countries = json_decode($CountryResponse->getBody()->getContents());
        $currencyCodes = Currency::pluck('code', 'id')->all();

        return view('pages.payees.create', compact('payees', 'swiftCodes', 'currencyCodes', 'countries', 'check'));
    }

    public function store(Request $request)
    {
        $client = new Client();
        $headers = [
            'Authorization' => "Bearer " . $this->token,
        ];

        $url = BASE_URL.'/payee/add';
        $response = $client->request('POST', $url, [
            'headers' => $headers,
            'form_params' => $request->all(),
        ]);
        $inputData = json_decode($response->getBody()->getContents());
        if (isset($inputData->status) == 'ok') {
            return redirect()->route('payees.list')->with('success', $inputData->message);
        } else {
            return back()->withErrors($inputData)->withInput();
        }
    }

    public function edit(Request $request, $key)
    {
        $check = $request['check'];
        $payee_id = $key;
        $client = new Client();
        $SwiftCodeUrl = BASE_URL.'/swift-codes';
        $countryUrl = BASE_URL.'/country';
        $headers = [
            'Authorization' => "Bearer " . $this->token
        ];
        $url = BASE_URL.'/payee/edit/'.$payee_id;

        $response = $client->request('GET', $url, [
            'headers' => $headers,
        ]);

        $SwiftCodeResponse = $client->request('GET', $SwiftCodeUrl, [
            'headers' => $headers,
        ]);

        $CountryResponse = $client->request('GET', $countryUrl, [
            'headers' => $headers,
        ]);

        $payees = json_decode($response->getBody()->getContents());

        $swiftCodes = json_decode($SwiftCodeResponse->getBody()->getContents(), true);

        $countries = json_decode($CountryResponse->getBody()->getContents(), true);

        $currencyCodes = Currency::pluck('code', 'id')->all();

        return view('pages.payees.edit', compact('payees', 'countries', 'currencyCodes', 'swiftCodes', 'check'));
    }
}
