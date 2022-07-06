<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
	use HasFactory;

	protected $table;

	public function __construct ()
	{
		$this->table = 'amo_account';
	}

	public function login ( $accountData )
	{
		self::truncate();

		$this->client_id     = $accountData[ 'client_id' ];
		$this->client_secret = $accountData[ 'client_secret' ];
		$this->subdomain     = $accountData[ 'subdomain' ];
		$this->access_token  = $accountData[ 'access_token' ];
		$this->redirect_uri  = $accountData[ 'redirect_uri' ];
		$this->token_type    = $accountData[ 'token_type' ];
		$this->refresh_token = $accountData[ 'refresh_token' ];
		$this->when_expires  = $accountData[ 'when_expires' ];

		$this->save();
	}

	public function getAuthData ()
	{
		$authData = self::all()->first();

		if ( !$authData ) return false;

		return [
			'client_id'     => $authData->client_id,
			'client_secret' => $authData->client_secret,
			'subdomain'     => $authData->subdomain,
			'access_token'  => $authData->access_token,
			'redirect_uri'  => $authData->redirect_uri,
			'token_type'    => $authData->token_type,
			'refresh_token' => $authData->refresh_token,
			'when_expires'  => $authData->when_expires
		];
	}
}
