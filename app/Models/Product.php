<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    public $timestamps = false; // disable timestamps
    protected $fillable = ['name', 'price', 'description', 'discount', 'images']; // allow mass assignment
    protected $casts = [
        'images' => 'array',
    ];

    const SORTS = [
        'name' => 'Name',
        'name_desc' => 'Name (Z-A)',
        'price' => 'Price',
        'price_desc' => 'Price (High to Low)',
    ];

    const DISCOUNT_FILTERS = [
        'all' => 'All',
        'discount' => 'Discounted',
        'no_discount' => 'Not Discounted',
    ];

    const RESULTS_PER_PAGE = [
        15 => 15,
        30 => 30,
        50 => 50,
        'all' => 'All',
    ];
    

    public function invoices()
    {
        return $this->hasMany(ProductInvoice::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}