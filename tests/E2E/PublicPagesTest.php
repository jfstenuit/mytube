<?php
namespace Tests\E2E;

use Tests\Support\E2ETestCase;

class PublicPagesTest extends E2ETestCase
{
    public function testWatchPageOfAPublicVideo(): void
    {
        $id = $this->fx->video(['title' => 'ZZ Public watch']);
        $response = $this->client()->get("watch?v=$id");
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('<title>ZZ Public watch</title>', $response->body);
        $this->assertStringContainsString('<base href="' . $this->basePath() . '">', $response->body);
        $this->assertStringContainsString('og:url', $response->body);
        $this->assertSame([], $response->cookiesSet(), 'public pages set no cookie');
        $this->assertStringContainsString('Cookie', (string) $response->header('vary'));
        $this->assertMatchesRegularExpression('#assets/css/style\.css\?v=\d+#', $response->body, 'versioned assets');
        $this->assertSame(['0 vue'], $response->texts('view-count'));
    }

    public function testEmbedAndOembed(): void
    {
        $id = $this->fx->video(['title' => 'ZZ Embed']);
        $client = $this->client();

        $embed = $client->get("embed?v=$id");
        $this->assertSame(200, $embed->status);
        $this->assertStringNotContainsString('site-header', $embed->body, 'no site chrome in the iframe');

        $json = $client->get("oembed?v=$id");
        $this->assertStringStartsWith('application/json', $json->header('content-type'));
        $data = $json->json();
        $this->assertSame('ZZ Embed', $data['title']);
        $this->assertSame($this->url("thumbs/$id.jpg"), $data['thumbnail_url'], 'thumbnail named after the video id');
        $this->assertStringContainsString($this->url("embed?v=$id"), $data['html']);

        $xml = $client->get("oembed?v=$id&format=xml");
        $this->assertStringStartsWith('text/xml', $xml->header('content-type'));
        $this->assertStringContainsString('<title>ZZ Embed</title>', $xml->body);
        $this->assertStringContainsString("thumbs/$id.jpg</thumbnail_url>", $xml->body);
        $this->assertNotFalse(simplexml_load_string($xml->body), 'well-formed XML');
    }

    public function testOembedEscapesSpecialCharacters(): void
    {
        $id = $this->fx->video(['title' => 'ZZ "Quoted" & <tagged> été']);
        $client = $this->client();
        $this->assertSame('ZZ "Quoted" & <tagged> été', $client->get("oembed?v=$id")->json()['title']);
        $xml = simplexml_load_string($client->get("oembed?v=$id&format=xml")->body);
        $this->assertSame('ZZ "Quoted" & <tagged> été', (string) $xml->title);
    }

    public function testErrors(): void
    {
        $client = $this->client();
        $this->assertSame(400, $client->get('watch')->status, 'missing id');
        $this->assertSame(400, $client->get('watch?v=bad')->status, 'malformed id');
        $this->assertSame(404, $client->get('watch?v=zzUnknown000')->status, 'unknown id');
        $this->assertSame(404, $client->get('no-such-page')->status);
        $this->assertSame(404, $client->get('x/watch')->status);
        $logout = $client->get('logout');
        $this->assertSame(405, $logout->status);
        $this->assertSame('POST', $logout->header('allow'));
    }

    public function testFirstViewCreatesTheThumbnailOfAShortVideo(): void
    {
        $id = $this->fx->video();  // 4 s clip: the thumbnail is taken at 1 s, not 2 min 20 s
        $this->assertFileDoesNotExist(MYTUBE_ROOT . "/thumbs/$id.jpg");
        $this->client()->get("watch?v=$id");
        $this->assertFileExists(MYTUBE_ROOT . "/thumbs/$id.jpg");
    }

    public function testVideoWithMissingMediaIs404(): void
    {
        $id = $this->fx->video(['path' => 'zz_no_such_media']);
        $this->assertSame(404, $this->client()->get("watch?v=$id")->status);
    }

    public function testHomePageShowsAListedVideoOrTheWelcomePage(): void
    {
        $publicCount = (int) $this->fx->scalar("SELECT COUNT(*) FROM videos WHERE visibility = 'public'");
        $home = $this->client()->get('');
        $this->assertSame(200, $home->status);
        $this->assertSame([], $home->cookiesSet());
        if ($publicCount === 0) {
            $this->assertStringContainsString('Bienvenue sur', $home->body);
            $this->assertStringContainsString('href="register"', $home->body);
        } else {
            $this->assertStringContainsString('id="video-player"', $home->body);
            $this->assertSame('no-store', $home->header('cache-control'));
        }
    }

    public function testHomePageNeverPicksUnlistedOrPrivateVideosForAnonymousVisitors(): void
    {
        $public = $this->fx->video(['title' => 'ZZ Home public']);
        $this->fx->video(['title' => 'ZZ Home unlisted', 'visibility' => 'unlisted']);
        $this->fx->video(['title' => 'ZZ Home private', 'visibility' => 'private']);
        $client = $this->client();
        for ($i = 0; $i < 15; $i++) {
            $body = $client->get('')->body;
            $this->assertStringNotContainsString('ZZ Home unlisted', $body);
            $this->assertStringNotContainsString('ZZ Home private', $body);
        }
        $this->assertNotNull($public);
    }

    public function testRootAliasesAndBanner(): void
    {
        $client = $this->client();
        $this->assertSame(200, $client->get('index.php')->status);
        $login = $client->get('login');
        $this->assertStringContainsString('class="brand" href="./"', $login->body, 'banner links home');
        $this->assertStringContainsString('class="btn btn-login"', $login->body);
    }
}
