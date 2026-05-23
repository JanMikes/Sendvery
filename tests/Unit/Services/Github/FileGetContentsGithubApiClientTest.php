<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Github;

use App\Services\Github\FileGetContentsGithubApiClient;
use PHPUnit\Framework\TestCase;

final class FileGetContentsGithubApiClientTest extends TestCase
{
    /**
     * Guard against accidental path-traversal in the repo identifier. The
     * client is only called with the hardcoded 'janmikes/sendvery' today,
     * but the public method must reject anything that isn't a valid
     * `<owner>/<repo>` pair so a future caller can't smuggle arbitrary
     * path segments into the GitHub API URL.
     */
    public function testRejectsRepoWithPathTraversal(): void
    {
        $client = new FileGetContentsGithubApiClient();

        self::assertFalse($client->fetchRepoStats('../../users'));
        self::assertFalse($client->fetchRepoStats('janmikes/sendvery/../../users'));
        self::assertFalse($client->fetchRepoStats('janmikes/sendvery?foo=bar'));
        self::assertFalse($client->fetchRepoStats('janmikes/sendvery#fragment'));
        self::assertFalse($client->fetchRepoStats('janmikes sendvery'));
        self::assertFalse($client->fetchRepoStats(''));
        self::assertFalse($client->fetchRepoStats('justone'));
        self::assertFalse($client->fetchRepoStats('too/many/segments'));
    }
}
