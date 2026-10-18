<?php
namespace App\Controllers;

use App\Core\App;
use App\Media\MediaLibrary;
use App\Models\User;
use App\Models\Video;
use RuntimeException;

/** Shared plumbing of the administration pages: admin check, tab badges, mail */
abstract class AdminBaseController
{
    protected MediaLibrary $library;
    private ?array $unregistered = null;

    public function __construct(protected App $app)
    {
        $this->library = new MediaLibrary($app->rootDir . '/media');
    }

    /** Admins only; anonymous visitors go to the login page, other users get a 404 */
    protected function guard(): bool
    {
        $user = $this->app->auth->user();
        if ($user === null) {
            $this->app->redirect('login?return=' . urlencode($this->app->request->uri()));
            return false;
        }
        if (!$user->isAdmin()) {
            $this->app->notFound();
            return false;
        }
        return true;
    }

    /** The logged-in administrator (call after guard()) */
    protected function admin(): User
    {
        return $this->app->auth->user();
    }

    protected function render(string $template, array $context, int $status = 200): void
    {
        $context['unregistered_count'] = count(array_filter($this->unregisteredMedia(), fn ($e) => $e['problem'] === null));
        $this->app->render($template, $context, $status);
    }

    protected function unregisteredMedia(): array
    {
        return $this->unregistered ??= $this->library->unregistered(Video::registeredPaths($this->app->db()));
    }

    /** Ids posted by a checkbox list (name="field[]"), as integers */
    protected function postedIds(string $field): array
    {
        return array_values(array_unique(array_map('intval', array_filter($this->app->request->inputList($field), 'ctype_digit'))));
    }

    protected function mail(string $to, string $name, string $template, array $context): bool
    {
        try {
            $this->app->mailer()->send($to, $name, $template, $context);
            return true;
        } catch (RuntimeException $e) {
            error_log("Could not send '$template' mail to $to: " . $e->getMessage());
            return false;
        }
    }
}
