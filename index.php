<?php
require __DIR__ . '/vendor/autoload.php';

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\PasswordResetController;
use App\Controllers\RegistrationController;
use App\Controllers\VideoController;
use App\Core\App;
use App\Core\Config;
use App\Core\Router;

Config::load(__DIR__);

$app = new App(__DIR__);

(new Router())
    ->get('', [VideoController::class, 'home'])
    ->get('watch', [VideoController::class, 'watch'])
    ->get('embed', [VideoController::class, 'embed'])
    ->get('oembed', [VideoController::class, 'oembed'])
    ->add(['GET', 'POST'], 'login', [AuthController::class, 'login'])
    ->post('logout', [AuthController::class, 'logout'])
    ->add(['GET', 'POST'], 'register', [RegistrationController::class, 'register'])
    ->get('verify-email', [RegistrationController::class, 'verifyEmail'])
    ->add(['GET', 'POST'], 'resend-verification', [RegistrationController::class, 'resendVerification'])
    ->add(['GET', 'POST'], 'forgot-password', [PasswordResetController::class, 'forgot'])
    ->add(['GET', 'POST'], 'reset-password', [PasswordResetController::class, 'reset'])
    ->add(['GET', 'POST'], 'account', [AccountController::class, 'account'])
    ->get('admin', [AdminController::class, 'index'])
    ->get('admin/videos', [AdminController::class, 'videos'])
    ->get('admin/media', [AdminController::class, 'media'])
    ->add(['GET', 'POST'], 'admin/videos/new', [AdminController::class, 'create'])
    ->add(['GET', 'POST'], 'admin/videos/edit', [AdminController::class, 'edit'])
    ->post('admin/videos/delete', [AdminController::class, 'delete'])
    ->dispatch($app);
