<?php
namespace App\Controllers;

use App\Models\Collection;
use App\Models\Group;
use App\Models\User;
use App\Models\UserAccounts;

/** Administration of groups: members and granted collections */
class AdminGroupsController extends AdminBaseController
{
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->render('admin/groups.twig', ['tab' => 'groups', 'groups' => Group::all($this->app->db())]);
    }

    public function create(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->form(null);
    }

    public function edit(): void
    {
        if (!$this->guard()) {
            return;
        }
        $app = $this->app;
        $id = (int) ($app->request->isPost() ? $app->request->input('id', '0') : $app->request->query('id', '0'));
        $group = Group::find($app->db(), $id);
        if ($group === null) {
            $app->notFound();
            return;
        }
        $this->form($group);
    }

    public function delete(): void
    {
        if (!$this->guard() || !$this->app->checkCsrf()) {
            return;
        }
        $app = $this->app;
        $group = Group::find($app->db(), (int) $app->request->input('id', '0'));
        if ($group !== null && $group['is_builtin']) {
            $app->session->flash('error', 'Le groupe intégré « admin » ne peut pas être supprimé.');
            $app->redirect('admin/groups/edit?id=' . $group['id']);
            return;
        }
        if ($group !== null) {
            Group::delete($app->db(), (int) $group['id']);
            $app->session->flash('success', sprintf('Le groupe « %s » a été supprimé.', $group['name']));
        }
        $app->redirect('admin/groups');
    }

    /** Create ($group null) or edit form */
    private function form(?array $group): void
    {
        $app = $this->app;
        $db = $app->db();
        $builtin = $group !== null && $group['is_builtin'];
        $form = [
            'name' => $group['name'] ?? '',
            'description' => $group['description'] ?? '',
            'members' => $group ? Group::memberIds($db, (int) $group['id']) : [],
            'collections' => $group ? Group::collectionIds($db, (int) $group['id']) : [],
        ];
        $errors = [];

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            $userIds = array_map('intval', array_column(UserAccounts::options($db), 'id'));
            $collectionIds = array_map('intval', array_column(Collection::all($db), 'id'));
            $form = [
                'name' => $builtin ? $group['name'] : trim($app->request->input('name', '')),
                'description' => $builtin ? $group['description'] : trim($app->request->input('description', '')),
                'members' => array_values(array_intersect($this->postedIds('members'), $userIds)),
                // The admin group has access to everything: no grants
                'collections' => $builtin ? [] : array_values(array_intersect($this->postedIds('collections'), $collectionIds)),
            ];

            if ($form['name'] === '' || mb_strlen($form['name']) > 64 || preg_match('/[\x00-\x1F\x7F]/', $form['name'])) {
                $errors[] = 'Le nom est obligatoire (64 caractères au maximum).';
            } elseif (Group::nameExists($db, $form['name'], $group ? (int) $group['id'] : null)) {
                $errors[] = 'Un groupe porte déjà ce nom.';
            }
            if (mb_strlen($form['description']) > 255 || preg_match('/[\x00-\x1F\x7F]/', $form['description'])) {
                $errors[] = 'La description n\'est pas valide (255 caractères au maximum).';
            }
            if ($builtin && (int) $group['id'] === User::ADMIN_GROUP_ID) {
                $errors = array_merge($errors, $this->adminMembershipErrors($form['members']));
            }

            if (!$errors) {
                if ($group === null) {
                    $id = Group::create($db, $form['name'], $form['description']);
                    $app->session->flash('success', sprintf('Le groupe « %s » a été créé.', $form['name']));
                } else {
                    $id = (int) $group['id'];
                    Group::update($db, $id, $form['name'], $form['description']);
                    $app->session->flash('success', 'Les modifications ont été enregistrées.');
                }
                Group::setMembers($db, $id, $form['members']);
                Group::setCollections($db, $id, $form['collections']);
                $app->redirect('admin/groups/edit?id=' . $id);
                return;
            }
        }

        $this->render('admin/group-form.twig', [
            'tab' => 'groups',
            'mode' => $group ? 'edit' : 'create',
            'group' => $group,
            'builtin' => $builtin,
            'form' => $form,
            'errors' => $errors,
            'users' => UserAccounts::options($db),
            'collections' => Collection::all($db),
            'self_id' => $this->admin()->id,
        ], $errors ? 422 : 200);
    }

    /** The admin group must keep the current administrator and at least one admin who can log in */
    private function adminMembershipErrors(array $memberIds): array
    {
        $errors = [];
        if (!in_array($this->admin()->id, $memberIds, true)) {
            $errors[] = 'Vous ne pouvez pas vous retirer vous-même du groupe admin.';
        }
        if (!array_intersect(UserAccounts::activeAdminIds($this->app->db()), $memberIds)) {
            $errors[] = 'Le groupe admin doit garder au moins un administrateur actif.';
        }
        return $errors;
    }
}
