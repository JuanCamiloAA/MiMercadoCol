<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // La autorizacion de ruta va en el middleware `permission:`; esto habilita ademas las
    // politicas, para lo que depende del recurso y no solo del permiso.
    use AuthorizesRequests;
}
