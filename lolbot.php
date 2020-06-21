<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__.'/library/helpers.php';
use Symfony\Component\Yaml\Yaml;

set_include_path(implode(PATH_SEPARATOR, array(__DIR__.'/library', __DIR__.'/plugins', get_include_path())));

spl_autoload_register( function($class)
{
    $path = str_replace('\\', '/', $class).'.php';
    include $path;
    return class_exists($class, false);
});

$config = Yaml::parseFile(__DIR__.'/config.yaml');


const waURL = 'https://api.wolframalpha.com/v2/query?input=';

use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

Loop::run( function() {
    global $config;
    $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);

    $bot->on('welcome', function ($e, \Irc\Client $bot) {
        global $config;
        $nick = $bot->getNick();
        $bot->send("MODE $nick +x");
        $bot->join(implode(',', $config['channels']));
    });

    $bot->on('chat', function ($args, $bot) {
        $chan = $args->channel;
        $a = explode(' ', $args->text);
        $a[0] = strtolower($a[0]);
        if ($a[0] == '.knio') {
            $bot->pm($chan, "Knio is a cool guy");
            return;
        }

        if ($a[0] == '.bing') {
            if(!isset($a[1])) {
                $bot->pm($chan, "give me something to lookup");
                return;
            }

            \Amp\asyncCall(function () use ($a, $bot, $chan) {
                global $config;
                unset($a[0]);
                $query = urlencode(htmlentities(implode(' ', $a)));
                $url = $config['bingEP'] . "search?q=$query&mkt=$config[bingLang]&setLang=$config[bingLang]";
                try {
                    $client = HttpClientBuilder::buildDefault();
                    $request = new Request($url);
                    $request->setHeader('Ocp-Apim-Subscription-Key', $config['bingKey']);
                    /** @var Response $response */
                    $response = yield $client->request($request);
                    $body = yield $response->getBody()->buffer();
                    if($response->getStatus() != 200) {
                        // Just in case its huge or some garbage
                        $body = substr($body, 0, 200);
                        $bot->pm($chan, "Error (" . $response->getStatus() . ") $body");
                        return;
                    }
                    $j = json_decode($body, true);

                    if(!array_key_exists('webPages', $j)) {
                        $bot->pm($chan, "\2Bing:\2 No Results");
                        return;
                    }
                    $results = $j['webPages']['totalEstimatedMatches'];
                    $res = $j['webPages']['value'][0];

                    $bot->pm($chan, "\2Bing (\2$results Results\2):\2 $res[url] ($res[name]) -- $res[snippet]");
                } catch (HttpException $error) {
                    // If something goes wrong Amp will throw the exception where the promise was yielded.
                    // The HttpClient::request() method itself will never throw directly, but returns a promise.
                    echo $error;
                    $bot->pm($chan, "\2WA:\2" . $error);
                }
            });
        }

        if ($a[0] == '.stock') {
            if(!isset($a[1])) {
                $bot->pm($chan, "give me something to lookup");
                return;
            }

            \Amp\asyncCall(function () use ($a, $bot, $chan) {
                global $config;
                $stocks = $a[1];
                if(substr_count($stocks, ',') > 0) {
                    $bot->pm($chan, "Please only 1 stock at time wtf");
                    return;
                }
                $query = urlencode(htmlentities($stocks));
                $url = "https://cloud.iexapis.com/stable/stock/$query/quote?token=" . $config['iexKey'] . '&displayPercent=true';
                try {
                    $client = HttpClientBuilder::buildDefault();
                    /** @var Response $response */
                    $response = yield $client->request(new Request($url));
                    $body = yield $response->getBody()->buffer();
                    if($response->getStatus() != 200) {
                        // Just in case its huge or some garbage
                        $body = substr($body, 0, 200);
                        $bot->pm($chan, "Error (" . $response->getStatus() . ") $body");
                        return;
                    }
                    $j = json_decode($body, true);

                    $bot->pm($chan, "$j[symbol] ($j[companyName]) $j[latestPrice] $j[change] ($j[changePercent]%)");
                } catch (HttpException $error) {
                    // If something goes wrong Amp will throw the exception where the promise was yielded.
                    // The HttpClient::request() method itself will never throw directly, but returns a promise.
                    echo $error;
                    $bot->pm($chan, "\2WA:\2" . $error);
                }
            });
        }

        if ($a[0] == '.calc') {
            \Amp\asyncCall(function () use ($a, $bot, $chan) {
                global $config;
                echo "starting calc\n";
                unset($a[0]);
                $arg2 = implode(' ', $a);
                $query = waURL . urlencode(htmlentities($arg2)) . '&appid=' . $config['waKey'] . '&format=plaintext';
                try {
                    $client = HttpClientBuilder::buildDefault();
                    // Make an asynchronous HTTP request
                    $promise = $client->request(new Request($query));
                    // Client::request() is asynchronous! It doesn't return a response. Instead, it returns a promise to resolve the
                    // response at some point in the future when we've received the headers of the response. Here we use yield which
                    // pauses the execution of the current coroutine until the promise resolves. Amp will automatically continue the
                    // coroutine then.
                    /** @var Response $response */
                    $response = yield $promise;

                    $body = yield $response->getBody()->buffer();

                    $xml = simplexml_load_string($body);
                    $res = '';
                    $resa = null;
                    $resb = null;

                    //first check if there was an error
                    if ($xml['success'] == 'false') {
                        $res = @$xml->tips->tip[0]['text'];
                    } else {
                        //the xml has things called pods so lets cycle through em
                        //i decided to cycle here in case i want to look at more then 2 in future
                        $count = 0;
                        foreach ($xml->pod as $pod) {
                            //I'm pretty sure our input pod will always be called Input
                            //Or will be the first pod
                            if ($count == 0) {
                                //input
                                $resa = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                            }
                            if ($count == 1) {
                                $resb = str_replace("\n", "\2;\2 ", $pod->subpod->plaintext);
                            }
                            if ($count != 1 && $pod['id'] == 'DecimalApproximation') {
                                $resb .= " \2DecimalApproximation:\2 " . $pod->subpod->plaintext;
                            }
                            $count++;
                        }
                        $res = "$resa = $resb";
                    }
                    $parsetime = $xml['parsetiming'];
                    $outtatime = $xml['parsetimedout'];
                    //we didn't have tips? try didyoumean
                    if ($res == '') {
                        $res = "No results for query";
                        if(isset($xml->didyoumeans->didyoumean[0])) {
                            $res .= ", Did you mean: " . $xml->didyoumeans->didyoumean[0];
                        }
                    }

                    if ($outtatime != 'false') {
                        $res = "Error, query took too long to parse.";
                    }
                    $bot->pm($chan, "\2WA:\2 " . $res);
                } catch (HttpException $error) {
                    // If something goes wrong Amp will throw the exception where the promise was yielded.
                    // The HttpClient::request() method itself will never throw directly, but returns a promise.
                    echo $error;
                    $bot->pm($chan, "\2WA:\2 " . $error->getMessage());
                }
            });
        }
    });
    Loop::onSignal(SIGINT, function () use ($bot) {
        echo "Caught SIGINT! exiting ...\n";
        yield from $bot->sendNow("quit :Caught SIGINT GOODBYE!!!!\r\n");
        //Loop::delay(2000, function() {exit;});
        exit;
    });

    while(1) {
        yield from $bot->go();
    }
});
