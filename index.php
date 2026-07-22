<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/view.php';
foreach (['auth', 'polls', 'public', 'settings', 'booking'] as $c) {
    require __DIR__ . "/app/controllers/$c.php";
}

$routes = [
    ['GET',  '/',                                  'home'],
    ['GET',  '/healthz',                           'healthz'],
    ['GET',  '/login',                             'login_form'],
    ['POST', '/login',                             'login_submit'],
    ['POST', '/logout',                            'logout_do'],
    ['GET',  '/forgot',                            'forgot_form'],
    ['POST', '/forgot',                            'forgot_submit'],
    ['GET',  '/reset',                             'reset_form'],
    ['POST', '/reset',                             'reset_submit'],
    ['GET',  '/dashboard',                         'dashboard'],
    ['GET',  '/profile',                           'profile_form'],
    ['POST', '/profile',                           'profile_save'],
    ['GET',  '/polls/new',                         'poll_new'],
    ['POST', '/polls/new',                         'poll_create'],
    ['GET',  '#^/polls/(\d+)$#',                   'poll_manage'],
    ['GET',  '#^/polls/(\d+)/edit$#',              'poll_edit'],
    ['GET',  '#^/polls/(\d+)/export\.csv$#',       'poll_export_csv'],
    ['POST', '#^/polls/(\d+)/edit$#',              'poll_update'],
    ['POST', '#^/polls/(\d+)/duplicate$#',         'poll_duplicate'],
    ['POST', '#^/polls/(\d+)/close$#',             'poll_close'],
    ['POST', '#^/polls/(\d+)/finalize$#',          'poll_finalize'],
    ['POST', '#^/polls/(\d+)/delete$#',            'poll_delete'],
    ['POST', '#^/polls/(\d+)/invite$#',            'poll_invite'],
    ['GET',  '/settings',                          'settings_form'],
    ['POST', '/settings',                          'settings_save'],
    ['GET',  '/errors',                            'errors_page'],
    ['POST', '/errors/clear',                      'errors_clear'],
    ['GET',  '/users',                             'users_list'],
    ['POST', '/users',                             'users_create'],
    ['POST', '#^/users/(\d+)/toggle$#',            'users_toggle'],
    ['POST', '#^/users/(\d+)/delete$#',            'users_delete'],
    ['GET',  '/logo',                              'public_logo'],
    ['GET',  '#^/p/([A-Za-z0-9_-]+)/ics$#',        'public_ics'],
    ['POST', '#^/p/([A-Za-z0-9_-]+)$#',            'public_respond'],
    ['GET',  '#^/p/([A-Za-z0-9_-]+)$#',            'public_poll'],
    ['GET',  '/booking',                           'booking_index'],
    ['GET',  '/booking/new',                       'booking_new'],
    ['POST', '/booking/new',                       'booking_create'],
    ['POST', '/booking/daysoff',                   'daysoff_add'],
    ['POST', '#^/booking/daysoff/(\d+)/delete$#',  'daysoff_remove'],
    ['GET',  '#^/booking/(\d+)/edit$#',            'booking_edit'],
    ['POST', '#^/booking/(\d+)/edit$#',            'booking_update'],
    ['POST', '#^/booking/(\d+)/windows$#',         'booking_save_week'],
    ['GET',  '#^/booking/(\d+)/calendar$#',        'booking_month'],
    ['GET',  '#^/booking/(\d+)/copy-week$#',       'booking_copy_week_confirm'],
    ['POST', '#^/booking/(\d+)/copy-week$#',       'booking_copy_week_do'],
    ['POST', '#^/booking/(\d+)/blocks$#',          'booking_block_add'],
    ['POST', '#^/booking/(\d+)/blocks/(\d+)/delete$#', 'booking_block_remove'],
    ['POST', '#^/booking/(\d+)/pause$#',           'booking_pause'],
    ['POST', '#^/booking/(\d+)/delete$#',          'booking_delete'],
    ['POST', '#^/booking/bookings/(\d+)/cancel$#', 'booking_org_cancel'],
    ['GET',  '#^/b/([A-Za-z0-9_-]+)$#',            'booking_public'],
    ['POST', '#^/b/([A-Za-z0-9_-]+)$#',            'booking_submit'],
    ['GET',  '#^/m/([A-Za-z0-9_-]+)/ics$#',        'booking_ics'],
    ['GET',  '#^/m/([A-Za-z0-9_-]+)/cancel$#',     'booking_cancel_confirm'],
    ['POST', '#^/m/([A-Za-z0-9_-]+)/cancel$#',     'booking_cancel_do'],
    ['GET',  '#^/m/([A-Za-z0-9_-]+)$#',            'booking_manage'],
];

$method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' ? 'GET' : ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$route = current_route();

foreach ($routes as [$m, $pat, $fn]) {
    if ($m !== $method) continue;
    if ($pat[0] === '#') {
        if (preg_match($pat, $route, $mm)) { $fn(...array_slice($mm, 1)); return; }
    } elseif ($pat === $route) {
        $fn();
        return;
    }
}

http_response_code(404);
view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'That page could not be found.'], 'public');
