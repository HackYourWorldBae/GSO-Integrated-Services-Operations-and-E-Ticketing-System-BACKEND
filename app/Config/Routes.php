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

    // Public Scheduled Projects Announcements (FGMU & LEAU)
    $routes->get('projects',          'TicketController::getProjects', ['filter' => 'throttle:60,60']);
    $routes->get('projects/archives', 'TicketController::getProjectArchives', ['filter' => 'throttle:60,60']);

    // Public Avatar Stream
    $routes->get('auth/avatar/(:segment)', 'AuthController::getAvatar/$1');

    // System Health & Connectivity Probe
    $routes->get('health', function() {
        return response()->setStatusCode(200)->setJSON([
            'status'    => 'ok',
            'timestamp' => time(),
            'service'   => 'GSO E-Ticketing System API'
        ]);
    });

    // --------------------------------------------------------------------------
    // 2. PROTECTED ROUTES (Require JWT & Rate Limiting)
    // --------------------------------------------------------------------------

    $routes->group('', ['filter' => ['jwt', 'throttle:120,60']], function ($routes) {

        // -- Auth --
        $routes->post('auth/logout',          'AuthController::logout');
        $routes->get('auth/me',               'AuthController::me');
        $routes->get('auth/check-session',    'AuthController::checkSession');
        $routes->patch('auth/profile',        'AuthController::updateProfile');
        $routes->post('auth/change-password', 'AuthController::changePassword');
        $routes->post('auth/avatar',          'AuthController::uploadAvatar');
        $routes->get('auth/avatar/(:segment)','AuthController::getAvatar/$1');

        // -- Ticket Intake (Users / Requestors) --
        $routes->post('tickets/intake',           'TicketController::submitIntake');
        $routes->get('tickets/my-requests',       'TicketController::myRequests');
        $routes->get('tickets/completed',         'TicketController::completedRequests');
        $routes->patch('tickets/(:segment)/cancel','TicketController::cancel/$1');
        $routes->get('tickets/(:segment)',        'TicketController::show/$1');
        $routes->get('tickets/(:segment)/logs',   'TicketController::logs/$1');
        
        // -- Ticket Attachments & Verification --
        $routes->post('tickets/(:segment)/attachments',     'TicketController::uploadAttachment/$1');
        $routes->get('attachments/(:num)',                  'TicketController::downloadAttachment/$1');
        $routes->post('tickets/(:segment)/accomplishment',  'TicketController::uploadAccomplishment/$1', ['filter' => 'role:admin,dispatcher,worker']);
        $routes->get('tickets/(:segment)/accomplishment',   'TicketController::downloadAccomplishment/$1');
        $routes->match(['post', 'patch'], 'tickets/(:segment)/verify-close', 'TicketController::verifyAndClose/$1', ['filter' => 'role:admin,dispatcher,director,user,superadmin']);
        $routes->patch('tickets/(:segment)/eodb',           'TicketController::updateEodb/$1',           ['filter' => 'role:admin,dispatcher']);

        // -- Ticket Queues (Per Unit — Admin, Dispatcher, Director, Superadmin) --
        $routes->get('tickets/queue/(:segment)',          'TicketController::pendingQueue/$1',   ['filter' => 'role:admin,dispatcher,director,superadmin']);
        $routes->get('tickets/dispatch/(:segment)',       'TicketController::dispatchQueue/$1',  ['filter' => 'role:admin,dispatcher,director,superadmin']);
        $routes->get('tickets/active/(:segment)',         'TicketController::activeTickets/$1',  ['filter' => 'role:admin,dispatcher,director,superadmin']);
        $routes->get('tickets/archives/(:segment)',       'TicketController::archives/$1',       ['filter' => 'role:admin,dispatcher,director,superadmin']);
        $routes->get('tickets/stats/(:segment)',          'TicketController::unitStats/$1',      ['filter' => 'role:admin,dispatcher,director,superadmin']);

        // -- Ticket Actions (Admin & Director Roles) --
        $routes->patch('tickets/(:segment)/approve',        'TicketController::approve/$1',               ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/decline',        'TicketController::decline/$1',               ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/complete',       'TicketController::complete/$1',              ['filter' => 'role:admin,dispatcher,worker,director']);
        $routes->patch('tickets/(:segment)/extend',         'TicketController::extendTicket/$1',          ['filter' => 'role:admin,dispatcher,director']);

        // -- SSU Incident Report Workflow (Admin & Director Roles) --
        $routes->get('tickets/investigating/(:segment)',    'TicketController::investigatingQueue/$1',    ['filter' => 'role:admin,dispatcher,director,superadmin']);
        $routes->patch('tickets/(:segment)/investigate',   'TicketController::setUnderInvestigation/$1', ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/uninvestigate', 'TicketController::unsetUnderInvestigation/$1', ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/notation',      'TicketController::addNotation/$1',           ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/resolve',       'TicketController::resolveIncident/$1',       ['filter' => 'role:admin,director']);

        // -- Scheduled Projects Management (FGMU & LEAU Admins) --
        $routes->post('projects',              'TicketController::createProject',   ['filter' => 'role:admin']);
        $routes->patch('projects/(:segment)',  'TicketController::updateProject/$1',['filter' => 'role:admin']);

        // -- Dispatch (Admin & Dispatcher — Unit Head inherits dispatcher capabilities) --
        $routes->post('dispatch/assign',                         'DispatchController::assign',                  ['filter' => 'role:admin,dispatcher']);
        $routes->post('dispatch/start',                          'DispatchController::startJob',                ['filter' => 'role:admin,dispatcher,worker']);
        $routes->patch('dispatch/assignments/(:num)',             'DispatchController::updateAssignment/$1',     ['filter' => 'role:admin,dispatcher']);
        $routes->post('dispatch/assignments/(:num)/materials',   'DispatchController::addMaterials/$1',         ['filter' => 'role:admin,dispatcher']);

        // -- Personnel Categories (Admin) --
        // NOTE: These must be declared BEFORE /personnel/(:segment) to avoid route collision
        $routes->get('personnel/categories/(:segment)',    'PersonnelController::categories/$1',      ['filter' => 'role:admin,dispatcher,director']);
        $routes->post('personnel/categories',             'PersonnelController::createCategory',     ['filter' => 'role:admin']);
        $routes->patch('personnel/categories/(:num)',     'PersonnelController::updateCategory/$1',  ['filter' => 'role:admin']);
        $routes->delete('personnel/categories/(:num)',    'PersonnelController::deleteCategory/$1',  ['filter' => 'role:admin']);

        // -- Personnel (Admin & Dispatcher) --
        $routes->get('personnel/(:segment)',             'PersonnelController::byUnit/$1',      ['filter' => 'role:admin,dispatcher,director']);
        $routes->get('personnel/(:segment)/available',  'PersonnelController::available/$1',   ['filter' => 'role:admin,dispatcher']);
        $routes->patch('personnel/(:segment)/status',   'PersonnelController::updateStatus/$1',['filter' => 'role:admin,dispatcher']);
        $routes->post('personnel',                       'PersonnelController::create',         ['filter' => 'role:admin']);
        $routes->put('personnel/(:segment)',             'PersonnelController::update/$1',      ['filter' => 'role:admin']);
        $routes->delete('personnel/(:segment)',          'PersonnelController::delete/$1',      ['filter' => 'role:admin']);

        // -- Feedback (Requestors: student / employee) --
        $routes->post('feedback',              'FeedbackController::submit',      ['filter' => 'role:student,employee']);
        $routes->get('feedback/(:segment)',    'FeedbackController::show/$1');

        // -- Director Analytics --
        $routes->get('director/analytics',           'DirectorController::analytics',        ['filter' => 'role:director,superadmin']);
        $routes->get('director/analytics/(:segment)','DirectorController::unitAnalytics/$1', ['filter' => 'role:director,superadmin']);

        // -- Superadmin (Master Administration, User Lifecycle & RBAC Matrix) --
        $routes->get('superadmin/stats',                  'SuperadminController::stats',          ['filter' => 'role:superadmin']);
        $routes->get('superadmin/users',                  'SuperadminController::users',          ['filter' => 'role:superadmin']);
        $routes->post('superadmin/users',                 'SuperadminController::createUser',     ['filter' => 'role:superadmin']);
        $routes->get('superadmin/users/(:segment)',       'SuperadminController::showUser/$1',    ['filter' => 'role:superadmin']);
        $routes->put('superadmin/users/(:segment)',       'SuperadminController::updateUser/$1',  ['filter' => 'role:superadmin']);
        $routes->delete('superadmin/users/(:segment)',    'SuperadminController::deleteUser/$1',  ['filter' => 'role:superadmin']);
        $routes->get('superadmin/audit-logs',             'SuperadminController::auditLogs',      ['filter' => 'role:superadmin']);
        $routes->get('superadmin/rbac-matrix',            'SuperadminController::getRbacMatrix',  ['filter' => 'role:superadmin']);
        $routes->post('superadmin/rbac-matrix',           'SuperadminController::updateRbacMatrix',['filter' => 'role:superadmin']);

        // -- Notifications --
        $routes->get('notifications',             'NotificationController::index');
        $routes->post('notifications/read/(:num)','NotificationController::markAsRead/$1');
        $routes->post('notifications/read-all',   'NotificationController::markAllAsRead');
        $routes->delete('notifications/clear',    'NotificationController::clearRead');
    });
});

// Default welcome route (remove or redirect in production)
$routes->get('/', 'Home::index');