<?php
namespace App\Models;

class Order extends AppModel
{
     protected $guarded = ['same_billing_shipping', 'sameForBilling'];

     /** Dates */
     protected $dates = ['order_date'];
    
    /**]
     * order and order detail relation
     */
    public function orderDetail()
    {
        return $this->hasMany('App\Models\OrderDetail');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function affiliateCommissionStatus()
    {
        return $this->hasOne(AffiliateCommissionStatus::class);
    }
    
    // Country Relation
    public function country() {
        return $this->belongsTo('App\Models\Country', 'billing_country');
    }


    public function getIdAttribute($value)
    {
        return  $value;    
    }
}
