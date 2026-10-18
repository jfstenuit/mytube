<?php
namespace App\Controllers;

use App\Auth\Auth;
use App\Core\App;

class AuthController
{
    public function __construct(private App $app)
    {
    }

    public function login(): void
    {
        $app = $this->app;
        $request = $app->request;
        $returnTo = $app->safeReturnPath($request->isPost() ? $request->input('return') : $request->query('return'));

        if ($app->auth->user()) {
            $app->redirect($returnTo);
            return;
        }

        $identifier = '';
        $error = null;
        $status = 200;
        if ($request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            $identifier = trim($request->input('identifier', ''));
            $result = $app->auth->attempt($identifier, $request->input('password', ''), $request->input('remember') === '1');
            if ($result === Auth::OK) {
                $app->redirect($returnTo);
                return;
            }
            [$status, $error] = match ($result) {
                Auth::THROTTLED => [429, 'Trop de tentatives de connexion. Veuillez réessayer dans quelques minutes.'],
                Auth::INACTIVE => [403, 'Ce compte est désactivé.'],
                Auth::UNVERIFIED => [403, 'Votre adresse e-mail n\'a pas encore été vérifiée. Consultez le message de confirmation que vous avez reçu.'],
                default => [401, 'Identifiant ou mot de passe incorrect.'],
            };
        }

        $app->render('login.twig', [
            'return_to' => $returnTo,
            'identifier' => $identifier,
            'error' => $error,
        ], $status);
    }

    public function logout(): void
    {
        $app = $this->app;
        // Without a session there is nothing to log out (and no CSRF token to check)
        if ($app->auth->user() && !$app->checkCsrf()) {
            return;
        }
        $app->auth->logout();
        $app->redirect('');
    }
}
