<?php

namespace App\Models\TableLess;

use Illuminate\Database\Eloquent\Model;

class NotionPage extends Model
{
    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }


}
