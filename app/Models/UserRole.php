<?php

namespace App\Models;

enum UserRole: string
{
    case Owner = 'owner';
    case Kasir = 'kasir';
}
