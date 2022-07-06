<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class changeStage extends Model
{
	use HasFactory;

	protected $table = 'change_stage';
	protected $fillable = [
		'lead_id',
		'lead',
	];

	public function deleteLead ( $id )
	{
		return $this->where( 'lead_id', ( int ) $id )->delete();
	}
}
