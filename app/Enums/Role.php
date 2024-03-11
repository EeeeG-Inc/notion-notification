<?php
namespace App\Enums;

enum Role: int
{
    case ADMIN = 1;
    case MANAGER = 2;
    case USER = 3;
}
