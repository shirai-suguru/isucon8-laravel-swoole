<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', 'IndexController@index');
Route::get('/initialize', 'IndexController@initialize');
Route::post('/api/actions/login', 'IndexController@login');
Route::post('/api/actions/logout', 'IndexController@logout');
Route::post('/api/users', 'IndexController@apiUsers');
Route::get('/api/users/{id}', 'IndexController@apiUsersById');
Route::get('/api/events', 'IndexController@apiGetEvents');
Route::get('/api/events/{id}', 'IndexController@apiGetEventsById');
Route::post('/api/events/{id}/actions/reserve', 'IndexController@apiEventsReserveById');
Route::delete('/api/events/{id}/sheets/{ranks}/{num}/reservation', 'IndexController@deletEventByIdRankNum');
Route::get('/admin', 'IndexController@admin');
Route::post('/admin/api/actions/login', 'IndexController@adminLogin');
Route::post('/admin/api/actions/logout', 'IndexController@adminLogout');
Route::get('/admin/api/events', 'IndexController@adminGetEvents');
Route::post('/admin/api/events', 'IndexController@adminCreateEvents');
Route::get('/admin/api/events/{id}', 'IndexController@adminGetEventsById');
Route::post('/admin/api/events/{id}/actions/edit', 'IndexController@adminEditEventsById');
Route::get('/admin/api/reports/events/{id}/sales', 'IndexController@adminGetSalesById');
Route::get('/admin/api/reports/sales', 'IndexController@adminGetSales');
