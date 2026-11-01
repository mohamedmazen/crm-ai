<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use HasFactory;

    public const TYPES = [
        'text' => 'Single Line Text',
        'email' => 'Email Address',
        'phone' => 'Phone Number',
        'textarea' => 'Multi-line Paragraph',
        'select' => 'Dropdown Select',
        'checkbox' => 'Checkbox',
        'number' => 'Number',
        'date' => 'Date',
        'hidden' => 'Hidden Field',
    ];

    public const MAPS_TO_FIELDS = [
        'name' => 'Lead Name',
        'phone' => 'Phone Number',
        'email' => 'Email Address',
        'website' => 'Website / URL',
        'address' => 'Full Address',
        'deal_value' => 'Deal Value',
    ];

    protected $fillable = [
        'form_id',
        'type',
        'label',
        'field_key',
        'placeholder',
        'help_text',
        'required',
        'options',
        'maps_to',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
