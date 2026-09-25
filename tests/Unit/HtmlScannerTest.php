<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/HtmlScanner.php';

final class HtmlScannerTest extends TestCase
{
    private static function page(string $body, string $head = ''): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Hola</title>' . $head . '</head><body>' . $body . '</body></html>';
    }

    /** @return array<string,array{string,string}> */
    public static function malicious(): array
    {
        $p = static fn (string $b, string $h = ''): string => self::page($b, $h);
        return [
            'iframe'            => [$p('<iframe src="https://x.test"></iframe>'), 'html'],
            'IFRAME mayusculas' => [$p('<IFRAME SRC=x></IFRAME>'), 'html'],
            'frameset'          => ['<frameset><frame src=x></frameset>', 'html'],
            'object'            => [$p('<object data="x.swf"></object>'), 'html'],
            'embed'             => [$p('<embed src="x">'), 'html'],
            'applet'            => [$p('<applet code="x"></applet>'), 'html'],
            'form'              => [$p('<form action="https://evil.test"><input name=a></form>'), 'html'],
            'base'              => [$p('', '<base href="https://evil.test/">'), 'html'],
            'meta refresh'      => [$p('', '<meta http-equiv="refresh" content="0;url=https://evil.test">'), 'html'],
            'meta refresh esp.' => [$p('', '<meta http-equiv = "REFRESH" content="0;url=//evil.test">'), 'html'],
            'meta csp'          => [$p('', '<meta http-equiv="Content-Security-Policy" content="default-src *">'), 'html'],
            'meta set-cookie'   => [$p('', '<meta http-equiv="set-cookie" content="a=b">'), 'html'],
            'link preload ext'  => [$p('', '<link rel="preload" href="https://evil.test/x.js" as="script">'), 'html'],
            'link prefetch'     => [$p('', '<link rel="prefetch" href="https://evil.test/">'), 'html'],
            'link preconnect'   => [$p('', '<link rel="preconnect" href="https://evil.test">'), 'html'],
            'script ext'        => [$p('<script src="https://evil.test/x.js"></script>'), 'html'],
            'script ext //'     => [$p('<script src="//evil.test/x.js"></script>'), 'html'],
            'script http cdn'   => [$p('<script src="http://cdn.jsdelivr.net/x.js"></script>'), 'html'],
            'script host trick' => [$p('<script src="https://cdn.jsdelivr.net.evil.test/x.js"></script>'), 'html'],
            'script userinfo'   => [$p('<script src="https://cdn.jsdelivr.net@evil.test/x.js"></script>'), 'html'],
            'javascript: href'  => [$p('<a href="javascript:alert(1)">x</a>'), 'html'],
            'javascript: mixto' => [$p('<a href="JaVa&#x0A;ScRiPt:alert(1)">x</a>'), 'html'],
            'jav&#x61;script'   => [$p('<a href="jav&#x61;script:alert(1)">x</a>'), 'html'],
            'tab en esquema'    => [$p("<a href=\"java\tscript:alert(1)\">x</a>"), 'html'],
            'vbscript'          => [$p('<a href="vbscript:msgbox(1)">x</a>'), 'html'],
            'data:text/html'    => [$p('<a href="data:text/html,<script>alert(1)</script>">x</a>'), 'html'],
            'data:application'  => [$p('<img src="data:application/x-shockwave-flash;base64,AAAA">'), 'html'],
            'blob:'             => [$p('<a href="blob:https://x.test/1">x</a>'), 'html'],
            'srcdoc'            => [$p('<div srcdoc="<script>1</script>">x</div>'), 'html'],
            'svg script'        => [$p('<svg><script>1</script></svg>'), 'html'],
            'svg onload'        => [$p('<svg onload="alert(1)"></svg>'), 'html'],
            'svg foreignObject' => [$p('<svg><foreignObject><div>x</div></foreignObject></svg>'), 'html'],
            'svg use ext'       => [$p('<svg><use href="https://evil.test/x.svg#a"></use></svg>'), 'html'],
            'svg animate href'  => [$p('<svg><a><set attributeName="href" to="javascript:alert(1)"/></a></svg>'), 'html'],
            'onerror eval'      => [$p('<img src=x onerror="eval(\'1\')">'), 'html'],
            'onclick cookie'    => [$p('<button onclick="document.cookie">x</button>'), 'html'],
            'eval'              => [$p('<script>eval("1")</script>'), 'html'],
            'eval espacios'     => [$p('<script>eval  ("1")</script>'), 'html'],
            'eval escapado'     => [$p('<script>\\u0065val("1")</script>'), 'html'],
            'new Function'      => [$p('<script>new Function("return 1")()</script>'), 'html'],
            'Function()'        => [$p('<script>Function("return 1")()</script>'), 'html'],
            'setTimeout str'    => [$p('<script>setTimeout("x()",10)</script>'), 'html'],
            'setInterval str'   => [$p('<script>setInterval(\'x()\',10)</script>'), 'html'],
            'document.write'    => [$p('<script>document.write("x")</script>'), 'html'],
            'document . cookie' => [$p('<script>var c = document . cookie;</script>'), 'html'],
            'cookie ["..."]'    => [$p('<script>var c = document["cookie"];</script>'), 'html'],
            'comentario en punto' => [$p('<script>var c = document/**/.cookie;</script>'), 'html'],
            'localStorage'      => [$p('<script>localStorage.x=1</script>'), 'html'],
            'sessionStorage'    => [$p('<script>sessionStorage.x=1</script>'), 'html'],
            'indexedDB'         => [$p('<script>indexedDB.open("a")</script>'), 'html'],
            'XMLHttpRequest'    => [$p('<script>new XMLHttpRequest()</script>'), 'html'],
            'fetch'             => [$p('<script>fetch("/profile.php")</script>'), 'html'],
            'WebSocket'         => [$p('<script>new WebSocket("wss://x")</script>'), 'html'],
            'EventSource'       => [$p('<script>new EventSource("/x")</script>'), 'html'],
            'sendBeacon'        => [$p('<script>navigator.sendBeacon("/x")</script>'), 'html'],
            'importScripts'     => [$p('<script>importScripts("x.js")</script>'), 'html'],
            'new Worker'        => [$p('<script>new Worker("x.js")</script>'), 'html'],
            'SharedWorker'      => [$p('<script>new SharedWorker("x.js")</script>'), 'html'],
            'WebAssembly'       => [$p('<script>WebAssembly.instantiate(b)</script>'), 'html'],
            'window.open'       => [$p('<script>window.open("https://x")</script>'), 'html'],
            'open()'            => [$p('<script>open("https://x")</script>'), 'html'],
            'location ='        => [$p('<script>location = "https://x"</script>'), 'html'],
            'location.href'     => [$p('<script>location.href = "https://x"</script>'), 'html'],
            'location.replace'  => [$p('<script>window.location.replace("https://x")</script>'), 'html'],
            'top.'              => [$p('<script>top.location = "x"</script>'), 'html'],
            'parent.'           => [$p('<script>parent.document.body</script>'), 'html'],
            'window.parent'     => [$p('<script>var p = window.parent;</script>'), 'html'],
            'window.top'        => [$p('<script>var p = window . top;</script>'), 'html'],
            'opener'            => [$p('<script>opener.x()</script>'), 'html'],
            'window["x"]'       => [$p('<script>window["ev"+"al"]("1")</script>'), 'html'],
            'globalThis'        => [$p('<script>globalThis.x</script>'), 'html'],
            'postMessage'       => [$p('<script>parent2.postMessage("x","*")</script>'), 'html'],
            'clipboard'         => [$p('<script>navigator.clipboard.readText()</script>'), 'html'],
            'geolocation'       => [$p('<script>navigator.geolocation.getCurrentPosition(f)</script>'), 'html'],
            'mediaDevices'      => [$p('<script>navigator.mediaDevices.getUserMedia({})</script>'), 'html'],
            'serviceWorker'     => [$p('<script>navigator.serviceWorker.register("x")</script>'), 'html'],
            'Notification'      => [$p('<script>Notification.requestPermission()</script>'), 'html'],
            'Function.prototype'=> [$p('<script>Function.prototype.x=1</script>'), 'html'],
            'constructor.ctor'  => [$p('<script>[].constructor.constructor("1")()</script>'), 'html'],
            'constructor[]'     => [$p('<script>x["constructor"]</script>'), 'html'],
            'atob'              => [$p('<script>var a = atob("AAAA")</script>'), 'html'],
            'fromCharCode'      => [$p('<script>String.fromCharCode(101)</script>'), 'html'],
            'ofuscado \\x'      => [$p('<script>var s="' . str_repeat('\\x41', 25) . '"</script>'), 'html'],
            'base64 largo'      => [$p('<script>var s="' . str_repeat('QUJD', 60) . '"</script>'), 'html'],
            'hex largo'         => [$p('<script>var s="' . str_repeat('ab', 45) . '"</script>'), 'html'],
            'import()'          => [$p('<script type="module">import("https://evil.test/x.js")</script>'), 'html'],
            'coinhive'          => [$p('<script>var m = new CoinHive.Anonymous("k");</script>'), 'html'],
            'coinhive src'      => [$p('<p>coinhive</p>'), 'html'],
            'cryptonight'       => [$p('<script>var a = "cryptonight";</script>'), 'html'],
            'stratum'           => [$p('<script>var a = "stratum+tcp://x";</script>'), 'html'],
            'deepMiner'         => [$p('<script>deepMiner.init()</script>'), 'html'],
            'password input'    => [$p('<input type="password" name="p">'), 'html'],
            'password INPUT'    => [$p('<INPUT TYPE=PASSWORD>'), 'html'],
            'phishing texto'    => [$p('<h1>Iniciar sesión</h1><input name="u"><input name="x">'), 'html'],
            'phishing tarjeta'  => [$p('<p>Número de tarjeta y CVV</p><input name="n">'), 'html'],
            'phishing seed'     => [$p('<p>Escribe tu frase semilla</p><textarea></textarea>'), 'html'],
            'comentario cond.'  => [$p('<!--[if IE]><iframe src=x></iframe><![endif]-->'), 'html'],
            'scr<script>ipt'    => [$p('<scr<script>eval(1)</script>ipt>'), 'html'],
            'entidad en on*'    => [$p('<img src=x onerror="&#x65;val(1)">'), 'html'],
            'style expression'  => [$p('<div style="width:expression(alert(1))">x</div>'), 'html'],
            'style tag @import' => [$p('', '<style>@import url("https://evil.test/x.css");</style>'), 'html'],
            'style url(js)'     => [$p('', '<style>a{background:url(javascript:alert(1))}</style>'), 'html'],
            'demasiado grande'  => [str_repeat('a', 524289), 'html'],
            'css expression'    => ['a{width:expression(alert(1))}', 'css'],
            'css escapado'      => ['a{width:\\65 xpression(alert(1))}', 'css'],
            'css behavior'      => ['a{behavior:url(x.htc)}', 'css'],
            'css moz-binding'   => ['a{-moz-binding:url(x.xml#a)}', 'css'],
            'css @import ext'   => ['@import "https://evil.test/x.css";', 'css'],
            'css @import url'   => ['@import url(//evil.test/x.css);', 'css'],
            'css url(js)'       => ['a{background:url("javascript:alert(1)")}', 'css'],
            'css url data html' => ['a{background:url(data:text/html;base64,AAAA)}', 'css'],
            'js eval'           => ['eval("1")', 'js'],
            'js fetch'          => ['fetch("/x")', 'js'],
            'js cookie'         => ['var c = document.cookie', 'js'],
            'svg script'        => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'svg'],
            'svg onload'        => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'svg'],
            'svg foreignObject' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><p>x</p></foreignObject></svg>', 'svg'],
            'svg use externo'   => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil.test/a.svg#x"/></svg>', 'svg'],
            'svg entidad'       => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>', 'svg'],
            'svg href js'       => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>x</text></a></svg>', 'svg'],
            'svg no valido'     => ['no es xml', 'svg'],
        ];
    }

    #[DataProvider('malicious')]
    public function testRechazaMuestrasMaliciosas(string $content, string $ext): void
    {
        $errors = HtmlScanner::scan($content, 'test.' . $ext);
        self::assertNotEmpty($errors, 'Debía rechazarse');
    }

    /** @return array<string,array{string,string}> */
    public static function benign(): array
    {
        $tw = '<script src="https://cdn.tailwindcss.com"></script>';
        return [
            'landing tailwind' => [self::page('<main class="min-h-screen bg-rose-50 p-6"><h1 class="text-4xl font-bold">Te amo, Ana</h1><p>Desde el 14 de febrero.</p><a href="#fotos" class="underline">Ver fotos</a><img src="https://images.example.com/a.jpg" alt="Foto"></main>', $tw), 'html'],
            'fuentes google' => [self::page('<p>Hola</p>', '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">'), 'html'],
            'jsdelivr/cdnjs' => [self::page('<p>x</p>', '<script src="https://cdn.jsdelivr.net/npm/aos@2/dist/aos.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>'), 'html'],
            'js inocuo' => [self::page('<button id="b">Toca</button><p id="c">0</p><script>let n=0;document.getElementById("b").addEventListener("click",()=>{n++;document.getElementById("c").textContent=String(n);});</script>'), 'html'],
            'onclick inocuo' => [self::page('<button onclick="toggle()">Hola</button><script>function toggle(){document.body.classList.toggle("dark")}</script>'), 'html'],
            'carrusel' => [self::page('<div class="slides"><img src="a.jpg" alt=""><img src="b.jpg" alt=""></div><script>const s=document.querySelectorAll(".slides img");let i=0;setInterval(()=>{s[i].style.display="none";i=(i+1)%s.length;s[i].style.display="block";},3000);</script>'), 'html'],
            'imagen data' => [self::page('<img alt="" src="data:image/png;base64,iVBORw0KGgo=">'), 'html'],
            'svg inline seguro' => [self::page('<svg viewBox="0 0 10 10"><path d="M1 1L9 9"/><use href="#a"/></svg>'), 'html'],
            'style con url' => [self::page('<div style="background:url(fondo.jpg)">x</div>', '<style>body{font-family:sans-serif}@media(max-width:600px){h1{font-size:2rem}}</style>'), 'html'],
            'tarjeta sin input' => [self::page('<h1>Tarjeta de felicitación</h1><p>Contraseña del amor: ninguna</p>'), 'html'],
            'script relativo' => [self::page('<script src="js/app.js"></script>'), 'html'],
            'input suelto' => [self::page('<label>Tu nombre <input type="text"></label>'), 'html'],
            'css normal' => ['body{margin:0;background:#fff url(bg.png)}@font-face{font-family:F;src:url(f.woff2)}', 'css'],
            'css import google' => ['@import url("https://fonts.googleapis.com/css2?family=Poppins");a{color:red}', 'css'],
            'js normal' => ['document.querySelectorAll(".a").forEach(function(el){el.classList.add("x")});', 'js'],
            'svg seguro' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#f43f5e"/></svg>', 'svg'],
        ];
    }

    #[DataProvider('benign')]
    public function testAceptaMuestrasBenignas(string $content, string $ext): void
    {
        self::assertSame([], HtmlScanner::scan($content, 'test.' . $ext));
    }

    public function testMensajesNoReflejanContenidoPeligroso(): void
    {
        $errors = HtmlScanner::scan(self::page('<a href="javascript:alert(document.domain)">x</a>'), '<img src=x onerror=alert(1)>.html');
        $joined = implode(' ', $errors);
        self::assertStringNotContainsString('<', $joined);
        self::assertStringNotContainsString('document.domain', $joined);
    }

    public function testNoExpandeEntidadesExternas(): void
    {
        $xxe = '<!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]><html><body>&x;</body></html>';
        self::assertSame([], array_filter(HtmlScanner::scan($xxe, 'a.html'), static fn (string $m): bool => str_contains($m, 'root:')));
    }
}
