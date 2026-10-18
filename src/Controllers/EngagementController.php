<?php
namespace App\Controllers;

use App\Auth\Access;
use App\Core\App;
use App\Core\Config;
use App\Models\Engagement;
use App\Models\Video;
use App\Stats\ViewCounter;

/**
 * Views (anyone who can watch), likes and comments (logged-in users whose group has
 * access to the video's collection, and admins).
 */
class EngagementController
{
    private Access $access;

    public function __construct(private App $app)
    {
        $this->access = new Access($app->db(), $app->auth->user());
    }

    /** Beacon from the player once the view threshold is reached; always answers 204 */
    public function view(): void
    {
        $app = $this->app;
        $video = Video::find($app->db(), $app->request->input('v', ''));
        if ($video !== null && $this->access->canView($video)) {
            $counter = new ViewCounter($app->db(), $app->throttle, Config::get('app.secret'));
            $counter->record($video, $app->request->input('token', ''), $app->auth->user(),
                $app->clientIp, $app->request->userAgent());
        }
        http_response_code(204);
    }

    /** Toggle the like; JSON for the script, redirect for the no-JavaScript form */
    public function like(): void
    {
        $app = $this->app;
        $json = $app->request->wantsJson();
        $video = Video::find($app->db(), $app->request->input('v', ''));
        $user = $app->auth->user();

        if (!$app->csrf->isValid($app->request->input('csrf_token'))) {
            $this->fail($json, 400, 'Le formulaire a expiré. Veuillez recharger la page.', $video);
            return;
        }
        if ($video === null || !$this->canEngage($video)) {
            $this->fail($json, 403, 'Seuls les membres ayant accès à cette collection peuvent aimer cette vidéo.', $video);
            return;
        }

        $liked = Engagement::toggleLike($app->db(), $video['id'], $user->id);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['liked' => $liked, 'count' => Engagement::likeCount($app->db(), $video['id'])]);
            return;
        }
        $app->redirect('watch?v=' . rawurlencode($video['id']));
    }

    public function comment(): void
    {
        $app = $this->app;
        $video = Video::find($app->db(), $app->request->input('v', ''));
        if ($video === null || !$app->checkCsrf()) {
            if ($video === null) {
                $app->notFound();
            }
            return;
        }
        $back = 'watch?v=' . rawurlencode($video['id']) . '#comments';
        $user = $app->auth->user();
        if (!$this->canEngage($video)) {
            $app->session->flash('error', 'Seuls les membres ayant accès à cette collection peuvent commenter.');
            $app->redirect($back);
            return;
        }

        $text = trim(str_replace("\r\n", "\n", $app->request->input('comment', '')));
        $subject = 'user:' . $user->id;
        $error = match (true) {
            $text === '' => 'Le commentaire est vide.',
            mb_strlen($text) > Engagement::COMMENT_MAX_LENGTH =>
                'Le commentaire est trop long (' . Engagement::COMMENT_MAX_LENGTH . ' caractères au maximum).',
            $app->throttle->tooMany('comment', $subject, $app->clientIp) =>
                'Vous avez publié beaucoup de commentaires récemment. Veuillez patienter quelques minutes.',
            default => null,
        };
        if ($error !== null) {
            $app->session->flash('error', $error);
            $app->session->set('comment_draft', ['video' => $video['id'], 'text' => mb_substr($text, 0, 10000)]);
            $app->redirect($back);
            return;
        }

        Engagement::addComment($app->db(), $video['id'], $user->id, $text);
        $app->throttle->record('comment', $subject, $app->clientIp, true);
        $app->session->remove('comment_draft');
        $app->session->flash('success', 'Votre commentaire a été publié.');
        $app->redirect($back);
    }

    /** The author or an admin can delete a comment */
    public function deleteComment(): void
    {
        $app = $this->app;
        if (!$app->checkCsrf()) {
            return;
        }
        $user = $app->auth->user();
        $comment = Engagement::findComment($app->db(), (int) $app->request->input('id', '0'));
        if ($comment === null || $user === null || ((int) $comment['user_id'] !== $user->id && !$user->isAdmin())) {
            $app->notFound();
            return;
        }
        Engagement::deleteComment($app->db(), (int) $comment['id']);
        $app->session->flash('success', 'Le commentaire a été supprimé.');
        $app->redirect('watch?v=' . rawurlencode($comment['video_id']) . '#comments');
    }

    private function canEngage(array $video): bool
    {
        return $this->app->auth->user() !== null && $this->access->canView($video)
            && $this->access->hasCollectionAccess((int) $video['collection_id']);
    }

    private function fail(bool $json, int $status, string $message, ?array $video): void
    {
        $app = $this->app;
        if ($json) {
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode(['error' => $message]);
            return;
        }
        if ($video === null) {
            $app->notFound();
            return;
        }
        $app->session->flash('error', $message);
        $app->redirect('watch?v=' . rawurlencode($video['id']));
    }
}
