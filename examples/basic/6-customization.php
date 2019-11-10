<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Interceptor\LogIntoHttpArchive;
use Amp\Http\Client\Interceptor\MatchOrigin;
use Amp\Http\Client\Interceptor\SetRequestHeader;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use function Amp\delay;

require __DIR__ . '/../.helper/functions.php';

Loop::run(static function () use ($argv) {
    try {
        $client = (new HttpClientBuilder)
            ->intercept(new LogIntoHttpArchive(__DIR__ . '/log.har'))
            ->intercept(new MatchOrigin(['https://amphp.org' => new SetRequestHeader('x-amphp', 'true')]))
            ->followRedirects(0)
            ->retry(3)
            ->build();

        for ($i = 0; $i < 5; $i++) {
            /** @var Response $response */
            $response = yield $client->request(new Request($argv[1] ?? 'https://httpbin.org/user-agent'));

            dumpRequestTrace($response->getRequest());
            dumpResponseTrace($response);

            dumpResponseBodyPreview(yield $response->getBody()->buffer());

            print 'Waiting ' . ($i * 65) . ' seconds...' . "\r\n";

            if ($i !== 5) {
                yield delay($i * 65 * 1000);
            }
        }
    } catch (HttpException $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The Client::request() method itself will never throw directly, but returns a promise.
        echo $error;
    }
});
