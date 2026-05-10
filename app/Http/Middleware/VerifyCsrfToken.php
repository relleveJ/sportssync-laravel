<?php
namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'admin/tabletennis/save_set',
        'admin/tabletennis/declare_winner',
        'Badminton Admin UI/update_match.php',
        'Basketball Admin UI/update_match.php',
        'DARTS ADMIN UI/update_match.php',
        'TABLE TENNIS ADMIN UI/update_match.php',
        'Volleyball Admin UI/update_match.php',
        'Badminton%20Admin%20UI/update_match.php',
        'Basketball%20Admin%20UI/update_match.php',
        'DARTS%20ADMIN%20UI/update_match.php',
        'TABLE%20TENNIS%20ADMIN%20UI/update_match.php',
        'Volleyball%20Admin%20UI/update_match.php',
    ];
}
