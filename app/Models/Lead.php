<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
	use HasFactory;

	protected $table = 'leads';
	protected $fillable = [
		'id_target_lead',
		'related_lead'
	];


	public function get ( $id )
  {
    return $this->where( 'id_target_lead', $id )->first();
  }

  public function add () {}

  public function deleteWithRelated ( $id )
	{
		$lead = $this->where( 'id_target_lead', $id )->first();

		if ( $lead )
		{
			$id_target_lead	= $lead->id_target_lead;
			$related_lead		= $lead->related_lead;

			return $this->where( 'id_target_lead', $id_target_lead )->delete() && $this->where( 'id_target_lead', $related_lead )->delete();
		}
		else
		{
			return false;
		}
	}

  public function aktualisieren () {}
}
