<?php
namespace App\Controllers;

use App\Models\Collection;
use App\Models\Group;

/** Administration of collections and of the groups granted access to them */
class AdminCollectionsController extends AdminBaseController
{
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->render('admin/collections.twig', [
            'tab' => 'collections',
            'collections' => Collection::all($this->app->db()),
            'default_id' => Collection::DEFAULT_ID,
        ]);
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
        $id = (int) ($app->request->isPost() ? $app->request->input('id', '-1') : $app->request->query('id', '-1'));
        $collection = Collection::find($app->db(), $id);
        if ($collection === null) {
            $app->notFound();
            return;
        }
        $this->form($collection);
    }

    public function delete(): void
    {
        if (!$this->guard() || !$this->app->checkCsrf()) {
            return;
        }
        $app = $this->app;
        $db = $app->db();
        $collection = Collection::find($db, (int) $app->request->input('id', '-1'));
        if ($collection === null) {
            $app->redirect('admin/collections');
            return;
        }
        $id = (int) $collection['id'];
        if ($id === Collection::DEFAULT_ID) {
            $app->session->flash('error', 'La collection par défaut ne peut pas être supprimée.');
        } elseif (Collection::videos($db, $id)) {
            $app->session->flash('error', 'Cette collection contient des vidéos : déplacez-les ou supprimez-les d\'abord.');
        } else {
            Collection::delete($db, $id);
            $app->session->flash('success', sprintf('La collection « %s » a été supprimée.', $collection['name']));
            $app->redirect('admin/collections');
            return;
        }
        $app->redirect('admin/collections/edit?id=' . $id);
    }

    /** Create ($collection null) or edit form */
    private function form(?array $collection): void
    {
        $app = $this->app;
        $db = $app->db();
        $id = $collection ? (int) $collection['id'] : null;
        // Grants to the admin group are pointless (it sees everything)
        $groups = array_values(array_filter(Group::all($db), fn ($g) => !$g['is_builtin']));
        $form = [
            'name' => $collection['name'] ?? '',
            'groups' => $id !== null ? Group::idsOfCollection($db, $id) : [],
        ];
        $errors = [];

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            $form = [
                'name' => trim($app->request->input('name', '')),
                'groups' => array_values(array_intersect($this->postedIds('groups'), array_map('intval', array_column($groups, 'id')))),
            ];
            if ($form['name'] === '' || mb_strlen($form['name']) > 255 || preg_match('/[\x00-\x1F\x7F]/', $form['name'])) {
                $errors[] = 'Le nom est obligatoire (255 caractères au maximum).';
            } elseif (Collection::nameExists($db, $form['name'], $id)) {
                $errors[] = 'Une collection porte déjà ce nom.';
            }

            if (!$errors) {
                if ($id === null) {
                    $id = Collection::create($db, $form['name']);
                    $app->session->flash('success', sprintf('La collection « %s » a été créée.', $form['name']));
                } else {
                    Collection::rename($db, $id, $form['name']);
                    $app->session->flash('success', 'Les modifications ont été enregistrées.');
                }
                Group::setCollectionGroups($db, $id, $form['groups']);
                $app->redirect('admin/collections/edit?id=' . $id);
                return;
            }
        }

        $this->render('admin/collection-form.twig', [
            'tab' => 'collections',
            'mode' => $collection ? 'edit' : 'create',
            'collection' => $collection,
            'is_default' => $id === Collection::DEFAULT_ID,
            'form' => $form,
            'errors' => $errors,
            'groups' => $groups,
            'videos' => $id !== null ? Collection::videos($db, $id) : [],
        ], $errors ? 422 : 200);
    }
}
