<?php

$router->get('/api/auth', 'Api\Services\amoAuthController@auth');

$router->get('/lead/{id}', 'LeadController@get');
$router->post('/mortgage/create', [
    'middleware'  =>  'amoAuth',
    'uses'        =>  'LeadController@createMortgage',
]);


// Webhooks

$router->post('/lead/delete', 'LeadController@deleteLeadWithRelated');
$router->post('/lead/changestage', 'LeadController@changeStage');

// Crons

$router->get('/changestage', [
    'middleware'  =>  'amoAuth',
    'uses'        =>  'LeadController@cronChangeStage',
]);
