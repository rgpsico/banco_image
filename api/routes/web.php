<?php

use Illuminate\Support\Facades\Route;

// API pura: a interface é o app Vue (servido pelo Nginx). Esta rota existe só para o servidor responder algo em "/".
Route::view('/', 'welcome');
