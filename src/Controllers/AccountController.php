<?php
namespace App\Controllers;

use App\Core\App;

class AccountController
{
    public function __construct(private App $app)
    {
    }

    public function account(): void
    {
        $app = $this->app;
        if (!$app->auth->user()) {
            $app->redirect('login?return=' . urlencode($app->request->uri()));
            return;
        }

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            // Profile and password changes: phase 5 (docs/auth-plan.md)
            $app->session->flash('info', 'La modification du profil et du mot de passe sera bientôt disponible.');
            $app->redirect('account');
            return;
        }

        $app->render('account.twig');
    }
}
