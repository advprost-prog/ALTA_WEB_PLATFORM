<?php

use Illuminate\Support\Facades\Route;

Route::get('/addons/demo/hello-module', fn (): string => 'Demo hello module')->name('addons.demo.hello-module');
