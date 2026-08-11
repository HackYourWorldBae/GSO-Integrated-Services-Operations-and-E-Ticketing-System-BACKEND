<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ============================================================================
// API v1 Routes — GSO Integrated Services Operations & E-Ticketing System
// ============================================================================

$routes->group('api/v1', ['namespace' => 'App\Controllers\API'], function ($routes) {

    // --------------------------------------------------------------------------
    // 1. PUBLIC ROUTES (No Auth Required)
    // --------------------------------------------------------------------------

    // Authentication (Rate limited: max 10 login attempts per minute per IP to prevent brute force)
    $routes->post('auth/login',  'AuthController::login', ['filter' => 'throttle:10,60']);

    // Public vehicle availability (requestor can browse before login — rate limited to 60/min)
    $routes->get('tasu/vehicles/available', 'TasuController::available', ['filter' => 'throttle:60,60']);

    // --------------------------------------------------------------------------
    // 2. PROTECTED ROUTES (Require JWT & Rate Limiting)
    // --------------------------------------------------------------------------

    $routes->group('', ['filter' => ['jwt', 'throttle:120,60']], function ($routes) {

        // -- Auth --
        $routes->post('auth/logout',          'AuthController::logout');
        $routes->get('auth/me',               'AuthController::me');
        $routes->patch('auth/profile',        'AuthController::updateProfile');
        $routes->post('auth/change-password', 'AuthController::changePassword');

        // -- Ticket Intake (Users / Requestors) --
        $routes->post('tickets/intake',           'TicketController::submitIntake');
        $routes->get('tickets/my-requests',       'TicketController::myRequests');
        $routes->get('tickets/completed',         'TicketController::completedRequests');
        $routes->get('tickets/(:segment)',        'TicketController::show/$1');
        $routes->get('tickets/(:segment)/logs',   'TicketController::logs/$1');
        
        // -- Ticket Attachments --
        $routes->post('tickets/(:segment)/attachments', 'TicketController::uploadAttachment/$1');
        $routes->get('attachments/(:num)',              'TicketController::downloadAttachment/$1');

        // -- Ticket Queues (Per Unit — Admin & Dispatcher) --
        $routes->get('tickets/queue/(:segment)',          'TicketController::pendingQueue/$1',   ['filter' => 'role:admin,dispatcher']);
        $routes->get('tickets/dispatch/(:segment)',       'TicketController::dispatchQueue/$1',  ['filter' => 'role:admin,dispatcher']);
        $routes->get('tickets/active/(:segment)',         'TicketController::activeTickets/$1',  ['filter' => 'role:admin,dispatcher']);
        $routes->get('tickets/archives/(:segment)',       'TicketController::archives/$1',       ['filter' => 'role:admin,dispatcher,director']);
        $routes->get('tickets/stats/(:segment)',          'TicketController::unitStats/$1',      ['filter' => 'role:admin,dispatcher,director']);

        // -- Ticket Actions (Admin Role) --
        $routes->patch('tickets/(:segment)/approve',        'TicketController::approve/$1',               ['filter' => 'role:admin']);
        $routes->patch('tickets/(:segment)/decline',        'TicketController::decline/$1',               ['filter' => 'role:admin']);
        $routes->patch('tickets/(:segment)/complete',       'TicketController::complete/$1',              ['filter' => 'role:admin,dispatcher']);

        // -- SSU Incident Report Workflow (Admin Role) --
        $routes->get('tickets/investigating/(:segment)',    'TicketController::investigatingQueue/$1',    ['filter' => 'role:admin,dispatcher']);
        $routes->patch('tickets/(:segment)/investigate',   'TicketController::setUnderInvestigation/$1', ['filter' => 'role:admin']);
        $routes->patch('tickets/(:segment)/uninvestigate', 'TicketController::unsetUnderInvestigation/$1', ['filter' => 'role:admin']);
        $routes->patch('tickets/(:segment)/notation',      'TicketController::addNotation/$1',           ['filter' => 'role:admin']);
        $routes->patch('tickets/(:segment)/resolve',       'TicketController::resolveIncident/$1',       ['filter' => 'role:admin']);

        // -- Dispatch (Dispatchers) --
        $routes->post('dispatch/assign',                         'DispatchController::assign',                  ['filter' => 'role:admin,dispatcher']);
        $routes->post('dispatch/start',                          'DispatchController::startJob',                ['filter' => 'role:admin,dispatcher']);
        $routes->patch('dispatch/assignments/(:num)',             'DispatchController::updateAssignment/$1',     ['filter' => 'role:admin,dispatcher']);
        $routes->post('dispatch/assignments/(:num)/materials',   'DispatchController::addMaterials/$1',         ['filter' => 'role:admin,dispatcher']);

        // -- Personnel (Admin & Dispatcher) --
        $routes->get('personnel/(:segment)',             'PersonnelController::byUnit/$1',      ['filter' => 'role:admin,dispatcher,director']);
        $routes->get('personnel/(:segment)/available',  'PersonnelController::available/$1',   ['filter' => 'role:admin,dispatcher']);
        $routes->patch('personnel/(:segment)/status',   'PersonnelController::updateStatus/$1',['filter' => 'role:admin,dispatcher']);
        $routes->post('personnel',                       'PersonnelController::create',         ['filter' => 'role:admin']);
        $routes->put('personnel/(:segment)',             'PersonnelController::update/$1',      ['filter' => 'role:admin']);
        $routes->delete('personnel/(:segment)',          'PersonnelController::delete/$1',      ['filter' => 'role:admin']);

        // -- TASU Fleet Management (Admin) --
        $routes->get('tasu/vehicles',              'TasuController::fleet',            ['filter' => 'role:admin,dispatcher,director']);
        $routes->post('tasu/vehicles',             'TasuController::create',           ['filter' => 'role:admin']);
        $routes->put('tasu/vehicles/(:num)',        'TasuController::update/$1',        ['filter' => 'role:admin']);
        $routes->delete('tasu/vehicles/(:num)',     'TasuController::delete/$1',        ['filter' => 'role:admin']);
        $routes->patch('tasu/vehicles/(:num)/status', 'TasuController::updateStatus/$1', ['filter' => 'role:admin,dispatcher']);
        $routes->get('tasu/dispatch',              'TasuController::dispatchBoard',    ['filter' => 'role:admin,dispatcher,director']);

        // -- Feedback (Requestors: student / employee) --
        $routes->post('feedback',              'FeedbackController::submit',      ['filter' => 'role:student,employee']);
        $routes->get('feedback/(:segment)',    'FeedbackController::show/$1');

        // -- Director Analytics --
        $routes->get('director/analytics',           'DirectorController::analytics',        ['filter' => 'role:director']);
        $routes->get('director/analytics/(:segment)','DirectorController::unitAnalytics/$1', ['filter' => 'role:director']);

        // -- Notifications --
        $routes->get('notifications',             'NotificationController::index');
        $routes->post('notifications/read/(:num)','NotificationController::markAsRead/$1');
        $routes->post('notifications/read-all',   'NotificationController::markAllAsRead');
        $routes->delete('notifications/clear',    'NotificationController::clearRead');
    });
});

// Default welcome route (remove or redirect in production)
$routes->get('/', 'Home::index');