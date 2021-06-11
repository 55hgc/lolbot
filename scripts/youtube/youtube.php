<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Options;
use knivey\cmdr\attributes\Syntax;


function ytDuration($input) {
    try {
        $di = new DateInterval($input);
        $dur = '';
        if ($di->s > 0) {
            $dur = "{$di->s}s";
        }
        if ($di->i > 0) {
            $dur = "{$di->i}m $dur";
        }
        if ($di->h > 0) {
            $dur = "{$di->h}h $dur";
        }
        if ($di->d > 0) {
            $dur = "{$di->d}d $dur";
        }
        //Seems unlikely, months and years
        if ($di->m > 0) {
            $dur = "{$di->m}M $dur";
        }
        if ($di->y > 0) {
            $dur = "{$di->y}y $dur";
        }
        $dur = trim($dur);
        if ($dur == '') {
            $dur = 'LIVE';
        }
    } catch (Exception $e) {
        return '???';
    }
    return $dur;
}

$youtube_history = [];
function youtube(\Irc\Client $bot, $nick, $chan, $text)
{
    global $config, $youtube_history;

    //Avoiding clobber of jewbirds radio adverts
    if(str_contains($text, "                     https://twitch.tv/hughbord")) {
        return;
    }


    $key = $config['gkey'];
    $URL = '@^((?:https?:)?//)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(/(?:[\w\-]+\?v=|shorts/|embed/|v/)?)([\w\-]+)(\S+)?$@i';
    foreach (explode(' ', $text) as $word) {
        if (!preg_match($URL, $word, $m)) {
            continue;
        }

        if (!array_key_exists(5, $m)) {
            continue;
        }

        $id = $m[5];
        // Get this with https://www.youtube.com/watch?time_continue=165&v=Bfdy5a_R4K4
        if ($id == "watch") {
            $url = parse_url($word, PHP_URL_QUERY);
            foreach (explode('&', $url) as $p) {
                list($lhs, $rhs) = explode('=', $p);
                if ($lhs == 'v') {
                    $id = $rhs;
                }
            }
        }

        $repost = '';
        if(($youtube_history[$chan] ?? "") == $id) {
            $repost = "\x0307,01[\x0304,01REPOST\x0307,01]\x03 ";
        }
        $youtube_history[$chan] = $id;
        echo "Looking up youtube video $id\n";

        $data = null;
        $body = null;
        try {
            $client = HttpClientBuilder::buildDefault();
            /** @var Response $response */
            $response = yield $client->request(new Request("https://www.googleapis.com/youtube/v3/videos?id=$id&part=snippet%2CcontentDetails%2Cstatistics&key=$key"));
            $body = yield $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                $bot->pm($chan, "Error (" . $response->getStatus() . ")");
                echo "Error (" . $response->getStatus() . ")\n";
                var_dump($body);
                return;
            }
            $data = json_decode($body, false);
        } catch (\Exception $error) {
            // If something goes wrong Amp will throw the exception where the promise was yielded.
            // The HttpClient::request() method itself will never throw directly, but returns a promise.
            echo "$error\n";
            $bot->pm($chan, "\2YouTube Error:\2 " . substr($error, 0, strpos($error, "\n")));
            continue;
        }

        if (!is_object($data)) {
            echo "No data\n";
            var_dump($data);
            continue;
        }
        try {
            if(!is_array($data->items) || count($data->items) < 1)
                continue;
            $v = $data->items[0];
            $title = $v->snippet->title;

            $dur = ytDuration($v->contentDetails->duration);
            $chanTitle = $v->snippet->channelTitle;
            //$datef = 'M j, Y';
            //$date = date($datef, strtotime($v->snippet->publishedAt));
            //$views = number_format($v->statistics->viewCount);
            //$likes = number_format($v->statistics->likeCount);
            //$hates = number_format($v->statistics->dislikeCount);

            $thumbnail = $v?->snippet?->thumbnails?->high?->url;
            if($thumbnail != null && ($config['youtube_thumb'] ?? false) && isset($config['p2u']) && $repost == '') {
                $ext = explode('.', $thumbnail);
                $ext = array_pop($ext);
                try {
                    echo "fetching thumbnail at $thumbnail\n";
                    $client = HttpClientBuilder::buildDefault();
                    /** @var Response $response */
                    $response = yield $client->request(new Request($thumbnail));
                    $body = yield $response->getBody()->buffer();
                    if ($response->getStatus() != 200) {
                        $bot->pm($chan, "Error (" . $response->getStatus() . ")");
                        echo "Error (" . $response->getStatus() . ")\n";
                        var_dump($body);
                    } else {
                        $filename = "thumb_$id.$ext";
                        echo "saving to $filename\n";
                        file_put_contents($filename, $body);
                        $width = $config['youtube_thumbwidth'] ?? 40;
                        $filename_safe = escapeshellarg($filename);
                        $thumbnail = `$config[p2u] -f m -p x -w $width $filename_safe`;
                        unlink($filename);
                    }
                } catch (HttpException $error) {
                    // If something goes wrong Amp will throw the exception where the promise was yielded.
                    // The HttpClient::request() method itself will never throw directly, but returns a promise.
                    echo "$error\n";
                    $thumbnail = '';
                }
                if ($thumbnail != '') {
                    $thumbnail = explode("\n", trim($thumbnail));
                    foreach([count($thumbnail)-1,count($thumbnail)-2,1,0] as $i) {
                        if (trim($thumbnail[$i]) == "\x031,1") {
                            unset($thumbnail[$i]);
                        }
                    }
                    foreach ($thumbnail as $line) {
                        $bot->pm($chan, $line);
                    }
                }
            }

            $bot->pm($chan, "\2\3" . "01,00You" . "\3" . "00,04Tube\3\2 {$repost}$title | $chanTitle | $dur");
        } catch (\Exception $e) {
            $bot->pm($chan, "\2YouTube Error:\2 Unknown data received.");
            echo "\2YouTube Error:\2 Unknown data received.\n";
            var_dump($body);
        }
    }
}


#[Cmd("yt", "ytsearch", "youtube")]
#[Syntax('[query]...')]
#[CallWrap("Amp\asyncCall")]
#[Options("--amt")]
function ytsearch($args, \Irc\Client $bot, \knivey\cmdr\Request $req)
{
    global $config;
    $reply = function($msg) use($bot, $args) {$bot->pm($args->chan, "\2ytsearch:\2 $msg");};
    $key = $config['gkey'] ?? false;
    if(!$key) {
        $reply("youtube key not set on config");
        return;
    }
    $amt = 3;
    if($req->args->getOpt("--amt")) {
        $amt = $req->args->getOptVal("--amt");
        if($amt < 1 || $amt > 5) { //If greater than 5 should increase maxResults in api call
            $reply("Result --amt should be from 1 to 5");
            return;
        }
    }

    $q = urlencode(htmlentities($req->args['query']));
    // search only supports snippet part :(
    $url = "https://www.googleapis.com/youtube/v3/search?q=$q&key=$key&part=snippet&safeSearch=none&type=video";
    try {
        $client = HttpClientBuilder::buildDefault();
        /** @var Response $response */
        $response = yield $client->request(new Request($url));
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() != 200) {
            var_dump($body);
            // Just in case its huge or some garbage
            $body = str_replace(["\n","\r"], '', $body);
            $body = substr($body, 0, 200);
            $reply("Error (" . $response->getStatus() . ") $body");
            return;
        }
    } catch (\Exception $error) {
        echo $error;
        $reply(substr($error, 0, strpos($error, "\n")));
        return;
    }
    $res = json_decode($body, true);
    if(!isset($res['items']) || count($res['items']) == 0) {
        $reply("no results");
        return;
    }
    $cnt = 0;
    foreach ($res['items'] as $i) {
        if($cnt++ >=$amt)
            break;
        $s = $i['snippet'];
        $url = "https://youtu.be/{$i['id']['videoId']}";
        $title = html_entity_decode($s['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');;
        $title = htmlspecialchars_decode($title);
        $channel = html_entity_decode($s['channelTitle'], ENT_QUOTES | ENT_HTML5, 'UTF-8');;
        $channel = htmlspecialchars_decode($channel);
        $reply("$url - $title | $channel");
    }
    if($cnt < $amt) {
        $reply("No more results :(");
    }
}



























