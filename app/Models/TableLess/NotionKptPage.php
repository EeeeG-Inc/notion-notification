<?php

namespace App\Models\TableLess;

use Illuminate\Database\Eloquent\Model;

class NotionKptPage extends Model
{
    public string $id;
    public string $kpt;
    public array $comments;
    public $lastEditedTime;
    public string $categoryId;
    public string $categoryName;
    public string $personId;
    public string $personName;

    public function __construct(
        string $id,
        string $kpt,
        $lastEditedTime,
        string $categoryId,
        string $categoryName,
        string $personId,
        string $personName
    ) {
        $this->id = $id;
        $this->kpt = $kpt;
        $this->lastEditedTime = $lastEditedTime;
        $this->categoryId = $categoryId;
        $this->categoryName = $categoryName;
        $this->personId = $personId;
        $this->personName = $personName;
        $this->comments = [];
    }

    public function setComments(array $comments)
    {
        $this->comments = $comments;
    }
}
