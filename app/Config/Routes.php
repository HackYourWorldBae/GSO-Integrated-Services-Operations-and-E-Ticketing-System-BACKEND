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

    // Authentication (Rate limited: max 10 login/register attempts per minute per IP to prevent abuse)
    $routes->post('auth/login',    'AuthController::login',    ['filter' => 'throttle:10,60']);
    $routes->post('auth/register', 'AuthController::register', ['filter' => 'throttle:10,60']);

    // Password Recovery via Email Link (Rate limited to prevent abuse)
    $routes->post('auth/forgot-password',    'AuthController::forgotPassword',   ['filter' => 'throttle:5,60']);
    $routes->post('auth/verify-reset-token', 'AuthController::verifyResetToken', ['filter' => 'throttle:15,60']);
    $routes->post('auth/reset-password',     'AuthController::resetPassword',    ['filter' => 'throttle:5,60']);

    // Public Scheduled Projects Announcements (FGMU & LEAU)
    $routes->get('projects',          'TicketController::getProjects', ['filter' => 'throttle:60,60']);
    $routes->get('projects/archives', 'TicketController::getProjectArchives', ['filter' => 'throttle:60,60']);

    // Public Avatar & Institutional ID Card / Selfie Stream
    $routes->get('auth/avatar/(:segment)',    'AuthController::getAvatar/$1');
    $routes->get('auth/id-card/(:segment)',   'AuthController::getIdCard/$1');
    $routes->get('auth/id-selfie/(:segment)', 'AuthController::getIdSelfie/$1');

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
        $routes->get('tickets/(:segment)',        'TicketQueueController::show/$1');
        $routes->get('tickets/(:segment)/logs',   'TicketQueueController::logs/$1');

        // -- Ticket Attachments & Verification --
        $routes->post('tickets/(:segment)/attachments',     'TicketAttachmentController::uploadAttachment/$1');
        $routes->get('attachments/(:num)',                  'TicketAttachmentController::downloadAttachment/$1');
        $routes->post('tickets/(:segment)/accomplishment',  'TicketAttachmentController::uploadAccomplishment/$1', ['filter' => 'role:admin,worker']);
        $routes->get('tickets/(:segment)/accomplishment',   'TicketAttachmentController::downloadAccomplishment/$1');
        $routes->match(['post', 'patch'], 'tickets/(:segment)/verify-close', 'TicketActionController::verifyAndClose/$1', ['filter' => 'role:admin,director,user,superadmin']);
        $routes->patch('tickets/(:segment)/eodb',           'TicketActionController::updateEodb/$1',           ['filter' => 'role:admin']);

        // -- Ticket Queues (Per Unit — Admin, Director, Superadmin) --
        $routes->get('tickets/queue/(:segment)',          'TicketQueueController::pendingQueue/$1',   ['filter' => 'role:admin,director,superadmin']);
        $routes->get('tickets/delayed-approval/(:segment)','TicketQueueController::delayedApprovalQueue/$1', ['filter' => 'role:admin,director,superadmin']);
        $routes->get('tickets/dispatch/(:segment)',       'TicketQueueController::dispatchQueue/$1',  ['filter' => 'role:admin,director,superadmin']);
        $routes->get('tickets/active/(:segment)',         'TicketQueueController::activeTickets/$1',  ['filter' => 'role:admin,director,superadmin']);
        $routes->get('tickets/archives/(:segment)',       'TicketQueueController::archives/$1',       ['filter' => 'role:admin,director,superadmin']);
        $routes->get('tickets/stats/(:segment)',          'TicketQueueController::unitStats/$1',      ['filter' => 'role:admin,director,superadmin']);

        // -- Ticket Actions (Admin & Director Roles) --
        $routes->patch('tickets/(:segment)/approve',        'TicketActionController::approve/$1',               ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/delay-approval', 'TicketActionController::delayApproval/$1',        ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/resume-approval','TicketActionController::resumeApproval/$1',       ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/decline',        'TicketActionController::decline/$1',               ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/complete',       'TicketActionController::complete/$1',              ['filter' => 'role:admin,worker,director']);
        $routes->post('tickets/(:segment)/materials',       'TicketActionController::saveMaterials/$1',         ['filter' => 'role:admin,worker,director']);
        $routes->patch('tickets/(:segment)/extend',         'TicketActionController::extendTicket/$1',          ['filter' => 'role:admin,director']);

        // -- SSU Incident Report Workflow (Admin & Director Roles) --
        $routes->get('tickets/investigating/(:segment)',    'TicketQueueController::investigatingQueue/$1',    ['filter' => 'role:admin,director,superadmin']);
        $routes->patch('tickets/(:segment)/investigate',   'TicketActionController::setUnderInvestigation/$1', ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/uninvestigate', 'TicketActionController::unsetUnderInvestigation/$1', ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/notation',      'TicketActionController::addNotation/$1',           ['filter' => 'role:admin,director']);
        $routes->patch('tickets/(:segment)/resolve',       'TicketActionController::resolveIncident/$1',       ['filter' => 'role:admin,director']);

        // -- Scheduled Projects Management (FGMU & LEAU Admins) --
        $routes->post('projects',              'TicketController::createProject',   ['filter' => 'role:admin']);
        $routes->patch('projects/(:segment)',  'TicketController::updateProject/$1',['filter' => 'role:admin']);

        // -- Dispatch (Admin/Unit Head only) --
        $routes->post('dispatch/assign',                         'DispatchController::assign',                  ['filter' => 'role:admin']);
        $routes->post('dispatch/start',                          'DispatchController::startJob',                ['filter' => 'role:admin,worker']);
        $routes->patch('dispatch/assignments/(:num)',             'DispatchController::updateAssignment/$1',     ['filter' => 'role:admin']);
        $routes->post('dispatch/assignments/(:num)/materials',   'DispatchController::addMaterials/$1',         ['filter' => 'role:admin']);

        // -- Cross-Unit Collaborations (Admin / Director) --
        $routes->post('tickets/(:segment)/collaborations',           'CollaborationController::requestCollaboration/$1', ['filter' => 'role:admin,director']);
        $routes->get('tickets/(:segment)/collaborations',            'CollaborationController::getTicketCollaborations/$1');
        $routes->patch('collaborations/(:num)/respond',              'CollaborationController::respond/$1',              ['filter' => 'role:admin,director']);
        $routes->post('collaborations/(:num)/assign-personnel',      'CollaborationController::assignPersonnel/$1',      ['filter' => 'role:admin,director']);
        $routes->patch('collaborations/(:num)/complete',             'CollaborationController::complete/$1',             ['filter' => 'role:admin,director']);
        $routes->get('collaborations/my-unit',                       'CollaborationController::myUnitCollaborations',    ['filter' => 'role:admin,director']);

        // -- Personnel Categories (Admin) --
        // NOTE: These must be declared BEFORE /personnel/(:segment) to avoid route collision
        $routes->get('personnel/categories/(:segment)',    'PersonnelController::categories/$1',      ['filter' => 'role:admin,director']);
        $routes->post('personnel/categories',             'PersonnelController::createCategory',     ['filter' => 'role:admin']);
        $routes->patch('personnel/categories/(:num)',     'PersonnelController::updateCategory/$1',  ['filter' => 'role:admin']);
        $routes->delete('personnel/categories/(:num)',    'PersonnelController::deleteCategory/$1',  ['filter' => 'role:admin']);

        // -- Personnel (Admin) --
        $routes->get('personnel/(:segment)',             'PersonnelController::byUnit/$1',      ['filter' => 'role:admin,director']);
        $routes->get('personnel/(:segment)/available',  'PersonnelController::available/$1',   ['filter' => 'role:admin']);
        $routes->patch('personnel/(:segment)/status',   'PersonnelController::updateStatus/$1',['filter' => 'role:admin']);
        $routes->post('personnel',                       'PersonnelController::create',         ['filter' => 'role:admin']);
        $routes->put('personnel/(:segment)',             'PersonnelController::update/$1',      ['filter' => 'role:admin']);
        $routes->delete('personnel/(:segment)',          'PersonnelController::delete/$1',      ['filter' => 'role:admin']);

        // -- Feedback (Requestors: student / employee) --
        $routes->post('feedback',              'FeedbackController::submit',      ['filter' => 'role:student,employee']);
        $routes->get('feedback/(:segment)',    'FeedbackController::show/$1');

        // -- Director Analytics --
        $routes->get('director/analytics',           'DirectorController::analytics',        ['filter' => 'role:director,superadmin']);
        $routes->get('director/analytics/(:segment)','DirectorController::unitAnalytics/$1', ['filter' => 'role:director,superadmin']);

        // -- Superadmin (Master Administration, User Lifecycle & Verification) --
        $routes->get('superadmin/stats',                  'SuperadminController::stats',          ['filter' => 'role:superadmin']);
        $routes->get('superadmin/users',                  'SuperadminController::users',          ['filter' => 'role:superadmin']);
        $routes->post('superadmin/users',                 'SuperadminController::createUser',     ['filter' => 'role:superadmin']);
        $routes->get('superadmin/users/(:segment)',       'SuperadminController::showUser/$1',    ['filter' => 'role:superadmin']);
        $routes->put('superadmin/users/(:segment)',       'SuperadminController::updateUser/$1',  ['filter' => 'role:superadmin']);
        $routes->delete('superadmin/users/(:segment)',    'SuperadminController::deleteUser/$1',  ['filter' => 'role:superadmin']);
        $routes->match(['patch', 'post'], 'superadmin/users/(:segment)/verify', 'SuperadminController::verifyUser/$1', ['filter' => 'role:superadmin']);
        $routes->match(['patch', 'post'], 'superadmin/users/(:segment)/reject', 'SuperadminController::rejectVerification/$1', ['filter' => 'role:superadmin']);
        $routes->match(['patch', 'post', 'put'], 'superadmin/users/(:segment)/status', 'SuperadminController::updateStatus/$1', ['filter' => 'role:superadmin']);
        $routes->post('superadmin/users/(:segment)/unlock', 'SuperadminController::unlockUser/$1', ['filter' => 'role:superadmin']);
        $routes->get('superadmin/audit-logs',             'SuperadminController::auditLogs',      ['filter' => 'role:superadmin']);
        $routes->get('superadmin/account-activity-logs',  'SuperadminController::accountActivityLogs', ['filter' => 'role:superadmin']);

        // -- System Settings & Resend.com Email Integration (Superadmin) --
        $routes->get('settings/resend',        'SystemSettingController::getResendConfig',    ['filter' => 'role:superadmin']);
        $routes->post('settings/resend',       'SystemSettingController::updateResendConfig', ['filter' => 'role:superadmin']);
        $routes->post('settings/resend-key',   'SystemSettingController::updateResendConfig', ['filter' => 'role:superadmin']);
        $routes->post('settings/resend/test',  'SystemSettingController::testResendEmail',    ['filter' => 'role:superadmin']);

        // -- Notifications --
        $routes->get('notifications',             'NotificationController::index');
        $routes->post('notifications/read/(:num)','NotificationController::markAsRead/$1');
        $routes->post('notifications/read-all',   'NotificationController::markAllAsRead');
        $routes->delete('notifications/clear',    'NotificationController::clearRead');
    });
});

// Default welcome route (remove or redirect in production)
$routes->get('/', 'Home::index');