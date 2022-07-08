<?php

// AUTH
$router->get('/api/auth', 'Api\Services\amoAuthController@auth');

// API
$router->get('/lead/{id}', 'LeadController@get');
$router->post('/mortgage/create', [
    'middleware'  =>  'amoAuth',
    'uses'        =>  'LeadController@createMortgage',
]);


// WEBHOOKS
$router->post('/lead/delete', 'LeadController@deleteLeadWithRelated');
$router->post('/lead/changestage', 'LeadController@changeStage');

// CRONS
$router->get('/changestage', [
    'middleware'  =>  'amoAuth',
    'uses'        =>  'LeadController@cronChangeStage',
]);
