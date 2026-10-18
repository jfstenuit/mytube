<?php
namespace App\Controllers;

use App\Media\VideoProbe;
use App\Models\Collection;
use App\Models\Video;

/** Administration: video catalogue and registration of media found in media/ */
class AdminController extends AdminBaseController
{
    public function index(): void
    {
        if ($this->guard()) {
            $this->app->redirect('admin/videos');
        }
    }

    /** Registered videos */
    public function videos(): void
    {
        if (!$this->guard()) {
            return;
        }
        $videos = Video::all($this->app->db());
        foreach ($videos as &$video) {
            $video['media'] = $this->library->locate($video['path']);
        }
        $this->render('admin/videos.twig', ['videos' => $videos, 'tab' => 'videos']);
    }

    /** Media files and HLS directories not registered yet */
    public function media(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->render('admin/media.twig', ['entries' => $this->unregisteredMedia(), 'tab' => 'media']);
    }

    /** Registration form for an unregistered media entry (?path=…) */
    public function create(): void
    {
        if (!$this->guard()) {
            return;
        }
        $app = $this->app;
        $db = $app->db();
        $path = $app->request->isPost() ? $app->request->input('path', '') : $app->request->query('path', '');
        $entry = $this->library->findUnregistered($path, Video::registeredPaths($db));
        if ($entry === null || $entry['problem'] !== null) {
            $app->session->flash('error', $entry === null
                ? 'Ce média n\'existe pas ou est déjà enregistré.'
                : 'Ce média ne peut pas être ajouté dans son état actuel (voir la liste).');
            $app->redirect('admin/media');
            return;
        }
        $media = $this->library->locate($path);
        $info = VideoProbe::probe($media['file']);

        $form = [
            'title' => self::suggestTitle($path),
            'description' => '',
            'collection_id' => '0',
            'new_collection' => '',
            'visibility' => 'private',
            'thumbnail_time' => (string) VideoProbe::defaultThumbnailTime($info['duration'] ?? null),
        ];
        $errors = [];

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            [$form, $data, $errors] = $this->readForm($form);
            if (!$errors) {
                $data['path'] = $path;
                $data['resolution'] = $info ? "{$info['width']}x{$info['height']}" : '';
                $data['duration'] = $info && $info['duration'] !== null ? round($info['duration'], 3) : null;
                $id = Video::create($db, $data);
                $thumbnailOk = VideoProbe::generateThumbnail($media['file'], $this->thumbnailFile($id), (float) $form['thumbnail_time']);
                $app->session->flash('success', sprintf('La vidéo « %s » a été ajoutée.', $data['title']));
                if (!$thumbnailOk) {
                    $app->session->flash('error', 'La vignette n\'a pas pu être générée. Essayez un autre moment de la vidéo.');
                }
                $app->redirect('admin/videos/edit?id=' . rawurlencode($id));
                return;
            }
        }

        $this->render('admin/video-form.twig', [
            'tab' => 'media',
            'mode' => 'create',
            'entry' => $entry,
            'info' => $info,
            'form' => $form,
            'errors' => $errors,
            'collections' => Collection::all($db),
        ], $errors ? 422 : 200);
    }

    /** Edit metadata of a registered video (?id=…), optionally regenerating its thumbnail */
    public function edit(): void
    {
        if (!$this->guard()) {
            return;
        }
        $app = $this->app;
        $db = $app->db();
        $id = $app->request->isPost() ? $app->request->input('id', '') : $app->request->query('id', '');
        $video = Video::find($db, $id);
        if ($video === null) {
            $app->notFound();
            return;
        }
        $media = $this->library->locate($video['path']);

        $form = [
            'title' => $video['title'],
            'description' => $video['description'],
            'collection_id' => (string) $video['collection_id'],
            'new_collection' => '',
            'visibility' => $video['visibility'],
            'thumbnail_time' => '',
        ];
        $errors = [];

        if ($app->request->isPost()) {
            if (!$app->checkCsrf()) {
                return;
            }
            [$form, $data, $errors] = $this->readForm($form, thumbnailRequired: false);
            if (!$errors) {
                Video::update($db, $id, $data);
                $app->session->flash('success', 'Les modifications ont été enregistrées.');
                if ($form['thumbnail_time'] !== '') {
                    if ($media && VideoProbe::generateThumbnail($media['file'], $this->thumbnailFile($id), (float) $form['thumbnail_time'])) {
                        $app->session->flash('success', 'La vignette a été régénérée.');
                    } else {
                        $app->session->flash('error', 'La vignette n\'a pas pu être générée.');
                    }
                }
                $app->redirect('admin/videos/edit?id=' . rawurlencode($id));
                return;
            }
        }

        $this->render('admin/video-form.twig', [
            'tab' => 'videos',
            'mode' => 'edit',
            'video' => $video,
            'media' => $media,
            'info' => $media ? VideoProbe::probe($media['file']) : null,
            'has_thumbnail' => is_file($this->thumbnailFile($id)),
            'form' => $form,
            'errors' => $errors,
            'collections' => Collection::all($db),
        ], $errors ? 422 : 200);
    }

    /** Remove a video's metadata and thumbnail; the media files are kept */
    public function delete(): void
    {
        if (!$this->guard() || !$this->app->checkCsrf()) {
            return;
        }
        $app = $this->app;
        $video = Video::find($app->db(), $app->request->input('id', ''));
        if ($video !== null) {
            Video::delete($app->db(), $video['id']);
            @unlink($this->thumbnailFile($video['id']));
            $app->session->flash('success', sprintf(
                'La vidéo « %s » a été supprimée du catalogue. Ses fichiers restent dans media/%s.',
                $video['title'], $video['path']));
        }
        $app->redirect('admin/videos');
    }




    /**
     * Reads and validates the metadata form.
     * @return array{0: array, 1: array, 2: string[]} [form values, data to store, errors]
     */
    private function readForm(array $form, bool $thumbnailRequired = true): array
    {
        $request = $this->app->request;
        foreach ($form as $field => $_) {
            $form[$field] = trim($request->input($field, ''));
        }
        $errors = [];

        if ($form['title'] === '' || mb_strlen($form['title']) > 255) {
            $errors[] = 'Le titre est obligatoire (255 caractères au maximum).';
        }
        if (mb_strlen($form['description']) > 65535) {
            $errors[] = 'La description est trop longue.';
        }
        if (!in_array($form['visibility'], Video::VISIBILITIES, true)) {
            $errors[] = 'Visibilité invalide.';
        }
        if ($form['thumbnail_time'] !== '' && (!is_numeric($form['thumbnail_time']) || (float) $form['thumbnail_time'] < 0)) {
            $errors[] = 'Le moment de la vignette doit être un nombre de secondes positif.';
        } elseif ($thumbnailRequired && $form['thumbnail_time'] === '') {
            $form['thumbnail_time'] = '5';
        }

        $collectionId = null;
        if ($form['new_collection'] !== '') {
            if (mb_strlen($form['new_collection']) > 255) {
                $errors[] = 'Le nom de la collection est trop long.';
            } elseif (!$errors) {
                $collectionId = Collection::findOrCreate($this->app->db(), $form['new_collection']);
            }
        } elseif (ctype_digit($form['collection_id']) && Collection::exists($this->app->db(), (int) $form['collection_id'])) {
            $collectionId = (int) $form['collection_id'];
        } else {
            $errors[] = 'Choisissez une collection.';
        }

        $data = [
            'title' => $form['title'],
            'description' => $form['description'],
            'visibility' => $form['visibility'],
            'collection_id' => $collectionId,
        ];
        return [$form, $data, $errors];
    }

    private function thumbnailFile(string $id): string
    {
        return $this->app->rootDir . '/thumbs/' . $id . '.jpg';
    }

    /** "MVI_1953" → "MVI 1953"; generated names (hashes) give an empty title */
    private static function suggestTitle(string $path): string
    {
        if (preg_match('/^[0-9a-f]{16,}$/i', $path)) {
            return '';
        }
        return trim(preg_replace('/[_.]+/', ' ', $path));
    }
}
