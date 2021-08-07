<?php

use knivey\cmdr\attributes\CallWrap;
use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\Syntax;

#[Cmd("masshl")]
function masshl($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    $jewbirdHosts = [
        "*@vegan.fried.chicken.with.sodiumchlori.de",
        "*@*.jewbird.live",
    ];
    foreach($jewbirdHosts as $hostmask) {
        if (preg_match(hostmaskToRegex($hostmask), $args->fullhost)) {
            goto pass;
        }
    }
    return;
    pass:
    \Amp\asyncCall(function () use ($args, $bot) {
        try {
            $names = yield getChanUsers($args->chan, $bot);
        } catch (\Exception $e) {
            $bot->pm($args->chan, "masshl timeout? something horribowl must have happened :( try again");
            return;
        }
        $line = '';
        foreach ($names as $name) {
            $name = ltrim($name, "@+");
            $line .= "$name ";
            if(strlen($line) > 100) {
                $bot->pm($args->chan, rtrim($line));
                $line = '';
            }
        }
        $bot->pm($args->chan, rtrim($line));
    });
}

function getChanUsers($chan, $bot): \Amp\Promise {
    return \Amp\call(function () use ($chan, $bot) {
        $idx = null;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $chan, &$def) {
            if($args->channel == $chan) {
                $bot->off('names', null, $idx);
                $def->resolve($args->names);
            }
        };
        $bot->on('names', $cb, $idx);
        $bot->send("NAMES $chan");
        try {
            $names = yield \Amp\Promise\timeout($def->promise(), 4000);
        } catch (\Amp\TimeoutException $e) {
            $bot->off('names', null, $idx);
            throw $e;
        }
        return $names->names;
    });
}

#[Cmd("jrh")]
#[CallWrap("Amp\asyncCall")]
function jrh($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {
    if(!function_exists('getLiveVideos')) {
        echo "JRH requires youtube script loaded";
        return;
    }
    $jrhChannel = "UC21a1dW8hlmh7Zy7SrDbTRQ";
    try {
        $vids = yield getLiveVideos($jrhChannel);
    } catch (\Exception $e) {
        $bot->msg($args->chan, "Error when looking up youtube");
        echo $e;
        return;
    }
    if($vids == null || !is_array($vids) || count($vids) == 0) {
        $bot->msg($args->chan, "Don't see any live stream for JRH ATM :(");
        return;
    }
    $v = $vids[0];

    $w = strlen(" NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE");
    $title = $v->snippet->title;
    //try to center it
    $pad = (($w - strlen($title)) / 2) + strlen($title);
    if ($pad > 0)
        $title = str_pad($title, $pad, " ", STR_PAD_LEFT);

    $banner = "
https://www.youtube.com/watch?v={$v->id->videoId}                               https://twitch.tv/hughbord
$title
      😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃 😉 😌 😍 ASCIIBIRD DEVELOPMENT STREAM 😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃
                   WATCH THE WONDERFUL BIRD AND ASCIIBIRD NEARING COMPLETION LIVE NOW
                           ┏   ┰╛    ╔═━┉┈┉╼━━╌┈╍┅┉╌┄┉┉━═╾─┈═──┄┈╼╍═┈┄╍═╍╼━┈─┈╼┉╍┅╌╮
                         ╘███╏████╒█ ┕█   http://jewbird.live/                     ╏
                            █┻█  █┦█  █╕  http://yt.jewbird.live/                  ┇
                          ╔╼█ ████ ████╚━ http://patreon.jewbird.live/             ┃
                         ╕  █ █ █┉╍█ ┌█═  http://streamlabs.jewbird.live/          ╽
                       ━█████ █ ██ █ ╯█   ASCIIBIRD TAKING FLIGHT ASCIIBIRD FLIGHT ╎
                          ┸╮    ╛     ╘╼┈┅┅──━┈┉┅┈╍┄┈┄┈╍┉╾╾╼╍═━╾╾┄╼╾═─┈═┉═╼┅─┈━╌╾╾┅╯
                              [BTC] 1L2u8mQs5pe7k11ozn2BgX388e3fGMD7qo
[XMR] 832owKc3ZuGCnmjHXHeZeeJzGAxyKx5uWU9WxoaXg6BhQ7aWSnZ6EhxFK8Mzw137nSgGAfMM8FgHjM6rpq5s1EofD7UT2yp
           [STREAMLABS] http://streamlabs.jewbird.live [PATREON] http://patreon.jewbird.live
     [YT] http://yt.jewbird.live [TWITCH] http://twitch.jewbird.live [GITHUB] http://git.jewbird.live
😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃 😉 😌 😍 ASCIIBIRD DEVELOPMENT STREAM 😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙂 🙃
https://www.youtube.com/watch?v={$v->id->videoId}                                https://twitch.tv/hughbord
$title
 NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE NOW LIVE";
    $banner = trim($banner);
    $net = strtolower($bot->getOption('NETWORK'));
    if($net != 'efnet' && $net != 'ircltd') {
        $bot->msg($args->chan, "JRH now live! http://jewbird.live/ {$v->snippet->title}");
        return;
    }
    foreach (explode("\n", $banner) as $line) {
        $bot->msg($args->chan, $line);
    }
}

































