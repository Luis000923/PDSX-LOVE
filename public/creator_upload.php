<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/** URL antigua: la subida es ahora un único flujo en upload_html.php (modo público). */
redirect('upload_html.php?modo=publica');
