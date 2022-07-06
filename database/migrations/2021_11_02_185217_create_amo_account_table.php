<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAmoAccountTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('amo_account', function (Blueprint $table) {
            $table->id();
            $table->string( 'client_id' );
            $table->string( 'client_secret' );
            $table->string( 'subdomain' );
            $table->text( 'access_token' );
            $table->string( 'redirect_uri' );
            $table->string( 'token_type' );
            $table->text( 'refresh_token' );
            $table->bigInteger( 'when_expires' );
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('amo_account');
    }
}
