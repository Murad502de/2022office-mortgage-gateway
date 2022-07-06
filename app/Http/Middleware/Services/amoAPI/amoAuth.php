<?php

namespace App\Http\Middleware\Services\amoAPI;

use Closure;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\amoAPI\amoHttp\amoClient;

class amoAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle( Request $request, Closure $next )
    {
        $client = new amoClient();
        $account = new Account();

        $authData = $account->getAuthData();

        if ( $authData )
        {
            if ( time() >= ( int )$authData[ 'when_expires' ] )
            {
                $response = $client->accessTokenUpdate( $authData );

                if ( $response[ 'code' ] >= 200 && $response[ 'code' ] < 204 )
                {
                    $accountData = [
                        'client_id'     => $authData[ 'client_id' ],
                        'client_secret' => $authData[ 'client_secret' ],
                        'subdomain'     => $authData[ 'subdomain' ],
                        'access_token'  => $response[ 'body' ][ 'access_token' ],
                        'redirect_uri'  => $authData[ 'redirect_uri' ],
                        'token_type'    => $response[ 'body' ][ 'token_type' ],
                        'refresh_token' => $response[ 'body' ][ 'refresh_token' ],
                        'when_expires'  => time() + ( int )$response[ 'body' ]['expires_in'] - 400
                    ];

                    $account->login( $accountData );

                    Log::info(
                        __METHOD__,

                        [
                            'message'  => 'access token updated'
                        ]
                    );

                    return $next( $request );
                }
                else
                {
                    Log::error(
                        __METHOD__,

                        [
                            'message'  => 'Login error with code: ' . $response[ 'code' ]
                        ]
                    );

                    return response( [ 'Bad Request' ], 400 );
                }
            }
            else
            {
                return $next( $request );
            }
        }
        else
        {
            Log::warning(
                __METHOD__,

                [
                    'message'  => 'Login data not found'
                ]
            );

            return response( [ 'Login data not found' ], 404 );
        }
    }
}
