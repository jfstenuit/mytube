<?php
namespace App\Controllers;

use App\Models\Group;
use App\Models\User;
use App\Models\UserAccounts;
use PDOException;

/** Administration of user accounts and their group memberships */
class AdminUsersController extends AdminBaseController
{
    private const INVITATION_TTL = 7 * 86400;
    private const ADMIN_RESET_TTL = 86400;

    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->render('admin/users.twig', [
            'tab' => 'users',
            'users' => UserAccounts::all($this->app->db()),
            'self_id' => $this->admin()->id,
        ]);
    }

    /** New account: the user receives an invitation link to choose a password */
    public function create(): void
    {
        if (!$this->guard()) {
            return;
        }
        $app = $this->app;
        $db = $app->db();
        $form = ['username' => '', 'display_name' => '', 'email' => '', 'groups' => []];
        $errors = [];

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            [$form, $errors] = $this->readForm(null);
            if (!$errors) {
                try {
                    $userId = User::create($db, $form['username'], $form['email'], $form['display_name'], null);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    $errors[] = 'Ce nom d\'utilisateur ou cette adresse e-mail est déjà utilisé.';
                }
            }
            if (!$errors) {
                Group::setUserGroups($db, $userId, $form['groups']);
                $sent = $this->sendPasswordLink(UserAccounts::find($db, $userId));
                $app->session->flash('success', sprintf('Le compte « %s » a été créé.', $form['username']));
                $app->session->flash($sent ? 'info' : 'error', $sent
                    ? sprintf('Une invitation à choisir un mot de passe a été envoyée à %s.', $form['email'])
                    : 'L\'invitation n\'a pas pu être envoyée : utilisez « Envoyer un lien » plus tard.');
                $app->redirect('admin/users/edit?id=' . $userId);
                return;
            }
        }

        $this->render('admin/user-form.twig', [
            'tab' => 'users',
            'mode' => 'create',
            'form' => $form,
            'errors' => $errors,
            'groups' => Group::all($db),
        ], $errors ? 422 : 200);
    }

    public function edit(): void
    {
        if (!$this->guard()) {
            return;
        }
        $app = $this->app;
        $db = $app->db();
        $id = (int) ($app->request->isPost() ? $app->request->input('id', '0') : $app->request->query('id', '0'));
        $account = UserAccounts::find($db, $id);
        if ($account === null) {
            $app->notFound();
            return;
        }

        $form = [
            'username' => $account['username'],
            'display_name' => $account['display_name'],
            'email' => $account['email'],
            'is_active' => (bool) $account['is_active'],
            'email_verified' => (bool) $account['email_verified'],
            'groups' => Group::idsOfUser($db, $id),
        ];
        $errors = [];

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            [$form, $errors] = $this->readForm($account);
            if (!$errors) {
                UserAccounts::update($db, $id, $form);
                Group::setUserGroups($db, $id, $form['groups']);
                if (!$form['is_active']) {
                    // Sessions are refused at once (see Auth::user()); persistent logins are revoked
                    $app->tokens->deleteForUser($id, 'remember');
                }
                $app->session->flash('success', 'Les modifications ont été enregistrées.');
                $app->redirect('admin/users/edit?id=' . $id);
                return;
            }
        }

        $this->render('admin/user-form.twig', [
            'tab' => 'users',
            'mode' => 'edit',
            'account' => $account,
            'is_self' => $id === $this->admin()->id,
            'form' => $form,
            'errors' => $errors,
            'groups' => Group::all($db),
        ], $errors ? 422 : 200);
    }

    public function delete(): void
    {
        if (!$this->guard() || !$this->app->checkCsrf()) {
            return;
        }
        $app = $this->app;
        $db = $app->db();
        $account = UserAccounts::find($db, (int) $app->request->input('id', '0'));
        if ($account === null) {
            $app->redirect('admin/users');
            return;
        }
        if ((int) $account['id'] === $this->admin()->id) {
            $app->session->flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif ($this->isLastActiveAdmin((int) $account['id'])) {
            $app->session->flash('error', 'Impossible de supprimer le dernier administrateur actif.');
        } else {
            UserAccounts::delete($db, (int) $account['id']);
            $app->session->flash('success', sprintf('Le compte « %s » a été supprimé.', $account['username']));
            $app->redirect('admin/users');
            return;
        }
        $app->redirect('admin/users/edit?id=' . $account['id']);
    }

    /** Invitation (no password yet) or password reset link, sent to the user */
    public function sendLink(): void
    {
        if (!$this->guard() || !$this->app->checkCsrf()) {
            return;
        }
        $app = $this->app;
        $account = UserAccounts::find($app->db(), (int) $app->request->input('id', '0'));
        if ($account === null) {
            $app->redirect('admin/users');
            return;
        }
        if (!$account['is_active']) {
            $app->session->flash('error', 'Ce compte est désactivé : réactivez-le d\'abord.');
        } elseif ($this->sendPasswordLink($account)) {
            $app->session->flash('success', sprintf('Un lien %s a été envoyé à %s.',
                $account['has_password'] ? 'de réinitialisation du mot de passe' : 'd\'invitation', $account['email']));
        } else {
            $app->session->flash('error', 'Le message n\'a pas pu être envoyé.');
        }
        $app->redirect('admin/users/edit?id=' . $account['id']);
    }

    /**
     * Reads and validates the form; $account is null when creating.
     * @return array{0: array, 1: string[]} [form values, errors]
     */
    private function readForm(?array $account): array
    {
        $app = $this->app;
        $db = $app->db();
        $request = $app->request;
        $id = $account ? (int) $account['id'] : null;
        $form = [
            'username' => trim($request->input('username', '')),
            'display_name' => trim($request->input('display_name', '')),
            'email' => trim($request->input('email', '')),
            'is_active' => $request->input('is_active') === '1',
            'email_verified' => $request->input('email_verified') === '1',
            'groups' => array_values(array_intersect($this->postedIds('groups'), array_map('intval', array_column(Group::all($db), 'id')))),
        ];
        $errors = [];

        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $form['username'])) {
            $errors[] = 'Le nom d\'utilisateur doit contenir de 3 à 64 caractères : lettres, chiffres, « . », « _ » ou « - ».';
        } elseif (UserAccounts::usernameExists($db, $form['username'], $id)) {
            $errors[] = 'Ce nom d\'utilisateur est déjà utilisé.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 255) {
            $errors[] = 'L\'adresse e-mail n\'est pas valide.';
        } elseif (UserAccounts::emailExists($db, $form['email'], $id)) {
            $errors[] = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
        }
        if ($form['display_name'] === '') {
            $form['display_name'] = $form['username'];
        }
        if (mb_strlen($form['display_name']) > 255 || preg_match('/[\x00-\x1F\x7F]/', $form['display_name'])) {
            $errors[] = 'Le nom affiché n\'est pas valide.';
        }

        if ($account !== null) {
            $isSelf = $id === $this->admin()->id;
            $keepsAdmin = in_array(User::ADMIN_GROUP_ID, $form['groups'], true);
            // Inactive or unverified accounts can't log in: guard against locking admins out
            $canStillLogIn = $form['is_active'] && $form['email_verified'];
            if ($isSelf && !$canStillLogIn) {
                $errors[] = 'Vous ne pouvez pas désactiver votre propre compte ni marquer votre adresse comme non vérifiée.';
            }
            if ($isSelf && !$keepsAdmin) {
                $errors[] = 'Vous ne pouvez pas vous retirer vous-même du groupe admin.';
            }
            if (!$isSelf && (!$canStillLogIn || !$keepsAdmin) && $this->isLastActiveAdmin($id)) {
                $errors[] = 'Ce compte est le dernier administrateur actif : il doit rester actif, vérifié et membre du groupe admin.';
            }
        }
        return [$form, $errors];
    }

    private function isLastActiveAdmin(int $userId): bool
    {
        return UserAccounts::activeAdminIds($this->app->db()) === [$userId];
    }

    /** Invitation for accounts without a password, reset link otherwise */
    private function sendPasswordLink(array $account): bool
    {
        $app = $this->app;
        $invitation = !$account['has_password'];
        $ttl = $invitation ? self::INVITATION_TTL : self::ADMIN_RESET_TTL;
        $app->tokens->deleteForUser((int) $account['id'], 'reset_password');
        $token = $app->tokens->create((int) $account['id'], 'reset_password', $ttl);
        $name = $account['display_name'] !== '' ? $account['display_name'] : $account['username'];
        return $this->mail($account['email'], $name, $invitation ? 'invitation' : 'reset-password', [
            'name' => $name,
            'username' => $account['username'],
            'reset_url' => $app->publicUrl('reset-password?token=' . rawurlencode($token)),
            'validity' => $invitation ? (self::INVITATION_TTL / 86400) . ' jours' : (self::ADMIN_RESET_TTL / 3600) . ' heures',
            'login_url' => $app->publicUrl('login'),
        ]);
    }
}
